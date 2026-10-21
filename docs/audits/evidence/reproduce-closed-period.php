<?php
declare(strict_types=1);
require '/var/www/html/wp-load.php';
use MiniERP\Infrastructure\Database\{InvoiceSchema,CashSchema,AuditSchema,TransactionManager};
use MiniERP\Infrastructure\Audit\AuditLogger;
use MiniERP\Application\Services\{PaymentService,ReconciliationService};
use MiniERP\Security\{Actor,RolesInstaller};
global $wpdb;
if(PHP_SAPI!=='cli'||wp_get_environment_type()!=='local'||DB_NAME!=='mini_erp_local'||$wpdb->prefix!=='paxg_')throw new RuntimeException('Unsafe environment');
$p='paxg_test_audit_'.bin2hex(random_bytes(5)).'_';
$db=new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);$db->prefix=$p;
$tables=['erp_reconciliation_items','erp_reconciliations','erp_expenses','erp_payments','erp_cash_movements','erp_cash_accounts','erp_invoice_items','erp_invoices','erp_audit_logs'];
try {
 echo 'ISOLATION='.$db->get_var('SELECT @@transaction_isolation')."\n";
 (new AuditSchema($db))->migrate();(new InvoiceSchema($db))->migrate();(new CashSchema($db))->migrate();
 $tx=new TransactionManager($db);$audit=new AuditLogger($p);$payments=new PaymentService($db,$tx,$audit);$rec=new ReconciliationService($db,$tx,$audit);
 $caps=RolesInstaller::definitions();
 $accountant=new Actor(901,'erp_accountant',$caps['erp_accountant']['capabilities']);
 $id=$payments->createCashAccount($accountant,'AUDIT','Audit isolated account','CASH','1000');
 $accountant=new Actor(901,'erp_accountant',$caps['erp_accountant']['capabilities'],[],[$id]);
 $manager=new Actor(902,'erp_manager',$caps['erp_manager']['capabilities'],[],[$id]);
 $payments->activateCashAccount($manager,$id);
 $day=(new DateTimeImmutable('yesterday',new DateTimeZone('Asia/Ho_Chi_Minh')))->format('Y-m-d');
 $batch=$rec->create($accountant,$id,$day,$day,'1000','1000');$rec->close($manager,$batch);
 echo 'BEFORE='.json_encode($db->get_row("SELECT status,statement_closing_vnd FROM `{$p}erp_reconciliations` WHERE id={$batch}",ARRAY_A))."\n";
 $expense=$payments->postExpense($accountant,$id,'Audit','Backdated expense after close','100',$day.'T12:00',wp_generate_uuid4());
 $row=$db->get_row("SELECT r.status,r.statement_closing_vnd,(1000-(SELECT SUM(amount_vnd) FROM `{$p}erp_cash_movements`)) actual_closing,(SELECT COUNT(*) FROM `{$p}erp_cash_movements`) movement_count,(SELECT COUNT(*) FROM `{$p}erp_reconciliation_items`) matched_count FROM `{$p}erp_reconciliations` r WHERE id={$batch}",ARRAY_A);
 echo 'AFTER_EXPENSE_ID='.$expense.' '.json_encode($row)."\n";
 if($row['status']!=='CLOSED'||$row['actual_closing']!=='900'||$row['movement_count']!=='1'||$row['matched_count']!=='0')throw new RuntimeException('Unexpected reproduction result');
 echo "REPRODUCED: closed batch remains CLOSED after backdated unmatched expense alters its closing balance.\n";
}finally{
 foreach($tables as $suffix){$t=$p.$suffix;if(!preg_match('/\Apaxg_test_audit_[a-f0-9]{10}_erp_[a-z_]+\z/D',$t))throw new RuntimeException('Unsafe fixture table');if($db->query('DROP TABLE IF EXISTS `'.$t.'`')===false)throw new RuntimeException('Cleanup failed');if((string)$db->get_var($db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s',DB_NAME,$t))!=='0')throw new RuntimeException('Cleanup unverified');}
 echo 'CLEANUP_VERIFIED='.$p."\n";$db->close();
}
