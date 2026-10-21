<?php
declare(strict_types=1);

namespace MiniERP\Application\Services;

use DateTimeImmutable;
use DateTimeZone;
use DomainException;
use MiniERP\Security\Actor;
use RuntimeException;
use wpdb;

/** Read-only reports projected from posted ERP source documents and immutable ledgers. */
final class ReportService
{
    private const TZ_LOCAL = 'Asia/Ho_Chi_Minh';
    private const EFFECTIVE_PAYMENTS = "'POSTED','VOID_PENDING','VOID_APPROVED'";

    public function __construct(private readonly wpdb $db) {}

    /** @return array{total_vnd:int,invoice_count:int} */
    public function salesRevenue(Actor $actor,string $from,string $to):array
    {
        if(!$actor->can('erp_reports_sales'))throw new DomainException('Bạn không có quyền xem báo cáo doanh thu.');
        [$fromUtc,$toUtc]=$this->utcRange($from,$to);$invoices=$this->table('erp_invoices');
        $rows=$this->rows($this->db->prepare("SELECT COALESCE(SUM(total_vnd),0) total_vnd,COUNT(*) invoice_count FROM {$invoices} WHERE source_type='SHIPMENT' AND direction='IN' AND status='ISSUED' AND issued_at >= %s AND issued_at < %s",[$fromUtc,$toUtc]));
        $row=$rows[0]??[];return ['total_vnd'=>(int)($row['total_vnd']??0),'invoice_count'=>(int)($row['invoice_count']??0)];
    }

    /**
     * @return array{buckets:array<string,array{invoice_count:int,outstanding_vnd:int}>,rows:list<array<string,mixed>>,total_open:int,page_count:int,page:int}
     */
    public function receivables(Actor $actor,string $asOfLocalDate,int $page=1):array
    {
        if(!$actor->can('erp_reports_receivables'))throw new DomainException('Bạn không có quyền xem báo cáo công nợ.');
        $this->assertDate($asOfLocalDate);$page=max(1,min(10000,$page));$limit=50;
        $invoices=$this->table('erp_invoices');$payments=$this->table('erp_payments');$baseArgs=[];$scope='';
        if(!$actor->can('erp_invoice_sources_view_all')){
            if(!$actor->can('erp_invoice_sources_view_own'))throw new DomainException('Bạn không có quyền xem báo cáo công nợ.');
            $shipments=$this->table('erp_shipments');$orders=$this->table('erp_sales_orders');
            $scope=" AND i.source_type='SHIPMENT' AND EXISTS(SELECT 1 FROM {$shipments} sh JOIN {$orders} so ON so.id=sh.sales_order_id WHERE sh.id=i.source_id AND so.created_by=%d)";$baseArgs[]=$actor->id;
        }
        $base="SELECT i.id,i.invoice_no,i.source_type,i.source_id,i.buyer_name,i.issued_at,i.due_at,i.total_vnd,COALESCE(p.effective_paid_vnd,0) effective_paid_vnd,GREATEST(i.total_vnd-COALESCE(p.effective_paid_vnd,0),0) outstanding_vnd FROM {$invoices} i LEFT JOIN (SELECT invoice_id,SUM(amount_vnd) effective_paid_vnd FROM {$payments} WHERE status IN (".self::EFFECTIVE_PAYMENTS.") GROUP BY invoice_id) p ON p.invoice_id=i.id WHERE i.direction='IN' AND i.status='ISSUED'{$scope}";
        $dueLocal="DATE(DATE_ADD(v.due_at,INTERVAL 7 HOUR))";
        $bucket="CASE WHEN {$dueLocal}>=%s THEN 'not_due' WHEN DATEDIFF(%s,{$dueLocal})<=30 THEN 'days_1_30' WHEN DATEDIFF(%s,{$dueLocal})<=60 THEN 'days_31_60' WHEN DATEDIFF(%s,{$dueLocal})<=90 THEN 'days_61_90' ELSE 'days_over_90' END";
        $summarySql="SELECT {$bucket} aging_bucket,COUNT(*) invoice_count,COALESCE(SUM(v.outstanding_vnd),0) outstanding_vnd FROM ({$base}) v WHERE v.outstanding_vnd>0 GROUP BY aging_bucket";
        $summaryArgs=[$asOfLocalDate,$asOfLocalDate,$asOfLocalDate,$asOfLocalDate,...$baseArgs];
        $summary=$this->rows($this->db->prepare($summarySql,$summaryArgs));$buckets=[];$totalOpen=0;
        foreach($summary as $row){$key=(string)$row['aging_bucket'];$buckets[$key]=['invoice_count'=>(int)$row['invoice_count'],'outstanding_vnd'=>(int)$row['outstanding_vnd']];$totalOpen+=(int)$row['invoice_count'];}
        $rowSql="SELECT v.id,v.invoice_no,v.source_type,v.source_id,v.buyer_name,v.issued_at,v.due_at,DATE(DATE_ADD(v.due_at,INTERVAL 7 HOUR)) due_date,v.total_vnd,v.effective_paid_vnd,v.outstanding_vnd,GREATEST(DATEDIFF(%s,DATE(DATE_ADD(v.due_at,INTERVAL 7 HOUR))),0) days_overdue FROM ({$base}) v WHERE v.outstanding_vnd>0 ORDER BY days_overdue DESC,v.due_at,v.id LIMIT %d OFFSET %d";
        $pageCount=max(1,(int)ceil($totalOpen/$limit));$page=min($page,$pageCount);$offset=($page-1)*$limit;
        $rows=$this->rows($this->db->prepare($rowSql,[$asOfLocalDate,...$baseArgs,$limit,$offset]));
        return ['buckets'=>$buckets,'rows'=>$rows,'total_open'=>$totalOpen,'page_count'=>$pageCount,'page'=>$page];
    }

    /**
     * @return array{in_vnd:int,out_vnd:int,accounts:list<array<string,mixed>>,movements:list<array<string,mixed>>,movement_count:int,page:int,page_count:int}
     */
    public function cashBook(Actor $actor,string $from,string $to,int $page=1):array
    {
        if(!$actor->can('erp_reports_finance'))throw new DomainException('Bạn không có quyền xem báo cáo dòng tiền.');
        [$fromUtc,$toUtc]=$this->utcRange($from,$to);$page=max(1,min(10000,$page));$limit=50;
        if($actor->cashAccountIds===[])return ['in_vnd'=>0,'out_vnd'=>0,'accounts'=>[],'movements'=>[],'movement_count'=>0,'page'=>$page,'page_count'=>1];
        $slots=implode(',',array_fill(0,count($actor->cashAccountIds),'%d'));$accounts=$this->table('erp_cash_accounts');$movements=$this->table('erp_cash_movements');
        $accountRows=$this->rows($this->db->prepare("SELECT a.id,a.account_code,a.name,a.type,a.status,a.opening_balance_vnd,COALESCE(SUM(CASE WHEN m.direction='IN' THEN m.amount_vnd ELSE -m.amount_vnd END),0) movement_balance_vnd FROM {$accounts} a LEFT JOIN {$movements} m ON m.cash_account_id=a.id WHERE a.id IN ({$slots}) AND a.status='ACTIVE' GROUP BY a.id,a.account_code,a.name,a.type,a.status,a.opening_balance_vnd ORDER BY a.account_code",$actor->cashAccountIds));
        $periodRows=$this->rows($this->db->prepare("SELECT COALESCE(SUM(CASE WHEN direction='IN' THEN amount_vnd ELSE 0 END),0) in_vnd,COALESCE(SUM(CASE WHEN direction='OUT' THEN amount_vnd ELSE 0 END),0) out_vnd,COUNT(*) movement_count FROM {$movements} WHERE cash_account_id IN ({$slots}) AND occurred_at >= %s AND occurred_at < %s",[...$actor->cashAccountIds,$fromUtc,$toUtc]));
        $row=$periodRows[0]??[];$count=(int)($row['movement_count']??0);$pageCount=max(1,(int)ceil($count/$limit));$page=min($page,$pageCount);$offset=($page-1)*$limit;
        $movementRows=$this->rows($this->db->prepare("SELECT m.id,m.direction,m.amount_vnd,m.event_key,m.source_type,m.source_id,m.occurred_at,m.external_reference,a.account_code,a.name account_name FROM {$movements} m JOIN {$accounts} a ON a.id=m.cash_account_id WHERE m.cash_account_id IN ({$slots}) AND m.occurred_at >= %s AND m.occurred_at < %s ORDER BY m.occurred_at DESC,m.id DESC LIMIT %d OFFSET %d",[...$actor->cashAccountIds,$fromUtc,$toUtc,$limit,$offset]));
        $accountViews=[];foreach($accountRows as $account){$account['balance_vnd']=(int)$account['opening_balance_vnd']+(int)$account['movement_balance_vnd'];$accountViews[]=$account;}
        return ['in_vnd'=>(int)($row['in_vnd']??0),'out_vnd'=>(int)($row['out_vnd']??0),'accounts'=>$accountViews,'movements'=>$movementRows,'movement_count'=>$count,'page'=>$page,'page_count'=>$pageCount];
    }

    /** @return array{rows:list<array<string,mixed>>,total_rows:int,product_count:int,page:int,page_count:int} */
    public function stockOnHand(Actor $actor,int $page=1):array
    {
        if(!$actor->can('erp_reports_stock')&&!$actor->can('erp_stock_view'))throw new DomainException('Bạn không có quyền xem tồn kho.');
        $allWarehouses=$actor->can('erp_stock_adjust_approve');
        if(!$allWarehouses&&$actor->warehouseIds===[])return ['rows'=>[],'total_rows'=>0,'product_count'=>0,'page'=>1,'page_count'=>1];
        $page=max(1,min(10000,$page));$limit=50;$scope='';$scopeArgs=[];
        if(!$allWarehouses){$slots=implode(',',array_fill(0,count($actor->warehouseIds),'%d'));$scope=" WHERE b.warehouse_id IN ({$slots})";$scopeArgs=$actor->warehouseIds;}
        $balances=$this->table('erp_stock_balances');$products=$this->table('erp_products');$warehouses=$this->table('erp_warehouses');
        $countSql="SELECT COUNT(*) total_rows,COUNT(DISTINCT b.product_id) product_count FROM {$balances} b JOIN {$products} p ON p.id=b.product_id JOIN {$warehouses} w ON w.id=b.warehouse_id{$scope}";
        $countRows=$this->rows($this->db->prepare($countSql,$scopeArgs));$aggregate=$countRows[0]??[];$total=(int)($aggregate['total_rows']??0);
        $pageCount=max(1,(int)ceil($total/$limit));$page=min($page,$pageCount);$offset=($page-1)*$limit;
        $rowSql="SELECT b.warehouse_id,w.code warehouse_code,w.name warehouse_name,b.product_id,p.sku,p.name product_name,p.unit,b.quantity,b.updated_at FROM {$balances} b JOIN {$products} p ON p.id=b.product_id JOIN {$warehouses} w ON w.id=b.warehouse_id{$scope} ORDER BY w.code,p.sku LIMIT %d OFFSET %d";
        $rows=$this->rows($this->db->prepare($rowSql,[...$scopeArgs,$limit,$offset]));
        return ['rows'=>$rows,'total_rows'=>$total,'product_count'=>(int)($aggregate['product_count']??0),'page'=>$page,'page_count'=>$pageCount];
    }

    /** @return array{0:string,1:string} */
    private function utcRange(string $from,string $to):array
    {
        $this->assertDate($from);$this->assertDate($to);if($to<$from)throw new DomainException('Khoảng ngày báo cáo không hợp lệ.');
        $local=new DateTimeZone(self::TZ_LOCAL);$utc=new DateTimeZone('UTC');
        $start=(new DateTimeImmutable($from.' 00:00:00',$local))->setTimezone($utc)->format('Y-m-d H:i:s.u');
        $end=(new DateTimeImmutable($to.' 00:00:00',$local))->modify('+1 day')->setTimezone($utc)->format('Y-m-d H:i:s.u');return [$start,$end];
    }

    private function assertDate(string $date):void
    {
        $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date,new DateTimeZone(self::TZ_LOCAL));$errors=DateTimeImmutable::getLastErrors();
        if($parsed===false||($errors!==false&&($errors['warning_count']>0||$errors['error_count']>0))||$parsed->format('Y-m-d')!==$date)throw new DomainException('Ngày báo cáo không hợp lệ.');
    }

    private function table(string $suffix):string
    {
        $allowed=['erp_invoices','erp_payments','erp_shipments','erp_sales_orders','erp_cash_accounts','erp_cash_movements','erp_stock_balances','erp_products','erp_warehouses'];
        if(!preg_match('/\A[A-Za-z0-9_]{1,32}\z/D',$this->db->prefix)||!in_array($suffix,$allowed,true))throw new DomainException('Cấu hình bảng dữ liệu không hợp lệ.');return '`'.$this->db->prefix.$suffix.'`';
    }

    /** @return list<array<string,mixed>> */
    private function rows(string $sql):array
    {
        $rows=$this->db->get_results($sql,ARRAY_A);if($this->db->last_error!=='')throw new RuntimeException('A report query failed.');return is_array($rows)?$rows:[];
    }
}
