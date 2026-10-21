<?php
declare(strict_types=1);

namespace MiniERP\Application\Services;

use DomainException;
use MiniERP\Infrastructure\Audit\AuditLogger;
use MiniERP\Infrastructure\Database\TransactionManager;
use MiniERP\Security\Actor;
use DateTimeImmutable;
use DateTimeZone;
use wpdb;

/** Warehouse count snapshots and maker-checker stock adjustments. */
final class InventoryAdjustmentService
{
    public function __construct(private readonly wpdb $db, private readonly TransactionManager $transactions, private readonly AuditLogger $audit) {}

    /** Start a physical count from a locked snapshot of every active or currently stocked product. */
    public function startDraft(Actor $actor, int $warehouseId, string $reason): int
    {
        if (!$actor->can('erp_stock_adjust_request') || $warehouseId < 1 || !in_array($warehouseId, $actor->warehouseIds, true)
            || trim($reason) === '' || $this->length($reason) > 1000) {
            throw new DomainException('Thông tin kiểm kê không hợp lệ hoặc bạn không được thao tác tại kho này.');
        }
        $adjustments=$this->table('erp_stock_adjustments');$items=$this->table('erp_stock_adjustment_items');
        $warehouses=$this->table('erp_warehouses');$products=$this->table('erp_products');$balances=$this->table('erp_stock_balances');
        return $this->transactions->run(function(TransactionManager $tx)use($actor,$warehouseId,$reason,$adjustments,$items,$warehouses,$products,$balances):int{
            if($tx->fetchAll("SELECT id FROM {$warehouses} WHERE id=%d AND is_active=1 FOR UPDATE",[$warehouseId])===[])throw new DomainException('Kho không còn hoạt động.');
            $productRows=$tx->fetchAll("SELECT p.id,p.sku,p.name,p.unit,p.unit_step FROM {$products} p LEFT JOIN {$balances} b ON b.product_id=p.id AND b.warehouse_id=%d WHERE p.is_active=1 OR b.quantity>0 ORDER BY p.id FOR UPDATE",[$warehouseId]);
            if($productRows===[])throw new DomainException('Chưa có sản phẩm để kiểm kê.');
            $now=$this->now();$tmp='TMP-'.strtoupper(str_replace('-','',$this->uuid()));
            $tx->execute("INSERT INTO {$adjustments}(adjustment_no,warehouse_id,status,requested_by,reason,created_at,updated_at) VALUES(%s,%d,'DRAFT',%d,%s,%s,%s)",[$tmp,$warehouseId,$actor->id,trim($reason),$now,$now]);
            $id=$tx->insertId();$number='IA-'.gmdate('Y').'-'.str_pad((string)$id,8,'0',STR_PAD_LEFT);$tx->execute("UPDATE {$adjustments} SET adjustment_no=%s WHERE id=%d",[$number,$id]);
            foreach($productRows as $product){$productId=(int)$product['id'];$step=(string)$product['unit_step'];$tx->execute("INSERT INTO {$balances}(warehouse_id,product_id,quantity,updated_at) VALUES(%d,%d,0,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE updated_at=updated_at",[$warehouseId,$productId]);$stock=$tx->fetchAll("SELECT quantity FROM {$balances} WHERE warehouse_id=%d AND product_id=%d FOR UPDATE",[$warehouseId,$productId]);if($stock===[])throw new DomainException('Không thể khóa số dư kho.');$expected=(string)$stock[0]['quantity'];
                $tx->execute("INSERT INTO {$items}(adjustment_id,product_id,sku_snapshot,name_snapshot,unit_snapshot,unit_step_snapshot,expected_qty,counted_qty,delta_qty) VALUES(%d,%d,%s,%s,%s,%s,%s,%s,'0.000')",[$id,$productId,(string)$product['sku'],(string)$product['name'],(string)$product['unit'],$step,$expected,$expected]);}
            $this->audit->record($tx,$this->uuid(),$actor->id,'stock.adjustment.created','stock_adjustment',$id,'success',[],['status'=>'DRAFT']);return $id;
        });
    }

    /** Save physical counts to a draft; optionally submit the same transaction for approval. @param array<int,string> $counts */
    public function saveDraft(Actor $actor,int $adjustmentId,array $counts,bool $submit=false):string
    {
        if($adjustmentId<1||!$actor->can('erp_stock_adjust_request')||!array_is_list($counts))throw new DomainException('Thông tin kiểm kê không hợp lệ.');
        $adjustments=$this->table('erp_stock_adjustments');$items=$this->table('erp_stock_adjustment_items');
        return $this->transactions->run(function(TransactionManager $tx)use($actor,$adjustmentId,$counts,$submit,$adjustments,$items):string{
            $rows=$tx->fetchAll("SELECT id,warehouse_id,status,requested_by FROM {$adjustments} WHERE id=%d FOR UPDATE",[$adjustmentId]);$adjustment=$rows[0]??null;
            if(!is_array($adjustment)||(int)$adjustment['requested_by']!==$actor->id||(string)$adjustment['status']!=='DRAFT'||!in_array((int)$adjustment['warehouse_id'],$actor->warehouseIds,true))throw new DomainException('Bản kiểm kê không còn là bản nháp của bạn.');
            $snapshot=$tx->fetchAll("SELECT product_id,unit_step_snapshot,expected_qty FROM {$items} WHERE adjustment_id=%d ORDER BY product_id FOR UPDATE",[$adjustmentId]);if($snapshot===[])throw new DomainException('Bản kiểm kê không có dòng hàng.');
            $provided=[];foreach($counts as $line){if(!is_array($line)||!isset($line['product_id'],$line['counted_qty'])||!is_int($line['product_id'])||!is_string($line['counted_qty'])||isset($provided[$line['product_id']]))throw new DomainException('Dòng kiểm kê không hợp lệ hoặc bị trùng.');$provided[$line['product_id']]=$line['counted_qty'];}
            $byId=[];foreach($snapshot as $line)$byId[(int)$line['product_id']]=$line;if(count($provided)!==count($byId)||array_diff_key($provided,$byId)!==[])throw new DomainException('Cần lưu số đếm cho đúng các mặt hàng trong bản kiểm kê.');
            $changed=false;foreach($snapshot as $line){$productId=(int)$line['product_id'];$counted=$this->countedQuantity($provided[$productId],(string)$line['unit_step_snapshot']);$delta=bcsub($counted,(string)$line['expected_qty'],3);if(bccomp($delta,'0.000',3)!==0)$changed=true;$tx->execute("UPDATE {$items} SET counted_qty=%s,delta_qty=%s WHERE adjustment_id=%d AND product_id=%d",[$counted,$delta,$adjustmentId,$productId]);}
            if($submit&&!$changed)throw new DomainException('Không có chênh lệch nào để gửi duyệt.');
            if($submit){$now=$this->now();if($tx->execute("UPDATE {$adjustments} SET status='REQUESTED',requested_at=%s,version=version+1,updated_at=%s WHERE id=%d AND status='DRAFT'",[$now,$now,$adjustmentId])!==1)throw new DomainException('Bản kiểm kê đã thay đổi.');$this->audit->record($tx,$this->uuid(),$actor->id,'stock.adjustment.requested','stock_adjustment',$adjustmentId,'success',['status'=>'DRAFT'],['status'=>'REQUESTED']);}
            else $this->audit->record($tx,$this->uuid(),$actor->id,'stock.adjustment.count_saved','stock_adjustment',$adjustmentId,'success');
            return $submit?'REQUESTED':'DRAFT';
        });
    }

    public function request(Actor $actor,int $adjustmentId):void
    {
        if($adjustmentId<1||!$actor->can('erp_stock_adjust_request'))throw new DomainException('Bạn không có quyền gửi kiểm kê.');
        $adjustments=$this->table('erp_stock_adjustments');$items=$this->table('erp_stock_adjustment_items');
        $this->transactions->run(function(TransactionManager $tx)use($actor,$adjustmentId,$adjustments,$items):void{$rows=$tx->fetchAll("SELECT warehouse_id,status,requested_by FROM {$adjustments} WHERE id=%d FOR UPDATE",[$adjustmentId]);$row=$rows[0]??null;if(!is_array($row)||(int)$row['requested_by']!==$actor->id||(string)$row['status']!=='DRAFT'||!in_array((int)$row['warehouse_id'],$actor->warehouseIds,true))throw new DomainException('Bản kiểm kê không còn là bản nháp của bạn.');$lines=$tx->fetchAll("SELECT delta_qty FROM {$items} WHERE adjustment_id=%d",[$adjustmentId]);$changed=false;foreach($lines as $line)if(bccomp((string)$line['delta_qty'],'0.000',3)!==0)$changed=true;if(!$changed)throw new DomainException('Không có chênh lệch nào để gửi duyệt.');$now=$this->now();$tx->execute("UPDATE {$adjustments} SET status='REQUESTED',requested_at=%s,version=version+1,updated_at=%s WHERE id=%d",[$now,$now,$adjustmentId]);$this->audit->record($tx,$this->uuid(),$actor->id,'stock.adjustment.requested','stock_adjustment',$adjustmentId,'success',['status'=>'DRAFT'],['status'=>'REQUESTED']);});
    }

    public function decide(Actor $actor,int $adjustmentId,string $decision):string
    {
        if($adjustmentId<1||!$actor->can('erp_stock_adjust_approve')||!in_array($decision,['approve','reject'],true))throw new DomainException('Quyết định kiểm kê không hợp lệ.');
        $adjustments=$this->table('erp_stock_adjustments');
        return $this->transactions->run(function(TransactionManager $tx)use($actor,$adjustmentId,$decision,$adjustments):string{$rows=$tx->fetchAll("SELECT requested_by,status FROM {$adjustments} WHERE id=%d FOR UPDATE",[$adjustmentId]);$row=$rows[0]??null;if(!is_array($row)||(string)$row['status']!=='REQUESTED')throw new DomainException('Bản kiểm kê không ở trạng thái chờ duyệt.');if((int)$row['requested_by']===$actor->id)throw new DomainException('Người duyệt phải khác người yêu cầu.');$next=$decision==='approve'?'APPROVED':'REJECTED';$now=$this->now();$tx->execute("UPDATE {$adjustments} SET status=%s,approved_by=%d,approved_at=%s,version=version+1,updated_at=%s WHERE id=%d AND status='REQUESTED'",[$next,$actor->id,$now,$now,$adjustmentId]);$this->audit->record($tx,$this->uuid(),$actor->id,'stock.adjustment.'.$decision,'stock_adjustment',$adjustmentId,'success',['status'=>'REQUESTED'],['status'=>$next]);return $next;});
    }

    /** @return array{id:int,status:string} */
    public function apply(Actor $actor,int $adjustmentId,string $requestKey):array
    {
        if($adjustmentId<1||!$actor->can('erp_stock_adjust_apply')||!$this->validUuid($requestKey))throw new DomainException('Yêu cầu áp dụng kiểm kê không hợp lệ.');
        $adjustments=$this->table('erp_stock_adjustments');$items=$this->table('erp_stock_adjustment_items');$balances=$this->table('erp_stock_balances');$movements=$this->table('erp_stock_movements');
        return $this->transactions->run(function(TransactionManager $tx)use($actor,$adjustmentId,$requestKey,$adjustments,$items,$balances,$movements):array{$rows=$tx->fetchAll("SELECT warehouse_id,status,approved_by,apply_request_key FROM {$adjustments} WHERE id=%d FOR UPDATE",[$adjustmentId]);$adjustment=$rows[0]??null;if(!is_array($adjustment)||(string)$adjustment['status']==='REJECTED'||!in_array((int)$adjustment['warehouse_id'],$actor->warehouseIds,true))throw new DomainException('Không tìm thấy bản kiểm kê được phép áp dụng.');if((string)$adjustment['status']==='APPLIED'){if(hash_equals((string)$adjustment['apply_request_key'],$requestKey))return ['id'=>$adjustmentId,'status'=>'APPLIED'];throw new DomainException('Bản kiểm kê đã được áp dụng bằng yêu cầu khác.');}if((string)$adjustment['status']!=='APPROVED')throw new DomainException('Bản kiểm kê chưa được duyệt hoặc cần kiểm kê lại.');if((int)$adjustment['approved_by']===$actor->id)throw new DomainException('Người thực hiện phải khác người duyệt.');
            $lines=$tx->fetchAll("SELECT product_id,unit_step_snapshot,expected_qty,counted_qty,delta_qty FROM {$items} WHERE adjustment_id=%d ORDER BY product_id FOR UPDATE",[$adjustmentId]);if($lines===[])throw new DomainException('Bản kiểm kê không có dòng hàng.');$warehouseId=(int)$adjustment['warehouse_id'];$current=[];$stale=false;
            foreach($lines as $line){$productId=(int)$line['product_id'];$tx->execute("INSERT INTO {$balances}(warehouse_id,product_id,quantity,updated_at) VALUES(%d,%d,0,UTC_TIMESTAMP(6)) ON DUPLICATE KEY UPDATE updated_at=updated_at",[$warehouseId,$productId]);$stock=$tx->fetchAll("SELECT quantity FROM {$balances} WHERE warehouse_id=%d AND product_id=%d FOR UPDATE",[$warehouseId,$productId]);if($stock===[])throw new DomainException('Không thể khóa số dư kho.');$quantity=(string)$stock[0]['quantity'];$current[$productId]=$quantity;if(bccomp($quantity,(string)$line['expected_qty'],3)!==0)$stale=true;}
            $now=$this->now();if($stale){$tx->execute("UPDATE {$adjustments} SET status='STALE',version=version+1,updated_at=%s WHERE id=%d AND status='APPROVED'",[$now,$adjustmentId]);$this->audit->record($tx,$this->uuid(),$actor->id,'stock.adjustment.stale','stock_adjustment',$adjustmentId,'success',['status'=>'APPROVED'],['status'=>'STALE']);return ['id'=>$adjustmentId,'status'=>'STALE'];}
            foreach($lines as $line){$productId=(int)$line['product_id'];$delta=(string)$line['delta_qty'];if(bccomp($delta,'0.000',3)===0)continue;$direction=bccomp($delta,'0.000',3)>0?'IN':'OUT';if($direction==='IN'&&$tx->fetchAll("SELECT id FROM {$movements} WHERE warehouse_id=%d AND product_id=%d LIMIT 1",[$warehouseId,$productId])===[])throw new DomainException('Không hỗ trợ nhập số dư đầu kỳ qua kiểm kê; hãy tạo đơn mua và phiếu nhận hàng.');$before=$current[$productId];$after=(string)$line['counted_qty'];$qty=ltrim($delta,'-');$tx->execute("UPDATE {$balances} SET quantity=%s,updated_at=%s WHERE warehouse_id=%d AND product_id=%d",[$after,$now,$warehouseId,$productId]);$tx->execute("INSERT INTO {$movements}(warehouse_id,product_id,movement_key,direction,qty,before_qty,after_qty,source_type,source_id,actor_id,occurred_at) VALUES(%d,%d,%s,%s,%s,%s,%s,'STOCK_ADJUSTMENT',%d,%d,%s)",[$warehouseId,$productId,'ADJUSTMENT:'.$adjustmentId.':'.$productId,$direction,$qty,$before,$after,$adjustmentId,$actor->id,$now]);}
            $tx->execute("UPDATE {$adjustments} SET status='APPLIED',applied_by=%d,applied_at=%s,apply_request_key=%s,version=version+1,updated_at=%s WHERE id=%d AND status='APPROVED'",[$actor->id,$now,$requestKey,$now,$adjustmentId]);$this->audit->record($tx,$this->uuid(),$actor->id,'stock.adjustment.applied','stock_adjustment',$adjustmentId,'success',['status'=>'APPROVED'],['status'=>'APPLIED']);return ['id'=>$adjustmentId,'status'=>'APPLIED'];});
    }

    private function countedQuantity(string $value,string $step):string
    {
        if(!preg_match('/\A(?:0|[0-9]{1,15})(?:\.[0-9]{1,3})?\z/D',$value))throw new DomainException('Số đếm phải không âm và có tối đa ba chữ số thập phân.');$value=bcadd($value,'0',3);if(bccomp($value,'999999999999999.999',3)>0||bccomp(bcmod($value,$step,3),'0.000',3)!==0)throw new DomainException('Số đếm vượt giới hạn hoặc không đúng bước đơn vị.');return $value;
    }
    private function table(string $suffix):string{if(!preg_match('/\A[A-Za-z0-9_]{1,32}\z/D',$this->db->prefix)||!in_array($suffix,['erp_stock_adjustments','erp_stock_adjustment_items','erp_stock_balances','erp_stock_movements','erp_warehouses','erp_products'],true))throw new DomainException('Cấu hình bảng Mini ERP không hợp lệ.');return '`'.$this->db->prefix.$suffix.'`';}
    private function now():string{return(new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');}
    private function uuid():string{if(function_exists('wp_generate_uuid4'))return wp_generate_uuid4();$bytes=random_bytes(16);$bytes[6]=chr((ord($bytes[6])&0x0f)|0x40);$bytes[8]=chr((ord($bytes[8])&0x3f)|0x80);return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($bytes),4));}
    private function validUuid(string $value):bool{return(bool)preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/iD',$value);}
    private function length(string $value):int{return function_exists('mb_strlen')?mb_strlen($value):strlen($value);}
}
