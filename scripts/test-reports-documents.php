<?php
declare(strict_types=1);

use MiniERP\Application\Services\InvoiceDocumentService;
use MiniERP\Application\Services\InvoiceService;
use MiniERP\Application\Services\CashDocumentService;
use MiniERP\Application\Services\PaymentService;
use MiniERP\Application\Services\ReconciliationService;
use MiniERP\Application\Services\ReportService;
use MiniERP\Infrastructure\Audit\AuditLogger;
use MiniERP\Infrastructure\CsvRenderer;
use MiniERP\Infrastructure\Database\AuditSchema;
use MiniERP\Infrastructure\Database\CashSchema;
use MiniERP\Infrastructure\Database\InventorySchema;
use MiniERP\Infrastructure\Database\InvoiceSchema;
use MiniERP\Infrastructure\Database\MasterSchema;
use MiniERP\Infrastructure\Database\SalesSchema;
use MiniERP\Infrastructure\Database\TransactionManager;
use MiniERP\Application\Services\ReportExportService;
use MiniERP\Security\Actor;
use MiniERP\Security\RolesInstaller;

if (PHP_SAPI !== 'cli' || !is_file('/var/www/html/wp-load.php')) { fwrite(STDERR,"Local WordPress CLI is required.\n"); exit(2); }
require_once '/var/www/html/wp-load.php';
global $wpdb;
$assert=static function(bool $ok,string $message):void{if(!$ok)throw new RuntimeException($message);};
$assert(wp_get_environment_type()==='local'&&DB_NAME==='mini_erp_local'&&$wpdb->prefix==='paxg_','Refusing to run outside the local fixture database.');
$prefix='paxg_test_reports_'.bin2hex(random_bytes(5)).'_';
$assert((bool)preg_match('/\Apaxg_test_reports_[a-f0-9]{10}_\z/D',$prefix),'Generated report fixture prefix is unsafe.');
$db=new wpdb(DB_USER,DB_PASSWORD,DB_NAME,DB_HOST);$db->prefix=$prefix;
$dropOrder=['erp_reconciliation_items','erp_reconciliations','erp_expenses','erp_payments','erp_cash_movements','erp_cash_accounts','erp_invoice_items','erp_invoices','erp_stock_adjustment_items','erp_stock_adjustments','erp_goods_receipt_items','erp_goods_receipts','erp_purchase_order_items','erp_purchase_orders','erp_shipment_items','erp_shipments','erp_stock_movements','erp_stock_balances','erp_sales_order_items','erp_sales_orders','erp_warehouses','erp_suppliers','erp_customers','erp_products','erp_categories','erp_audit_logs'];
$results=[];$failure=null;$exit=0;$oldCompanyName=get_option('erp_company_name','__MISSING__');
$insert=static function(wpdb $connection,string $table,array $values):int{if($connection->insert($table,$values)===false)throw new RuntimeException('Fixture insert failed for '.$table.': '.$connection->last_error);return (int)$connection->insert_id;};
try{
    (new MasterSchema($db))->migrate();(new AuditSchema($db))->migrate();(new SalesSchema($db))->migrate();(new InventorySchema($db))->migrate();(new InvoiceSchema($db))->migrate();(new CashSchema($db))->migrate();
    $category=$insert($db,$prefix.'erp_categories',['code'=>'REPORT','name'=>'Báo cáo']);
    $product=$insert($db,$prefix.'erp_products',['sku'=>'CAFÉ-01','name'=>'Cà phê đặc sản','category_id'=>$category,'unit'=>'hộp','unit_step'=>'1.000','sale_price_vnd'=>10000]);
    $customer=$insert($db,$prefix.'erp_customers',['code'=>'CUS-REPORT','name'=>'Khách hàng mẫu']);
    $warehouse=$insert($db,$prefix.'erp_warehouses',['code'=>'WH-REPORT','name'=>'Kho báo cáo']);
    $warehouseTwo=$insert($db,$prefix.'erp_warehouses',['code'=>'WH-REPORT-2','name'=>'Kho báo cáo thứ hai']);
    $insert($db,$prefix.'erp_stock_balances',['warehouse_id'=>$warehouse,'product_id'=>$product,'quantity'=>'3.000']);
    $insert($db,$prefix.'erp_stock_balances',['warehouse_id'=>$warehouseTwo,'product_id'=>$product,'quantity'=>'2.000']);
    $orderIds=[];$shipmentIds=[];
    foreach([500,501] as $index=>$owner){
        $order=$insert($db,$prefix.'erp_sales_orders',['order_no'=>'SO-REPORT-'.($index+1),'customer_id'=>$customer,'warehouse_id'=>$warehouse,'created_by'=>$owner,'status'=>'SHIPPED','discount_bps'=>0,'tax_bps'=>0,'subtotal_exact_vnd'=>'10000.000','rounding_adjustment_vnd'=>'0.000','subtotal_vnd'=>10000,'discount_vnd'=>0,'tax_vnd'=>0,'total_vnd'=>10000]);
        $shipment=$insert($db,$prefix.'erp_shipments',['shipment_no'=>'SHP-REPORT-'.($index+1),'sales_order_id'=>$order,'warehouse_id'=>$warehouse,'shipped_by'=>$owner,'shipped_at'=>gmdate('Y-m-d H:i:s').'.000000','request_key'=>wp_generate_uuid4()]);
        $orderIds[]=$order;$shipmentIds[]=$shipment;
    }
    $invoiceIds=[];
    foreach([['2026-10-18 16:59:59.999999',500],['2026-10-21 16:59:59.999999',501]] as $index=>[$due,$owner]){
        $invoice=$insert($db,$prefix.'erp_invoices',['invoice_no'=>'INV-2026-'.str_pad((string)($index+1),8,'0',STR_PAD_LEFT),'source_type'=>'SHIPMENT','source_id'=>$shipmentIds[$index],'direction'=>'IN','party_id'=>$customer,'seller_name'=>'Công ty Mini ERP','seller_address'=>'Hà Nội','seller_tax_id'=>'0100000000','buyer_name'=>'Khách hàng mẫu','buyer_address'=>'Hồ Chí Minh','buyer_tax_id'=>'0200000000','subtotal_exact_vnd'=>'10000.000','rounding_adjustment_vnd'=>'0.000','subtotal_vnd'=>10000,'discount_vnd'=>0,'tax_vnd'=>0,'total_vnd'=>10000,'status'=>'ISSUED','issued_at'=>gmdate('Y-m-d H:i:s').'.000000','due_at'=>$due,'issued_by'=>$owner]);
        $insert($db,$prefix.'erp_invoice_items',['invoice_id'=>$invoice,'source_item_id'=>1,'sku_snapshot'=>'CAFÉ-01','name_snapshot'=>'Cà phê đặc sản','unit_snapshot'=>'hộp','quantity'=>'1.000','unit_price_vnd'=>10000,'line_subtotal_exact_vnd'=>'10000.000','rounding_allocation_vnd'=>'0.000','line_subtotal_vnd'=>10000,'discount_vnd'=>0,'tax_vnd'=>0,'line_total_vnd'=>10000]);$invoiceIds[]=$invoice;
    }
    $cashAccount=$insert($db,$prefix.'erp_cash_accounts',['account_code'=>'CASH-REPORT','name'=>'Quỹ tiền mặt thử nghiệm','type'=>'CASH','currency'=>'VND','opening_balance_vnd'=>5000,'status'=>'ACTIVE','created_by'=>1,'activated_by'=>2,'activated_at'=>gmdate('Y-m-d H:i:s').'.000000','created_at'=>gmdate('Y-m-d H:i:s').'.000000']);
    $occurred=gmdate('Y-m-d H:i:s').'.000000';
    $payment=$insert($db,$prefix.'erp_payments',['payment_no'=>'PAY-REPORT-1','invoice_id'=>$invoiceIds[0],'direction'=>'IN','amount_vnd'=>1000,'cash_account_id'=>$cashAccount,'method'=>'CASH','external_reference'=>'TEST-REF','paid_at'=>$occurred,'posted_at'=>$occurred,'status'=>'POSTED','created_by'=>1,'request_key'=>wp_generate_uuid4()]);
    $insert($db,$prefix.'erp_cash_movements',['cash_account_id'=>$cashAccount,'event_key'=>'PAYMENT_POST:'.$payment,'source_type'=>'PAYMENT','source_id'=>$payment,'direction'=>'IN','amount_vnd'=>1000,'occurred_at'=>$occurred,'actor_id'=>1,'external_reference'=>'TEST-REF','created_at'=>$occurred]);
    $expense=$insert($db,$prefix.'erp_expenses',['expense_no'=>'EXP-2026-00000001','category'=>'Văn phòng phẩm','description'=>'Mua giấy in','amount_vnd'=>500,'occurred_at'=>$occurred,'recorded_by'=>1,'cash_account_id'=>$cashAccount,'status'=>'POSTED','request_key'=>wp_generate_uuid4()]);
    $insert($db,$prefix.'erp_cash_movements',['cash_account_id'=>$cashAccount,'event_key'=>'EXPENSE_POST:'.$expense,'source_type'=>'EXPENSE','source_id'=>$expense,'direction'=>'OUT','amount_vnd'=>500,'occurred_at'=>$occurred,'actor_id'=>1,'external_reference'=>'','created_at'=>$occurred]);
    $managerCaps=RolesInstaller::definitions()['erp_manager']['capabilities'];$salesCaps=RolesInstaller::definitions()['erp_sales']['capabilities'];$warehouseCaps=RolesInstaller::definitions()['erp_warehouse']['capabilities'];
    $manager=new Actor(1,'erp_manager',$managerCaps,[],[$cashAccount]);$sales=new Actor(500,'erp_sales',$salesCaps);$unassignedWarehouse=new Actor(3,'erp_warehouse',$warehouseCaps);
    $reports=new ReportService($db);$today='2026-10-20';
    $revenue=$reports->salesRevenue($manager,'2026-10-01',$today);$assert($revenue['total_vnd']===20000&&$revenue['invoice_count']===2,'Revenue report did not match issued sales invoices.');
    $aging=$reports->receivables($manager,$today);$assert($aging['total_open']===2&&$aging['buckets']['days_1_30']['outstanding_vnd']===9000&&$aging['buckets']['not_due']['outstanding_vnd']===10000,'Receivables aging did not apply effective payments and local due dates.');
    $cash=$reports->cashBook($manager,$today,$today);$assert($cash['in_vnd']===1000&&$cash['out_vnd']===500&&$cash['accounts'][0]['balance_vnd']===5500&&$cash['movement_count']===2,'Cash book did not reconcile opening plus posted movements.');
    $stock=$reports->stockOnHand($manager);$assert($stock['total_rows']===2&&$stock['product_count']===1&&array_column($stock['rows'],'quantity')===['3.000','2.000'],'Manager stock report did not include all warehouses without requiring explicit warehouse assignments.');
    $warehouseStock=$reports->stockOnHand($unassignedWarehouse);$assert($warehouseStock['rows']===[]&&$warehouseStock['total_rows']===0,'Warehouse without assigned scopes unexpectedly read stock rows.');
    $results[]='revenue, HCM aging, effective-payment balances, assigned cash book, Manager all-warehouse stock and empty Warehouse scope match isolated MySQL ledgers';

    $exports=new ReportExportService($db);
    $exportRevenue=$exports->rows($manager,'revenue','2026-10-01',$today,$today);
    $exportAging=$exports->rows($manager,'receivables','2026-10-01',$today,$today);
    $exportCash=$exports->rows($manager,'cash',$today,$today,$today);
    $exportStock=$exports->rows($manager,'stock',$today,$today,$today);
    $scopedStockActor=new Actor(3,'erp_manager',['erp_reports_stock'],[$warehouse],[]);
    $scopedStock=$exports->rows($scopedStockActor,'stock',$today,$today,$today);
    $ownExportAging=$exports->rows($sales,'receivables',$today,$today,$today);
    $csv=CsvRenderer::render(['invoice_no'=>'Số hóa đơn','buyer_name'=>'Khách hàng','total_vnd'=>'Tổng (VND)'],$exportRevenue);
    $assert(count($exportRevenue)===2&&count($exportAging)===2&&count($exportCash)===2&&count($exportStock)===2&&count($scopedStock)===1,'CSV report export projections did not preserve their source report scopes.');
    $assert(count($ownExportAging)===1&&$ownExportAging[0]['invoice_no']==='INV-2026-00000001','CSV receivables export escaped the Sales invoice-owner scope.');
    $assert(str_starts_with($csv,"\xEF\xBB\xBF")&&str_contains($csv,'INV-2026-00000001'),'CSV renderer did not emit a UTF-8 BOM and the expected invoice rows.');
    try{$exports->rows($sales,'cash',$today,$today,$today);throw new RuntimeException('Sales role exported the finance report.');}catch(DomainException){}
    $results[]='bounded CSV projections preserve finance-account, sales-owner and warehouse scopes; UTF-8 BOM and read-only report roles pass';

    $zone=new DateTimeZone('Asia/Ho_Chi_Minh');$closedDay=(new DateTimeImmutable('now',$zone))->modify('-1 day')->format('Y-m-d');$beforeClosedDay=(new DateTimeImmutable($closedDay.' 12:00:00',$zone))->modify('-1 day')->format('Y-m-d\\TH:i');$insideClosedDay=$closedDay.'T12:00';$currentLocal=(new DateTimeImmutable('now',$zone))->format('Y-m-d\\TH:i');
    $accountantCaps=RolesInstaller::definitions()['erp_accountant']['capabilities'];$accountant=new Actor(2,'erp_accountant',$accountantCaps,[$warehouse],[$cashAccount]);$transactions=new TransactionManager($db);$audit=new AuditLogger();$reconciliations=new ReconciliationService($db,$transactions,$audit);$closedBatch=$reconciliations->create($accountant,$cashAccount,$closedDay,$closedDay,'5000','5000');$reconciliations->close($manager,$closedBatch);
    $closedStatus=$db->get_var($db->prepare('SELECT status FROM `'.$prefix.'erp_reconciliations` WHERE id=%d',$closedBatch));$assert($closedStatus==='CLOSED','Historical reconciliation fixture did not close.');
    $paymentsService=new PaymentService($db,new TransactionManager($db),new AuditLogger());
    $mustRejectBackdate=static function(callable $operation,string $message)use($assert):void{try{$operation();}catch(DomainException){return;}throw new RuntimeException($message);};
    $beforePaymentKey=wp_generate_uuid4();$mustRejectBackdate(static fn()=>$paymentsService->post($accountant,$invoiceIds[0],$cashAccount,'100','CASH','BACKDATE-BEFORE',$beforeClosedDay,$beforePaymentKey),'Payment dated before a closed period was posted and changed its opening balance.');
    $insidePaymentKey=wp_generate_uuid4();$mustRejectBackdate(static fn()=>$paymentsService->post($accountant,$invoiceIds[0],$cashAccount,'100','CASH','BACKDATE-INSIDE',$insideClosedDay,$insidePaymentKey),'Payment dated inside a closed period was posted.');
    $beforeExpenseKey=wp_generate_uuid4();$mustRejectBackdate(static fn()=>$paymentsService->postExpense($accountant,$cashAccount,'Office','Backdated expense before a closed period','100',$beforeClosedDay,$beforeExpenseKey),'Expense dated before a closed period was posted and changed its opening balance.');
    $insideExpenseKey=wp_generate_uuid4();$mustRejectBackdate(static fn()=>$paymentsService->postExpense($accountant,$cashAccount,'Office','Backdated expense inside a closed period','100',$insideClosedDay,$insideExpenseKey),'Expense dated inside a closed period was posted.');
    $rejectedCount=(int)$db->get_var($db->prepare('SELECT COUNT(*) FROM `'.$prefix.'erp_payments` WHERE request_key IN (%s,%s)', $beforePaymentKey,$insidePaymentKey))+(int)$db->get_var($db->prepare('SELECT COUNT(*) FROM `'.$prefix.'erp_expenses` WHERE request_key IN (%s,%s)', $beforeExpenseKey,$insideExpenseKey));$assert($rejectedCount===0,'A rejected backdated cash operation left a source record behind.');
    $currentExpenseKey=wp_generate_uuid4();$currentExpense=$paymentsService->postExpense($accountant,$cashAccount,'Office','Current-day expense after historical close','100',$currentLocal,$currentExpenseKey);
    $currentPaymentKey=wp_generate_uuid4();$currentPayment=$paymentsService->post($accountant,$invoiceIds[0],$cashAccount,'100','CASH','AFTER-CLOSED-PERIOD',$currentLocal,$currentPaymentKey);
    $assert((int)$db->get_var($db->prepare('SELECT COUNT(*) FROM `'.$prefix.'erp_cash_movements` WHERE source_id IN (%d,%d) AND cash_account_id=%d',$currentExpense,$currentPayment,$cashAccount))===2,'Valid post-period expense/payment did not create both cash movements.');
    $voidAccount=$insert($db,$prefix.'erp_cash_accounts',['account_code'=>'CASH-VOID-GUARD','name'=>'Quỹ kiểm tra kỳ hủy','type'=>'CASH','currency'=>'VND','opening_balance_vnd'=>5000,'status'=>'ACTIVE','created_by'=>1,'activated_by'=>2,'activated_at'=>gmdate('Y-m-d H:i:s').'.000000','created_at'=>gmdate('Y-m-d H:i:s').'.000000']);
    $voidAccountant=new Actor(2,'erp_accountant',$accountantCaps,[$warehouse],[$voidAccount]);$voidManager=new Actor(1,'erp_manager',$managerCaps,[$warehouse],[$voidAccount]);$twoDaysAgo=(new DateTimeImmutable('now',$zone))->modify('-2 days')->format('Y-m-d');$reconciliations->create($voidAccountant,$voidAccount,$twoDaysAgo,$twoDaysAgo,'5000','5000');$voidPaymentAt=(new DateTimeImmutable('now',$zone))->modify('-1 day')->setTime(12,0)->format('Y-m-d\\TH:i');$voidPayment=$paymentsService->post($voidAccountant,$invoiceIds[1],$voidAccount,'1000','CASH','REVERSAL-GUARD',$voidPaymentAt,wp_generate_uuid4());$paymentsService->requestVoid($voidAccountant,$voidPayment,'Reversal guard smoke');$paymentsService->decideVoid($voidManager,$voidPayment,true);
    $todayLocal=(new DateTimeImmutable('now',$zone))->format('Y-m-d');$voidDayBatch=$reconciliations->create($voidAccountant,$voidAccount,$todayLocal,$todayLocal,'6000','6000');$reconciliations->close($voidManager,$voidDayBatch);
    $mustRejectBackdate(static fn()=>$paymentsService->applyVoid($voidAccountant,$voidPayment),'Applying a payment reversal changed the opening balance of a closed current-day reconciliation.');$voidState=$db->get_var($db->prepare('SELECT status FROM `'.$prefix.'erp_payments` WHERE id=%d',$voidPayment));$reversalCount=(int)$db->get_var($db->prepare('SELECT COUNT(*) FROM `'.$prefix.'erp_cash_movements` WHERE event_key=%s','PAYMENT_VOID:'.$voidPayment));$assert($voidState==='VOID_APPROVED'&&$reversalCount===0,'A rejected reversal changed payment state or left a cash movement.');
    $results[]='closed periods reject backdated payment/expense and reversal postings that change period flow or opening balance; later postings remain writable';

    $documents=new InvoiceDocumentService($db);$model=$documents->viewModel($manager,$invoiceIds[0]);$html=$documents->html($model,false);$pdf=$documents->pdf($model);
    $assert(str_contains($html,'CHỨNG TỪ NỘI BỘ')&&str_contains($html,'Cà phê đặc sản'),'HTML document did not preserve Vietnamese snapshot content.');
    $assert(str_starts_with($pdf,'%PDF-')&&preg_match('/\/Type\s*\/Page\b/',$pdf)===1,'PDF output was empty or missing its page tree.');

    $cashDocuments=new CashDocumentService($db);$paymentVoucher=$cashDocuments->paymentVoucher($manager,$payment);$paymentHtml=$cashDocuments->html($paymentVoucher,false);$paymentPdf=$cashDocuments->pdf($paymentVoucher);
    $assert(str_contains($paymentHtml,'PAY-REPORT-1')&&str_contains($paymentHtml,'INV-2026-00000001')&&str_contains($paymentHtml,'TEST-REF'),'Payment voucher omitted its posted payment, readable invoice source or reference.');
    $assert(str_starts_with($paymentPdf,'%PDF-')&&preg_match('/\/Type\s*\/Page\b/',$paymentPdf)===1,'Payment voucher PDF was empty or missing its page tree.');
    $expenseVoucher=$cashDocuments->expenseVoucher($manager,$expense);$expenseHtml=$cashDocuments->html($expenseVoucher,false);$expensePdf=$cashDocuments->pdf($expenseVoucher);
    $assert(str_contains($expenseHtml,'EXP-2026-00000001')&&str_contains($expenseHtml,'Mua giấy in'),'Expense voucher omitted its posted expense source.');
    $assert(str_starts_with($expensePdf,'%PDF-')&&preg_match('/\/Type\s*\/Page\b/',$expensePdf)===1,'Expense voucher PDF was empty or missing its page tree.');
    $secondAccount=$insert($db,$prefix.'erp_cash_accounts',['account_code'=>'CASH-OTHER','name'=>'Quỹ chưa gán','type'=>'CASH','currency'=>'VND','opening_balance_vnd'=>0,'status'=>'ACTIVE','created_by'=>1,'activated_by'=>2,'activated_at'=>$occurred,'created_at'=>$occurred]);
    $unassignedPayment=$insert($db,$prefix.'erp_payments',['payment_no'=>'PAY-REPORT-2','invoice_id'=>$invoiceIds[0],'direction'=>'IN','amount_vnd'=>700,'cash_account_id'=>$secondAccount,'method'=>'CASH','external_reference'=>'OTHER-REF','paid_at'=>$occurred,'posted_at'=>$occurred,'status'=>'POSTED','created_by'=>1,'request_key'=>wp_generate_uuid4()]);
    $insert($db,$prefix.'erp_cash_movements',['cash_account_id'=>$secondAccount,'event_key'=>'PAYMENT_POST:'.$unassignedPayment,'source_type'=>'PAYMENT','source_id'=>$unassignedPayment,'direction'=>'IN','amount_vnd'=>700,'occurred_at'=>$occurred,'actor_id'=>1,'external_reference'=>'OTHER-REF','created_at'=>$occurred]);
    $emptyScopeManager=new Actor(2,'erp_manager',$managerCaps,[$warehouse],[]);
    $assignedCashExport=$exports->rows($manager,'cash',$today,$today,$today);
    $emptyCashExport=$exports->rows($emptyScopeManager,'cash',$today,$today,$today);
    $assert(count($assignedCashExport)>=2&&array_values(array_unique(array_column($assignedCashExport,'account_code')))===['CASH-REPORT']&&$emptyCashExport===[],'CSV cash export did not enforce only explicitly assigned cash accounts.');
    try{$cashDocuments->paymentVoucher($manager,$unassignedPayment);throw new RuntimeException('Manager read a payment from an unassigned cash account.');}catch(DomainException){}
    try{$cashDocuments->expenseVoucher($emptyScopeManager,$expense);throw new RuntimeException('Manager read an expense without an assigned cash account.');}catch(DomainException){}
    $results[]='payment/expense HTML and PDF vouchers use posted source plus matching ledger and enforce assigned-account scope';

    $approvedAt=gmdate('Y-m-d H:i:s',time()+60).'.000000';$reversedAt=gmdate('Y-m-d H:i:s',time()+120).'.000000';
    if($db->update($prefix.'erp_payments',['status'=>'VOID','void_requested_by'=>4,'void_approved_by'=>2,'void_approved_at'=>$approvedAt,'void_applied_by'=>3],['id'=>$payment])===false)throw new RuntimeException('Could not prepare void voucher fixture.');
    $insert($db,$prefix.'erp_cash_movements',['cash_account_id'=>$cashAccount,'event_key'=>'PAYMENT_VOID:'.$payment,'source_type'=>'PAYMENT_VOID','source_id'=>$payment,'direction'=>'OUT','amount_vnd'=>1000,'occurred_at'=>$reversedAt,'actor_id'=>3,'external_reference'=>'TEST-REF','created_at'=>$reversedAt]);
    $voidHtml=$cashDocuments->html($cashDocuments->paymentVoucher($manager,$payment),false);
    $assert(str_contains($voidHtml,'ĐÃ HỦY')&&str_contains($voidHtml,'#2')&&str_contains($voidHtml,$approvedAt),'Voided payment voucher omitted its cancellation marker, approver or approval time.');
    $results[]='voided payment reprint shows cancellation approver/time and validates the reversal ledger row';

    try{$documents->viewModel($sales,$invoiceIds[1]);throw new RuntimeException('Sales user read another owner invoice.');}catch(DomainException){}
    $ownAging=$reports->receivables($sales,$today);$assert($ownAging['total_open']===1&&(int)$ownAging['rows'][0]['id']===$invoiceIds[0],'Sales aging report escaped the invoice-owner scope.');
    try{$reports->cashBook($sales,$today,$today);throw new RuntimeException('Sales role read the finance report.');}catch(DomainException){}
    $results[]='protected Vietnamese HTML/PDF and owner/role boundaries pass';

    update_option('erp_company_name','PRICE snapshot smoke company');
    $precisionProduct=$insert($db,$prefix.'erp_products',['sku'=>'PRECISION-01','name'=>'Sản phẩm làm tròn','category_id'=>$category,'unit'=>'phần','unit_step'=>'0.001','sale_price_vnd'=>1]);
    $precisionOrder=$insert($db,$prefix.'erp_sales_orders',['order_no'=>'SO-PRICE-1','customer_id'=>$customer,'warehouse_id'=>$warehouse,'created_by'=>500,'status'=>'SHIPPED','discount_bps'=>0,'tax_bps'=>0,'subtotal_exact_vnd'=>'1.000','rounding_adjustment_vnd'=>'0.000','subtotal_vnd'=>1,'discount_vnd'=>0,'tax_vnd'=>0,'total_vnd'=>1]);
    $precisionOrderItems=[];
    for($index=1;$index<=2;$index++)$precisionOrderItems[]=$insert($db,$prefix.'erp_sales_order_items',['order_id'=>$precisionOrder,'product_id'=>$precisionProduct,'sku_snapshot'=>'PRECISION-01','name_snapshot'=>'Sản phẩm làm tròn','unit_snapshot'=>'phần','unit_step_snapshot'=>'0.001','unit_price_vnd'=>1,'quantity'=>'0.500','line_subtotal_exact_vnd'=>'0.500','rounding_allocation_vnd'=>$index===1?'0.500':'-0.500','line_subtotal_vnd'=>$index===1?1:0,'discount_vnd'=>0,'tax_vnd'=>0,'line_total_vnd'=>$index===1?1:0]);
    $precisionShipment=$insert($db,$prefix.'erp_shipments',['shipment_no'=>'SHP-PRICE-1','sales_order_id'=>$precisionOrder,'warehouse_id'=>$warehouse,'shipped_by'=>500,'shipped_at'=>$occurred,'request_key'=>wp_generate_uuid4()]);
    foreach($precisionOrderItems as $itemId)$insert($db,$prefix.'erp_shipment_items',['shipment_id'=>$precisionShipment,'sales_item_id'=>$itemId,'product_id'=>$precisionProduct,'quantity'=>'0.500']);
    $invoiceService=new InvoiceService($db,new TransactionManager($db),new AuditLogger());
    $dueDate=(new DateTimeImmutable('now',$zone))->modify('+1 day')->format('Y-m-d');
    $precisionInvoice=$invoiceService->issueFromShipment($accountant,$precisionShipment,$dueDate);
    $precisionHeader=$db->get_row($db->prepare('SELECT pricing_algorithm_version,discount_bps,tax_bps,subtotal_exact_vnd,rounding_adjustment_vnd,subtotal_vnd,total_vnd FROM `'.$prefix.'erp_invoices` WHERE id=%d',$precisionInvoice),ARRAY_A);
    $precisionLines=$db->get_results($db->prepare('SELECT line_subtotal_exact_vnd,rounding_allocation_vnd,line_subtotal_vnd,line_total_vnd FROM `'.$prefix.'erp_invoice_items` WHERE invoice_id=%d ORDER BY id',$precisionInvoice),ARRAY_A);
    $assert(is_array($precisionHeader)&&$precisionHeader['pricing_algorithm_version']==='PRICE-1'&&(int)$precisionHeader['discount_bps']===0&&(int)$precisionHeader['tax_bps']===0&&is_array($precisionLines)&&count($precisionLines)===2,'Invoice issuance did not persist its pricing algorithm and basis-point rates.');
    $assert((int)$precisionHeader['subtotal_vnd']===1&&(int)$precisionHeader['total_vnd']===1&&bccomp((string)$precisionHeader['subtotal_exact_vnd'],'1.000',3)===0&&bccomp((string)$precisionLines[0]['rounding_allocation_vnd'],'0.500',3)===0&&bccomp((string)$precisionLines[1]['rounding_allocation_vnd'],'-0.500',3)===0&&(int)$precisionLines[0]['line_subtotal_vnd']===1&&(int)$precisionLines[1]['line_subtotal_vnd']===0,'PRICE-1 allocation was not frozen in the issued invoice lines.');
    $precisionModel=$documents->viewModel($sales,$precisionInvoice);$precisionHtml=$documents->html($precisionModel,false);
    $assert(str_contains($precisionHtml,'Phân bổ làm tròn')&&str_contains($precisionHtml,'0,500')&&str_contains($precisionHtml,'-0,500'),'Invoice HTML did not explain the persisted line-level rounding allocation.');
    $results[]='PRICE-1 invoice issuance persists exact lines, rounding allocations, and discount/tax rates';

    $legacyInvoice=$insert($db,$prefix.'erp_invoices',['invoice_no'=>'INV-LEGACY-PRICE','source_type'=>'GOODS_RECEIPT','source_id'=>999999999,'direction'=>'OUT','party_id'=>$customer,'seller_name'=>'Công ty thử nghiệm','seller_address'=>'','seller_tax_id'=>'','buyer_name'=>'Nhà cung cấp thử nghiệm','buyer_address'=>'','buyer_tax_id'=>'','subtotal_exact_vnd'=>'1.000','rounding_adjustment_vnd'=>'0.000','subtotal_vnd'=>1,'discount_vnd'=>0,'tax_vnd'=>0,'total_vnd'=>1,'pricing_algorithm_version'=>'PRICE-1','discount_bps'=>0,'tax_bps'=>0,'status'=>'VOID','issued_at'=>'2000-01-01 00:00:00.000000','due_at'=>'2000-01-01 23:59:59.999999','issued_by'=>500]);
    foreach([1,0] as $index=>$allocated)$insert($db,$prefix.'erp_invoice_items',['invoice_id'=>$legacyInvoice,'source_item_id'=>$index+1,'sku_snapshot'=>'LEGACY-PRICE','name_snapshot'=>'Dòng tiền lẻ','unit_snapshot'=>'phần','quantity'=>'0.500','unit_price_vnd'=>1,'line_subtotal_exact_vnd'=>'0.500','rounding_allocation_vnd'=>$allocated===1?'0.500':'-0.500','line_subtotal_vnd'=>$allocated,'discount_vnd'=>0,'tax_vnd'=>0,'line_total_vnd'=>$allocated]);
    if($db->query('ALTER TABLE `'.$prefix.'erp_invoice_items` DROP COLUMN `rounding_allocation_vnd`, DROP COLUMN `line_subtotal_vnd`')===false||$db->query('ALTER TABLE `'.$prefix.'erp_invoices` DROP COLUMN `pricing_algorithm_version`, DROP COLUMN `discount_bps`, DROP COLUMN `tax_bps`')===false)throw new RuntimeException('Could not prepare exact legacy invoice-schema fixture.');
    (new InvoiceSchema($db))->migrate();
    $legacyHeader=$db->get_row($db->prepare('SELECT pricing_algorithm_version,discount_bps,tax_bps FROM `'.$prefix.'erp_invoices` WHERE id=%d',$legacyInvoice),ARRAY_A);
    $legacyModel=$documents->viewModel($manager,$legacyInvoice);$legacyHtml=$documents->html($legacyModel,false);
    $assert(is_array($legacyHeader)&&$legacyHeader['pricing_algorithm_version']==='LEGACY'&&$legacyHeader['discount_bps']===null&&$legacyHeader['tax_bps']===null,'Legacy invoice migration invented pricing rates that were not in its original snapshot.');
    $assert((int)$legacyModel['lines'][0]['line_subtotal_vnd']===1&&(int)$legacyModel['lines'][1]['line_subtotal_vnd']===0&&bccomp((string)$legacyModel['lines'][0]['rounding_allocation_vnd'],'0.500',3)===0&&bccomp((string)$legacyModel['lines'][1]['rounding_allocation_vnd'],'-0.500',3)===0&&str_contains($legacyHtml,'-0,500'),'Legacy invoice migration did not reconstruct its saved amount allocation.');
    (new InvoiceSchema($db))->migrate();
    $legacyAgain=$documents->viewModel($manager,$legacyInvoice);
    $assert(bccomp((string)$legacyAgain['lines'][0]['rounding_allocation_vnd'],'0.500',3)===0&&bccomp((string)$legacyAgain['lines'][1]['rounding_allocation_vnd'],'-0.500',3)===0,'Repeated invoice schema migration changed a preserved legacy snapshot.');
    $results[]='Legacy invoice upgrade backfills only derivable allocations, leaves unknown rates blank, and is repeatable';
}catch(Throwable $error){$exit=1;$failure='Reports/documents smoke failed: '.get_class($error).' '.$error->getMessage();}
finally{
    foreach($dropOrder as $suffix){$table=$prefix.$suffix;if(!preg_match('/\Apaxg_test_reports_[a-f0-9]{10}_erp_[a-z_]+\z/D',$table)){$exit=1;continue;}if($db->query('DROP TABLE IF EXISTS `'.$table.'`')===false){$exit=1;$failure='Exact report fixture cleanup failed.';continue;}$left=$db->get_var($db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=%s AND TABLE_NAME=%s',DB_NAME,$table));if((string)$left!=='0'){$exit=1;$failure='Exact report fixture cleanup could not be verified.';}}
    if($oldCompanyName==='__MISSING__')delete_option('erp_company_name');else update_option('erp_company_name',$oldCompanyName);
    $db->close();
}
if($failure!==null)fwrite(STDERR,$failure."\n");foreach($results as $result)fwrite(STDOUT,'PASS: '.$result."\n");if($exit!==0)exit($exit);fwrite(STDOUT,"Report and invoice-document smoke passed; exact fixture tables cleaned.\n");
