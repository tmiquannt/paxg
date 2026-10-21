<?php
declare(strict_types=1);

namespace MiniERP\Application\Services;

use DomainException;
use MiniERP\Domain\BusinessConflictException;
use MiniERP\Domain\ExactAmounts;
use MiniERP\Domain\RecordNotFoundException;
use MiniERP\Infrastructure\Audit\AuditLogger;
use MiniERP\Infrastructure\Database\DatabaseException;
use MiniERP\Infrastructure\Database\TransactionManager;
use MiniERP\Security\Actor;
use DateTimeImmutable;
use DateTimeZone;
use wpdb;

/** Posts partial/full invoice payments together with their immutable cash movement. */
final class PaymentService
{
    public function __construct(private readonly wpdb $db,private readonly TransactionManager $transactions,private readonly AuditLogger $audit){}

    public function createCashAccount(Actor $actor,string $code,string $name,string $type,string $openingBalance):int
    {
        if(!$actor->can('erp_cash_accounts_manage'))throw new DomainException('Bạn không có quyền tạo quỹ tiền mặt hoặc tài khoản ngân hàng.');
        $code=strtoupper(trim($code));$name=trim($name);$type=strtoupper($type);$opening=ExactAmounts::money($openingBalance);
        if(!preg_match('/\A[A-Z0-9][A-Z0-9_-]{1,63}\z/D',$code)||$name===''||mb_strlen($name)>191||!in_array($type,['CASH','BANK'],true))throw new \InvalidArgumentException('Thông tin quỹ tiền mặt hoặc tài khoản ngân hàng không hợp lệ.');
        $table=$this->table('erp_cash_accounts');
        return $this->transactions->run(function(TransactionManager $tx)use($actor,$code,$name,$type,$opening,$table):int{
            $now=$this->now();$tx->execute("INSERT INTO {$table} (account_code,name,type,currency,opening_balance_vnd,status,created_by,created_at) VALUES (%s,%s,%s,'VND',%s,'DRAFT',%d,%s)",[$code,$name,$type,$opening,$actor->id,$now]);$id=$tx->insertId();
            $this->audit->record($tx,$this->uuid(),$actor->id,'cash_account.created','cash_account',$id,'success',[],['status'=>'DRAFT','amount_vnd'=>$opening]);return $id;
        });
    }

    public function activateCashAccount(Actor $actor,int $accountId):void
    {
        if(!$actor->can('erp_cash_accounts_activate'))throw new DomainException('Bạn không có quyền kích hoạt tài khoản.');if($accountId<1)throw new \InvalidArgumentException('Tài khoản không hợp lệ.');if(!in_array($accountId,$actor->cashAccountIds,true))throw new RecordNotFoundException('Không tìm thấy tài khoản hoặc bạn không có quyền truy cập.');$table=$this->table('erp_cash_accounts');
        $this->transactions->run(function(TransactionManager $tx)use($actor,$accountId,$table):void{$rows=$tx->fetchAll("SELECT status,created_by,opening_balance_vnd FROM {$table} WHERE id=%d FOR UPDATE",[$accountId]);$row=$rows[0]??null;if(!is_array($row))throw new RecordNotFoundException('Không tìm thấy tài khoản hoặc bạn không có quyền truy cập.');if($row['status']!=='DRAFT'||(int)$row['created_by']===$actor->id)throw new BusinessConflictException('Tài khoản không còn chờ duyệt hoặc người tạo phải được duyệt bởi người khác.');$now=$this->now();$tx->execute("UPDATE {$table} SET status='ACTIVE',activated_by=%d,activated_at=%s WHERE id=%d AND status='DRAFT'",[$actor->id,$now,$accountId]);$this->audit->record($tx,$this->uuid(),$actor->id,'cash_account.activated','cash_account',$accountId,'success',['status'=>'DRAFT'],['status'=>'ACTIVE','amount_vnd'=>(string)$row['opening_balance_vnd']]);});
    }

    public function post(Actor $actor,int $invoiceId,int $accountId,string $amount,string $method,string $externalReference,string $paidAt,string $requestKey):int
    {
        if(!$actor->can('erp_payments_record'))throw new DomainException('Bạn không có quyền ghi khoản thu/chi.');if($invoiceId<1||$accountId<1)throw new \InvalidArgumentException('Hóa đơn hoặc tài khoản tiền không hợp lệ.');if(!in_array($accountId,$actor->cashAccountIds,true))throw new RecordNotFoundException('Không tìm thấy tài khoản tiền hoặc bạn không có quyền truy cập.');
        $amount=ExactAmounts::money($amount);if($amount==='0')throw new \InvalidArgumentException('Số tiền thanh toán phải lớn hơn 0.');
        $method=strtoupper(trim($method));$externalReference=trim($externalReference);
        if(!in_array($method,['CASH','BANK_TRANSFER','CARD','OTHER'],true)||mb_strlen($externalReference)>128||!preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/iD',$requestKey))throw new \InvalidArgumentException('Thông tin thanh toán không hợp lệ.');
        $paidAtUtc=$this->paidAt($paidAt);$invoices=$this->table('erp_invoices');$payments=$this->table('erp_payments');$accounts=$this->table('erp_cash_accounts');$movements=$this->table('erp_cash_movements');$reconciliations=$this->table('erp_reconciliations');
        return $this->transactions->run(function(TransactionManager $tx)use($actor,$invoiceId,$accountId,$amount,$method,$externalReference,$paidAtUtc,$requestKey,$invoices,$payments,$accounts,$movements,$reconciliations):int{
            $invoiceRows=$tx->fetchAll("SELECT direction,total_vnd,status FROM {$invoices} WHERE id=%d FOR UPDATE",[$invoiceId]);$invoice=$invoiceRows[0]??null;if(!is_array($invoice))throw new RecordNotFoundException('Không tìm thấy hóa đơn hoặc bạn không có quyền truy cập.');if($invoice['status']!=='ISSUED')throw new BusinessConflictException('Hóa đơn chưa ở trạng thái có thể thanh toán.');
            $accountRows=$tx->fetchAll("SELECT status FROM {$accounts} WHERE id=%d FOR UPDATE",[$accountId]);$account=$accountRows[0]??null;if(!is_array($account))throw new RecordNotFoundException('Không tìm thấy tài khoản tiền hoặc bạn không có quyền truy cập.');if($account['status']!=='ACTIVE')throw new BusinessConflictException('Tài khoản tiền chưa được kích hoạt.');
            $this->assertCashDateNotInClosedPeriod($tx,$accountId,$paidAtUtc,$reconciliations);
            $paidRows=$tx->fetchAll("SELECT COALESCE(SUM(amount_vnd),0) AS paid FROM {$payments} WHERE invoice_id=%d AND status IN ('POSTED','VOID_PENDING','VOID_APPROVED')",[$invoiceId]);$paid=(string)($paidRows[0]['paid']??'0');$outstanding=bcsub((string)$invoice['total_vnd'],$paid,0);
            if(bccomp($amount,$outstanding,0)>0)throw new BusinessConflictException('Số tiền vượt quá dư nợ còn lại.');
            $direction=(string)$invoice['direction'];
            if($direction==='OUT'){$balance=$tx->fetchAll("SELECT CAST((SELECT opening_balance_vnd FROM {$accounts} WHERE id=%d)+(SELECT COALESCE(SUM(CASE WHEN direction='IN' THEN amount_vnd ELSE -amount_vnd END),0) FROM {$movements} WHERE cash_account_id=%d) AS CHAR) AS balance",[$accountId,$accountId]);if(bccomp((string)($balance[0]['balance']??'0'),$amount,0)<0)throw new BusinessConflictException('Số dư quỹ tiền mặt hoặc tài khoản ngân hàng không đủ.');}
            $now=$this->now();$tmp='TMP-'.strtoupper(str_replace('-','',$requestKey));$tx->execute("INSERT INTO {$payments} (payment_no,invoice_id,direction,amount_vnd,cash_account_id,method,external_reference,paid_at,posted_at,status,created_by,request_key) VALUES (%s,%d,%s,%s,%d,%s,%s,%s,%s,'POSTED',%d,%s)",[$tmp,$invoiceId,$direction,$amount,$accountId,$method,$externalReference,$paidAtUtc,$now,$actor->id,$requestKey]);$paymentId=$tx->insertId();$paymentNo='PAY-'.gmdate('Y').'-'.str_pad((string)$paymentId,8,'0',STR_PAD_LEFT);$tx->execute("UPDATE {$payments} SET payment_no=%s WHERE id=%d",[$paymentNo,$paymentId]);
            $tx->execute("INSERT INTO {$movements} (cash_account_id,event_key,source_type,source_id,direction,amount_vnd,occurred_at,actor_id,external_reference,created_at) VALUES (%d,%s,'PAYMENT',%d,%s,%s,%s,%d,%s,%s)",[$accountId,'PAYMENT_POST:'.$paymentId,$paymentId,$direction,$amount,$paidAtUtc,$actor->id,$externalReference,$now]);
            $this->audit->record($tx,$this->uuid(),$actor->id,'payment.posted','payment',$paymentId,'success',[],['status'=>'POSTED','amount_vnd'=>$amount]);return $paymentId;
        });
    }

    public function postExpense(Actor $actor,int $accountId,string $category,string $description,string $amount,string $occurredAt,string $requestKey):int
    {
        if(!$actor->can('erp_expenses_manage'))throw new DomainException('Bạn không có quyền ghi phiếu chi.');if($accountId<1)throw new \InvalidArgumentException('Tài khoản tiền không hợp lệ.');if(!in_array($accountId,$actor->cashAccountIds,true))throw new RecordNotFoundException('Không tìm thấy tài khoản tiền hoặc bạn không có quyền truy cập.');
        $category=trim($category);$description=trim($description);$amount=ExactAmounts::money($amount);$occurredAt=$this->paidAt($occurredAt);
        if($category===''||mb_strlen($category)>64||$description===''||mb_strlen($description)>1000||$amount==='0'||!preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/iD',$requestKey))throw new \InvalidArgumentException('Thông tin phiếu chi không hợp lệ.');
        $accounts=$this->table('erp_cash_accounts');$expenses=$this->table('erp_expenses');$movements=$this->table('erp_cash_movements');$reconciliations=$this->table('erp_reconciliations');
        return $this->transactions->run(function(TransactionManager $tx)use($actor,$accountId,$category,$description,$amount,$occurredAt,$requestKey,$accounts,$expenses,$movements,$reconciliations):int{
            $rows=$tx->fetchAll("SELECT status FROM {$accounts} WHERE id=%d FOR UPDATE",[$accountId]);if($rows===[])throw new RecordNotFoundException('Không tìm thấy tài khoản tiền hoặc bạn không có quyền truy cập.');if(($rows[0]['status']??null)!=='ACTIVE')throw new BusinessConflictException('Tài khoản tiền chưa được kích hoạt.');
            $this->assertCashDateNotInClosedPeriod($tx,$accountId,$occurredAt,$reconciliations);
            $balance=$tx->fetchAll("SELECT CAST((SELECT opening_balance_vnd FROM {$accounts} WHERE id=%d)+(SELECT COALESCE(SUM(CASE WHEN direction='IN' THEN amount_vnd ELSE -amount_vnd END),0) FROM {$movements} WHERE cash_account_id=%d) AS CHAR) AS balance",[$accountId,$accountId]);if(bccomp((string)($balance[0]['balance']??'0'),$amount,0)<0)throw new BusinessConflictException('Số dư quỹ tiền mặt hoặc tài khoản ngân hàng không đủ.');
            $now=$this->now();$tmp='TMP-'.strtoupper(str_replace('-','',$requestKey));$tx->execute("INSERT INTO {$expenses} (expense_no,category,description,amount_vnd,occurred_at,recorded_by,cash_account_id,status,request_key) VALUES (%s,%s,%s,%s,%s,%d,%d,'POSTED',%s)",[$tmp,$category,$description,$amount,$occurredAt,$actor->id,$accountId,$requestKey]);$id=$tx->insertId();$no='EXP-'.gmdate('Y').'-'.str_pad((string)$id,8,'0',STR_PAD_LEFT);$tx->execute("UPDATE {$expenses} SET expense_no=%s WHERE id=%d",[$no,$id]);
            $tx->execute("INSERT INTO {$movements} (cash_account_id,event_key,source_type,source_id,direction,amount_vnd,occurred_at,actor_id,external_reference,created_at) VALUES (%d,%s,'EXPENSE',%d,'OUT',%s,%s,%d,'',%s)",[$accountId,'EXPENSE_POST:'.$id,$id,$amount,$occurredAt,$actor->id,$now]);$this->audit->record($tx,$this->uuid(),$actor->id,'expense.posted','expense',$id,'success',[],['status'=>'POSTED','amount_vnd'=>$amount]);return $id;
        });
    }

    public function requestVoid(Actor $actor,int $paymentId,string $reason):void
    {
        if(!$actor->can('erp_payments_void_request'))throw new DomainException('Bạn không có quyền yêu cầu hủy phiếu thanh toán.');if($paymentId<1||trim($reason)===''||mb_strlen($reason)>1000)throw new \InvalidArgumentException('Yêu cầu hủy phiếu thanh toán không hợp lệ.');$payments=$this->table('erp_payments');$invoices=$this->table('erp_invoices');$accounts=$this->table('erp_cash_accounts');$movements=$this->table('erp_cash_movements');$recItems=$this->table('erp_reconciliation_items');$refs=$this->paymentReferences($paymentId);
        $this->transactions->run(function(TransactionManager $tx)use($actor,$paymentId,$reason,$payments,$invoices,$accounts,$movements,$recItems,$refs):void{$tx->fetchAll("SELECT id FROM {$invoices} WHERE id=%d FOR UPDATE",[$refs['invoice_id']]);$rows=$tx->fetchAll("SELECT invoice_id,cash_account_id,status FROM {$payments} WHERE id=%d AND invoice_id=%d AND cash_account_id=%d FOR UPDATE",[$paymentId,$refs['invoice_id'],$refs['cash_account_id']]);$p=$rows[0]??null;if(!is_array($p)||$p['status']!=='POSTED'||!in_array((int)$p['cash_account_id'],$actor->cashAccountIds,true))throw new DomainException('Phiếu không còn đủ điều kiện để yêu cầu hủy.');$accountId=(int)$p['cash_account_id'];$tx->fetchAll("SELECT id FROM {$accounts} WHERE id=%d FOR UPDATE",[$accountId]);if($tx->fetchAll("SELECT ri.id FROM {$recItems} ri JOIN {$movements} m ON m.id=ri.cash_movement_id WHERE m.event_key=%s AND m.cash_account_id=%d FOR UPDATE",['PAYMENT_POST:'.$paymentId,$accountId])!==[])throw new DomainException('Phiếu đã được đối soát và không thể hủy trong phạm vi hiện tại.');if($tx->execute("UPDATE {$payments} SET status='VOID_PENDING',void_reason=%s,void_requested_by=%d WHERE id=%d AND status='POSTED'",[trim($reason),$actor->id,$paymentId])!==1)throw new DomainException('Phiếu không còn đủ điều kiện để yêu cầu hủy.');$this->audit->record($tx,$this->uuid(),$actor->id,'payment.void.requested','payment',$paymentId,'success',['status'=>'POSTED'],['status'=>'VOID_PENDING']);});
    }

    public function decideVoid(Actor $actor,int $paymentId,bool $approve,string $reason=''):void
    {
        if(!$actor->can('erp_payments_void_approve'))throw new DomainException('Bạn không có quyền quyết định hủy phiếu thanh toán.');if($paymentId<1||(!$approve&&(trim($reason)===''||mb_strlen($reason)>1000)))throw new \InvalidArgumentException('Quyết định hủy phiếu thanh toán không hợp lệ.');$payments=$this->table('erp_payments');$invoices=$this->table('erp_invoices');$accounts=$this->table('erp_cash_accounts');$recItems=$this->table('erp_reconciliation_items');$movements=$this->table('erp_cash_movements');$refs=$this->paymentReferences($paymentId);
        $this->transactions->run(function(TransactionManager $tx)use($actor,$paymentId,$approve,$reason,$payments,$invoices,$accounts,$recItems,$movements,$refs):void{$tx->fetchAll("SELECT id FROM {$invoices} WHERE id=%d FOR UPDATE",[$refs['invoice_id']]);$rows=$tx->fetchAll("SELECT invoice_id,cash_account_id,status,void_requested_by FROM {$payments} WHERE id=%d AND invoice_id=%d AND cash_account_id=%d FOR UPDATE",[$paymentId,$refs['invoice_id'],$refs['cash_account_id']]);$p=$rows[0]??null;if(!is_array($p)||$p['status']!=='VOID_PENDING'||(int)$p['void_requested_by']===$actor->id||!in_array((int)$p['cash_account_id'],$actor->cashAccountIds,true))throw new DomainException('Phiếu không còn chờ quyết định hoặc người yêu cầu phải khác người duyệt.');$accountId=(int)$p['cash_account_id'];$tx->fetchAll("SELECT id FROM {$accounts} WHERE id=%d FOR UPDATE",[$accountId]);if($tx->fetchAll("SELECT ri.id FROM {$recItems} ri JOIN {$movements} m ON m.id=ri.cash_movement_id WHERE m.event_key=%s AND m.cash_account_id=%d FOR UPDATE",['PAYMENT_POST:'.$paymentId,$accountId])!==[])throw new DomainException('Phiếu đã được đối soát và không thể hủy trong phạm vi hiện tại.');$now=$this->now();if($approve){if($tx->execute("UPDATE {$payments} SET status='VOID_APPROVED',void_approved_by=%d,void_approved_at=%s WHERE id=%d AND status='VOID_PENDING'",[$actor->id,$now,$paymentId])!==1)throw new DomainException('Phiếu không còn chờ quyết định.');$after='VOID_APPROVED';}else{if($tx->execute("UPDATE {$payments} SET status='POSTED',void_rejected_by=%d,void_rejected_at=%s,void_rejection_reason=%s WHERE id=%d AND status='VOID_PENDING'",[$actor->id,$now,trim($reason),$paymentId])!==1)throw new DomainException('Phiếu không còn chờ quyết định.');$after='POSTED';}$this->audit->record($tx,$this->uuid(),$actor->id,$approve?'payment.void.approved':'payment.void.rejected','payment',$paymentId,'success',['status'=>'VOID_PENDING'],['status'=>$after]);});
    }

    public function applyVoid(Actor $actor,int $paymentId):void
    {
        if(!$actor->can('erp_payments_void_apply'))throw new DomainException('Bạn không có quyền áp dụng việc hủy.');if($paymentId<1)throw new \InvalidArgumentException('Phiếu thanh toán không hợp lệ.');$payments=$this->table('erp_payments');$invoices=$this->table('erp_invoices');$accounts=$this->table('erp_cash_accounts');$movements=$this->table('erp_cash_movements');$recItems=$this->table('erp_reconciliation_items');$reconciliations=$this->table('erp_reconciliations');$refs=$this->paymentReferences($paymentId);
        $this->transactions->run(function(TransactionManager $tx)use($actor,$paymentId,$payments,$invoices,$accounts,$movements,$recItems,$reconciliations,$refs):void{
            $tx->fetchAll("SELECT id FROM {$invoices} WHERE id=%d FOR UPDATE",[$refs['invoice_id']]);
            $rows=$tx->fetchAll("SELECT invoice_id,cash_account_id,status,amount_vnd,direction,void_approved_by FROM {$payments} WHERE id=%d AND invoice_id=%d AND cash_account_id=%d FOR UPDATE",[$paymentId,$refs['invoice_id'],$refs['cash_account_id']]);$p=$rows[0]??null;
            if(!is_array($p)||$p['status']!=='VOID_APPROVED'||(int)$p['void_approved_by']===$actor->id||!in_array((int)$p['cash_account_id'],$actor->cashAccountIds,true))throw new DomainException('Phiếu chưa được người khác phê duyệt hoặc không thuộc tài khoản được phân quyền.');
            $accountId=(int)$p['cash_account_id'];$tx->fetchAll("SELECT id FROM {$accounts} WHERE id=%d FOR UPDATE",[$accountId]);
            if($tx->fetchAll("SELECT ri.id FROM {$recItems} ri JOIN {$movements} m ON m.id=ri.cash_movement_id WHERE m.event_key=%s AND m.cash_account_id=%d FOR UPDATE",['PAYMENT_POST:'.$paymentId,$accountId])!==[])throw new DomainException('Phiếu đã được đối soát và không thể hủy trong phạm vi hiện tại.');
            $now=$this->now();$this->assertCashDateNotInClosedPeriod($tx,$accountId,$now,$reconciliations);$reverse=$p['direction']==='IN'?'OUT':'IN';
            if($reverse==='OUT'){$balance=$tx->fetchAll("SELECT CAST((SELECT opening_balance_vnd FROM {$accounts} WHERE id=%d)+(SELECT COALESCE(SUM(CASE WHEN direction='IN' THEN amount_vnd ELSE -amount_vnd END),0) FROM {$movements} WHERE cash_account_id=%d) AS CHAR) AS balance",[$accountId,$accountId]);if(bccomp((string)($balance[0]['balance']??'0'),(string)$p['amount_vnd'],0)<0)throw new DomainException('Không thể đảo phiếu vì số dư tài khoản tiền không đủ.');}
            if($tx->execute("INSERT INTO {$movements} (cash_account_id,event_key,source_type,source_id,direction,amount_vnd,occurred_at,actor_id,external_reference,created_at) SELECT cash_account_id,%s,'PAYMENT_VOID',id,%s,amount_vnd,%s,%d,external_reference,%s FROM {$payments} WHERE id=%d",['PAYMENT_VOID:'.$paymentId,$reverse,$now,$actor->id,$now,$paymentId])!==1)throw new DomainException('Không thể tạo bút toán đảo cho phiếu thanh toán.');
            if($tx->execute("UPDATE {$payments} SET status='VOID',void_applied_by=%d WHERE id=%d AND status='VOID_APPROVED'",[$actor->id,$paymentId])!==1)throw new DomainException('Phiếu không còn chờ áp dụng việc hủy.');
            $this->audit->record($tx,$this->uuid(),$actor->id,'payment.void.applied','payment',$paymentId,'success',['status'=>'VOID_APPROVED'],['status'=>'VOID']);
        });
    }

    private function paidAt(string $local):string
    {
        $date=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $local,new DateTimeZone('Asia/Ho_Chi_Minh'));$errors=DateTimeImmutable::getLastErrors();if(!$date||($errors!==false&&($errors['warning_count']||$errors['error_count']))||$date->format('Y-m-d\TH:i')!==$local)throw new \InvalidArgumentException('Ngày giao dịch không hợp lệ.');if($date>new DateTimeImmutable('now',new DateTimeZone('Asia/Ho_Chi_Minh')))throw new \InvalidArgumentException('Ngày giao dịch không được ở tương lai.');return $date->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s.u');
    }
    private function assertCashDateNotInClosedPeriod(TransactionManager $tx,int $accountId,string $occurredAtUtc,string $reconciliations):void
    {
        $localDate=(new DateTimeImmutable($occurredAtUtc,new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('Asia/Ho_Chi_Minh'))->format('Y-m-d');
        $closed=$tx->fetchAll("SELECT id FROM {$reconciliations} WHERE cash_account_id=%d AND status='CLOSED' AND period_to>=%s ORDER BY period_to ASC,id ASC LIMIT 1 FOR UPDATE",[$accountId,$localDate]);
        if($closed!==[])throw new BusinessConflictException('Ngày giao dịch này sẽ làm thay đổi kỳ đối soát đã chốt.');
    }
    /** @return array{invoice_id:int,cash_account_id:int} */
    private function paymentReferences(int $paymentId):array
    {
        $payments=$this->table('erp_payments');$row=$this->db->get_row($this->db->prepare("SELECT invoice_id,cash_account_id FROM {$payments} WHERE id=%d",$paymentId),ARRAY_A);
        $invoiceId=is_array($row)?filter_var($row['invoice_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]):false;$accountId=is_array($row)?filter_var($row['cash_account_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]):false;
        if($this->db->last_error!=='')throw new DatabaseException();
        if($invoiceId===false||$accountId===false)throw new RecordNotFoundException('Không tìm thấy phiếu thanh toán.');
        return ['invoice_id'=>(int)$invoiceId,'cash_account_id'=>(int)$accountId];
    }
    private function now():string{return (new DateTimeImmutable('now',new DateTimeZone('UTC')))->format('Y-m-d H:i:s.u');}
    private function table(string $suffix):string{if(!preg_match('/\Aerp_[a-z_]+\z/D',$suffix)||!preg_match('/\A[A-Za-z0-9_]{1,32}\z/D',$this->db->prefix))throw new DomainException('Cấu hình bảng dữ liệu không hợp lệ.');return '`'.$this->db->prefix.$suffix.'`';}
    private function uuid():string{if(function_exists('wp_generate_uuid4'))return wp_generate_uuid4();$bytes=random_bytes(16);$bytes[6]=chr((ord($bytes[6])&15)|64);$bytes[8]=chr((ord($bytes[8])&63)|128);return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($bytes),4));}
}
