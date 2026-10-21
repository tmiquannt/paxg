<?php
declare(strict_types=1);

namespace MiniERP\Admin\Controllers;

use DomainException;
use MiniERP\Application\Services\ReportExportService;
use MiniERP\Application\Services\ReportService;
use MiniERP\Infrastructure\Audit\AuditLogger;
use MiniERP\Infrastructure\CsvRenderer;
use MiniERP\Infrastructure\Database\TransactionManager;
use MiniERP\Security\Actor;
use MiniERP\Security\CapabilityPolicy;
use Throwable;
use wpdb;

/** Role-aware dashboard and read-only operational reports. */
final class ReportController
{
    public static function dashboard():void
    {
        CapabilityPolicy::requireCapability('erp_dashboard_view');$actor=self::actor();global $wpdb;
        if(!$wpdb instanceof wpdb)wp_die('Cơ sở dữ liệu không khả dụng.');
        $service=new ReportService($wpdb);$today=wp_date('Y-m-d');$monthStart=wp_date('Y-m-01');$from=$monthStart;$to=$today;
        echo '<div class="wrap erp-app"><header class="erp-page-header"><div><p class="erp-eyebrow">TỔNG QUAN VẬN HÀNH</p><h1>'.esc_html(UIStudioController::applicationName()).'</h1><p class="description">Số liệu tổng hợp từ chứng từ đã ghi sổ. Thông tin hiển thị theo vai trò và phạm vi được giao.</p>';
        $helpText = UIStudioController::helpText();
        if ($helpText !== '') echo '<p class="erp-dashboard-help">'.esc_html($helpText).'</p>';
        echo '</div><time>'.esc_html(wp_date('d/m/Y')).'</time></header>';
        echo '<div class="erp-report-grid">';
        if($actor->can('erp_reports_sales')&&UIStudioController::dashboardCardEnabled('sales_revenue')){try{$sales=$service->salesRevenue($actor,$from,$to);self::metric('Doanh thu hóa đơn tháng này',self::money($sales['total_vnd']).' ₫',$sales['invoice_count'].' hóa đơn đã phát hành','Đã phát hành không đồng nghĩa đã thu tiền.');}catch(DomainException){}catch(\Throwable){self::unavailable();}}
        if($actor->can('erp_reports_receivables')&&UIStudioController::dashboardCardEnabled('receivables')){try{$receivables=$service->receivables($actor,$today);$open=0;$overdue=0;$count=0;foreach($receivables['buckets'] as $bucket=>$summary){$open+=$summary['outstanding_vnd'];$count+=$summary['invoice_count'];if($bucket!=='not_due')$overdue+=$summary['outstanding_vnd'];}self::metric('Công nợ phải thu',self::money($open).' ₫',$count.' hóa đơn còn dư nợ','Trong đó quá hạn: '.self::money($overdue).' ₫');}catch(DomainException){}catch(\Throwable){self::unavailable();}}
        if($actor->can('erp_reports_finance')&&UIStudioController::dashboardCardEnabled('cash')){try{$cash=$service->cashBook($actor,$from,$to);if($cash['accounts']===[])self::metric('Sổ tiền','Chưa được gán quỹ','Quản trị ERP có thể phân quyền quỹ tiền mặt hoặc tài khoản ngân hàng.');else{$balance=0;foreach($cash['accounts'] as $account)$balance+=(int)$account['balance_vnd'];self::metric('Tiền cuối sổ được phân quyền',self::money($balance).' ₫','Thu tháng này: '.self::money($cash['in_vnd']).' ₫','Chi tháng này: '.self::money($cash['out_vnd']).' ₫');}}catch(DomainException){}catch(\Throwable){self::unavailable();}}
        if($actor->can('erp_stock_view')&&UIStudioController::dashboardCardEnabled('stock')){try{$stock=$service->stockOnHand($actor,1);$allWarehouses=$actor->can('erp_stock_adjust_approve');if(!$allWarehouses&&$actor->warehouseIds===[])self::metric('Tồn kho','Chưa được gán kho','Quản trị ERP cần phân quyền kho trước khi xem số liệu.');else self::metric('Mã hàng trong tồn kho',''.(int)$stock['product_count'],(int)$stock['total_rows'].' dòng tồn kho',$allWarehouses?'Tổng hợp từ tất cả kho.':'Chỉ gồm các kho đã được gán.');}catch(DomainException){}catch(\Throwable){self::unavailable();}}
        echo '</div><section class="erp-dashboard-links"><h2>Đi tới nghiệp vụ</h2><div class="erp-report-links">';
        self::linkIf($actor,'erp_sales_create','mini-erp-sales','Đơn bán hàng');self::linkIf($actor,'erp_purchases_view','mini-erp-purchases','Đơn mua và nhập hàng');self::linkIf($actor,'erp_stock_view','mini-erp-inventory','Kho hàng và kiểm kê');self::linkIf($actor,'erp_invoices_view','mini-erp-invoices','Hóa đơn nội bộ');self::linkIf($actor,'erp_reports_receivables','mini-erp-reports','Báo cáo');self::linkIf($actor,'erp_users_view','mini-erp-users','Người dùng ERP');self::linkIf($actor,'erp_settings_manage','mini-erp-company','Hồ sơ công ty');
        if($actor->can('erp_ui_studio_manage')&&current_user_can('erp_ui_studio_manage'))echo '<a class="button" href="'.esc_url(admin_url('admin.php?page=mini-erp-ui-studio')).'">ERP UI Studio</a> ';
        if(!$actor->can('erp_reports_sales')&&!$actor->can('erp_reports_receivables')&&!$actor->can('erp_reports_finance')&&!$actor->can('erp_stock_view'))echo '<p>Tài khoản có quyền quản trị cấu hình và truy cập nhật ký hệ thống. Hãy cấp vai trò nghiệp vụ riêng để xem dữ liệu vận hành.</p>';
        echo '</div></section></div>';
    }

    public static function render():void
    {
        CapabilityPolicy::requireCapability('erp_reports_receivables');$actor=self::actor();global $wpdb;if(!$wpdb instanceof wpdb)wp_die('Cơ sở dữ liệu không khả dụng.');$service=new ReportService($wpdb);
        $views=[];if($actor->can('erp_reports_receivables'))$views['receivables']='Công nợ / tuổi nợ';if($actor->can('erp_reports_finance'))$views['cash']='Sổ quỹ';if($actor->can('erp_reports_sales'))$views['revenue']='Doanh thu';if($actor->can('erp_reports_stock'))$views['stock']='Tồn kho';
        $view=isset($_GET['view'])&&is_scalar($_GET['view'])?sanitize_key((string)wp_unslash($_GET['view'])):'';if(!isset($views[$view]))$view=(string)array_key_first($views);
        [$from,$to]=self::period();$page=isset($_GET['erp_report_page'])?max(1,absint($_GET['erp_report_page'])):1;
        echo '<div class="wrap erp-app"><header class="erp-page-header"><div><p class="erp-eyebrow">BÁO CÁO VẬN HÀNH</p><h1>Báo cáo</h1><p class="description">Tổng hợp từ hóa đơn, giao dịch tiền và số dư tồn kho đã ghi sổ.</p></div></header><nav class="erp-report-tabs" aria-label="Các báo cáo">';
        foreach($views as $key=>$label){$url=add_query_arg(['page'=>'mini-erp-reports','view'=>$key],home_url('/'));echo '<a class="button '.($view===$key?'button-primary':''). '" href="'.esc_url($url).'">'.esc_html($label).'</a> ';}
        echo '</nav>';
        try{
            if($view==='receivables')self::renderReceivables($service,$actor,$page);
            elseif($view==='cash')self::renderCash($service,$actor,$from,$to,$page);
            elseif($view==='revenue')self::renderRevenue($service,$actor,$from,$to);
            elseif($view==='stock')self::renderStock($service,$actor,$page);
            else echo '<p>Chưa có báo cáo nào được phân quyền cho tài khoản này.</p>';
        }catch(DomainException){wp_die('Bạn không có quyền xem báo cáo này.','Mini ERP',['response'=>403]);}catch(\Throwable){self::unavailable();}
        echo '</div>';
    }

    public static function export(): never
    {
        $view = isset($_POST['view']) && is_scalar($_POST['view']) ? sanitize_key((string) wp_unslash($_POST['view'])) : '';
        $capabilities = [
            'receivables' => 'erp_reports_receivables',
            'cash' => 'erp_reports_finance',
            'revenue' => 'erp_reports_sales',
            'stock' => 'erp_reports_stock',
        ];
        if (!isset($capabilities[$view])) {
            wp_die(esc_html__('Báo cáo CSV không hợp lệ.', 'mini-erp'), 'Mini ERP', ['response' => 400]);
        }
        CapabilityPolicy::requireCapability($capabilities[$view]);
        check_admin_referer('mini_erp_report_export_' . $view);
        $actor = self::actor();
        global $wpdb;
        if (!$wpdb instanceof wpdb) {
            self::unavailable();
        }

        $defaultTo = wp_date('Y-m-d');
        $defaultFrom = wp_date('Y-m-01');
        $from = in_array($view, ['cash', 'revenue'], true) ? self::postDate('from') : $defaultFrom;
        $to = in_array($view, ['cash', 'revenue'], true) ? self::postDate('to') : $defaultTo;
        if (!self::validDate($from) || !self::validDate($to) || $to < $from) {
            wp_die(esc_html__('Khoảng ngày xuất báo cáo không hợp lệ.', 'mini-erp'), 'Mini ERP', ['response' => 400]);
        }

        $columns = self::exportColumns($view);
        try {
            $rows = (new ReportExportService($wpdb))->rows($actor, $view, $from, $to, $defaultTo);
            $csv = CsvRenderer::render($columns, self::formatExportRows($view, $rows));
            (new TransactionManager($wpdb))->run(static function (TransactionManager $transaction) use ($actor, $view): void {
                (new AuditLogger())->record($transaction, self::requestId(), $actor->id, 'report.exported.csv.' . $view, 'report', null, 'success');
            });
        } catch (DomainException $failure) {
            wp_die(esc_html($failure->getMessage()), 'Mini ERP', ['response' => 422]);
        } catch (Throwable) {
            self::unavailable();
        }

        nocache_headers();
        header('Cache-Control: private, no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="mini-erp-' . $view . '-' . $defaultTo . '.csv"');
        echo $csv;
        exit;
    }

    private static function renderReceivables(ReportService $service,Actor $actor,int $page):void
    {
        $today=wp_date('Y-m-d');$report=$service->receivables($actor,$today,$page);$labels=['not_due'=>'Chưa đến hạn','days_1_30'=>'Quá hạn 1–30 ngày','days_31_60'=>'Quá hạn 31–60 ngày','days_61_90'=>'Quá hạn 61–90 ngày','days_over_90'=>'Quá hạn trên 90 ngày'];
        echo '<h2>Công nợ phải thu theo tuổi nợ</h2><p>Ngày chốt: '.esc_html($today).'. Số phải thu trừ đi các khoản thu còn hiệu lực, kể cả phiếu đang chờ hoàn tất quy trình hủy.</p>';self::exportForm('receivables');echo '<div class="erp-report-grid">';
        foreach($labels as $key=>$label){$bucket=$report['buckets'][$key]??['invoice_count'=>0,'outstanding_vnd'=>0];self::metric($label,self::money($bucket['outstanding_vnd']).' ₫',(int)$bucket['invoice_count'].' hóa đơn');}echo '</div>';
        echo '<h3>Hóa đơn còn dư nợ</h3><p>Trang '.(int)$report['page'].' / '.(int)$report['page_count'].' · '.(int)$report['total_open'].' hóa đơn</p><div class="erp-table-scroll"><table class="widefat striped"><thead><tr><th>Số hóa đơn</th><th>Khách hàng</th><th>Ngày phát hành</th><th>Hạn thanh toán</th><th>Tổng hóa đơn</th><th>Đã thu</th><th>Còn nợ</th><th>Quá hạn</th></tr></thead><tbody>';
        foreach($report['rows'] as $row){$detail=add_query_arg(['page'=>'mini-erp-invoices','invoice'=>(int)$row['id']],home_url('/'));echo '<tr><td><a href="'.esc_url($detail).'">'.esc_html((string)$row['invoice_no']).'</a></td><td>'.esc_html((string)$row['buyer_name']).'</td><td>'.esc_html(self::localTime((string)$row['issued_at'])).'</td><td>'.esc_html((string)$row['due_date']).'</td><td>'.esc_html(self::money($row['total_vnd'])).' ₫</td><td>'.esc_html(self::money($row['effective_paid_vnd'])).' ₫</td><td>'.esc_html(self::money($row['outstanding_vnd'])).' ₫</td><td>'.((int)$row['days_overdue']>0?esc_html((string)$row['days_overdue'].' ngày'):'—').'</td></tr>';}
        if($report['rows']===[])echo '<tr><td colspan="8">Không có hóa đơn còn dư nợ trong phạm vi này.</td></tr>';echo '</tbody></table></div>';self::pagination('receivables',$report['page'],$report['page_count']);
    }

    private static function renderCash(ReportService $service,Actor $actor,string $from,string $to,int $page):void
    {
        $report=$service->cashBook($actor,$from,$to,$page);echo '<h2>Sổ quỹ tiền mặt và tài khoản ngân hàng</h2><p>Doanh thu hóa đơn được báo cáo riêng; số dưới đây là dòng tiền thực ghi vào sổ. Kỳ báo cáo: '.esc_html($from).' đến '.esc_html($to).' (Asia/Ho_Chi_Minh).</p>';self::dateFilter($from,$to,'cash');self::exportForm('cash',$from,$to);echo '<div class="erp-report-grid">';self::metric('Thực thu',self::money($report['in_vnd']).' ₫');self::metric('Thực chi',self::money($report['out_vnd']).' ₫');self::metric('Giao dịch',number_format($report['movement_count'],0,',','.'));echo '</div>';
        echo '<h3>Số dư hiện tại theo sổ quỹ</h3><div class="erp-table-scroll"><table class="widefat striped"><thead><tr><th>Mã</th><th>Tên</th><th>Loại</th><th>Số dư mở</th><th>Biến động tích lũy</th><th>Số dư hiện tại</th></tr></thead><tbody>';
        foreach($report['accounts'] as $account)echo '<tr><td>'.esc_html((string)$account['account_code']).'</td><td>'.esc_html((string)$account['name']).'</td><td>'.esc_html($account['type']==='BANK'?'Ngân hàng':'Tiền mặt').'</td><td>'.esc_html(self::money($account['opening_balance_vnd'])).' ₫</td><td>'.esc_html(self::money($account['movement_balance_vnd'])).' ₫</td><td>'.esc_html(self::money($account['balance_vnd'])).' ₫</td></tr>';
        if($report['accounts']===[])echo '<tr><td colspan="6">Chưa được gán quỹ tiền mặt hoặc tài khoản ngân hàng.</td></tr>';echo '</tbody></table></div><h3>Giao dịch trong kỳ</h3><p>Trang '.(int)$report['page'].' / '.(int)$report['page_count'].' · '.(int)$report['movement_count'].' dòng</p><div class="erp-table-scroll"><table class="widefat striped"><thead><tr><th>Ngày</th><th>Tài khoản</th><th>Loại</th><th>Chứng từ nguồn</th><th>Mã tham chiếu</th><th>Số tiền</th></tr></thead><tbody>';
        foreach($report['movements'] as $row)echo '<tr><td>'.esc_html(self::localTime((string)$row['occurred_at'])).'</td><td>'.esc_html((string)$row['account_code']).'</td><td>'.esc_html($row['direction']==='IN'?'Thu':'Chi').'</td><td>'.esc_html((string)$row['source_type'].' #'.(string)$row['source_id']).'</td><td>'.esc_html((string)$row['external_reference']).'</td><td>'.esc_html(self::money($row['amount_vnd'])).' ₫</td></tr>';
        if($report['movements']===[])echo '<tr><td colspan="6">Không có dòng tiền trong kỳ đã chọn.</td></tr>';echo '</tbody></table></div>';self::pagination('cash',$report['page'],$report['page_count'],['from'=>$from,'to'=>$to]);
    }

    private static function renderRevenue(ReportService $service,Actor $actor,string $from,string $to):void
    {
        $report=$service->salesRevenue($actor,$from,$to);echo '<h2>Doanh thu theo hóa đơn bán đã phát hành</h2><p>Doanh thu ghi nhận theo ngày phát hành hóa đơn; số liệu không đồng nghĩa với tiền đã thu. Chứng từ đã hủy không được tính.</p>';self::dateFilter($from,$to,'revenue');self::exportForm('revenue',$from,$to);echo '<div class="erp-report-grid">';self::metric('Tổng giá trị hóa đơn',self::money($report['total_vnd']).' ₫',$report['invoice_count'].' hóa đơn trong kỳ');echo '</div>';
    }

    private static function renderStock(ReportService $service,Actor $actor,int $page):void
    {
        $report=$service->stockOnHand($actor,$page);$allWarehouses=$actor->can('erp_stock_adjust_approve');$scopeText=$allWarehouses?'Tổng hợp tồn kho tại tất cả kho.':'Chỉ hiển thị các kho được gán cho người dùng.';echo '<h2>Tồn kho hiện tại</h2><p>'.esc_html($scopeText).' Đây là on-hand theo sổ; không phải số lượng khả dụng sau khi giữ chỗ.</p>';self::exportForm('stock');echo '<p>Trang '.(int)$report['page'].' / '.(int)$report['page_count'].' · '.(int)$report['total_rows'].' dòng tồn kho</p><div class="erp-table-scroll"><table class="widefat striped"><thead><tr><th>Kho</th><th>SKU</th><th>Sản phẩm</th><th>Đơn vị</th><th>Tồn on-hand</th><th>Cập nhật</th></tr></thead><tbody>';
        foreach($report['rows'] as $row)echo '<tr><td>'.esc_html((string)$row['warehouse_code'].' · '.(string)$row['warehouse_name']).'</td><td>'.esc_html((string)$row['sku']).'</td><td>'.esc_html((string)$row['product_name']).'</td><td>'.esc_html((string)$row['unit']).'</td><td>'.esc_html((string)$row['quantity']).'</td><td>'.esc_html(self::localTime((string)$row['updated_at'])).'</td></tr>';
        if($report['rows']===[])echo '<tr><td colspan="6">Không có tồn kho trong phạm vi được phân quyền.</td></tr>';echo '</tbody></table></div>';self::pagination('stock',$report['page'],$report['page_count']);
    }

    private static function dateFilter(string $from,string $to,string $view):void
    {
        echo '<form class="erp-report-date-filter" method="get"><input type="hidden" name="page" value="mini-erp-reports"><input type="hidden" name="view" value="'.esc_attr($view).'"><label>Từ ngày <input type="date" name="from" value="'.esc_attr($from).'" required></label> <label>Đến ngày <input type="date" name="to" value="'.esc_attr($to).'" required></label> <button class="button button-primary">Lọc</button></form>';
    }

    private static function exportForm(string $view, ?string $from = null, ?string $to = null): void
    {
        echo '<form class="erp-report-export" method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="mini_erp_report_export"><input type="hidden" name="view" value="' . esc_attr($view) . '">';
        if ($from !== null && $to !== null) {
            echo '<input type="hidden" name="from" value="' . esc_attr($from) . '"><input type="hidden" name="to" value="' . esc_attr($to) . '">';
        }
        wp_nonce_field('mini_erp_report_export_' . $view);
        echo '<button class="button" type="submit">Tải CSV</button> <span class="description">Tối đa 5.000 dòng; dữ liệu theo phạm vi được phân quyền.</span></form>';
    }

    /** @return array<string, string> */
    private static function exportColumns(string $view): array
    {
        return match ($view) {
            'receivables' => ['invoice_no' => 'Số hóa đơn', 'buyer_name' => 'Khách hàng', 'issued_at' => 'Ngày phát hành', 'due_date' => 'Hạn thanh toán', 'total_vnd' => 'Tổng hóa đơn (VND)', 'effective_paid_vnd' => 'Đã thu hiệu lực (VND)', 'outstanding_vnd' => 'Còn nợ (VND)', 'days_overdue' => 'Số ngày quá hạn'],
            'cash' => ['occurred_at' => 'Ngày giao dịch', 'account_code' => 'Mã tài khoản', 'account_name' => 'Tài khoản', 'direction' => 'Chiều', 'source_type' => 'Loại chứng từ', 'source_id' => 'Mã nguồn', 'external_reference' => 'Tham chiếu', 'amount_vnd' => 'Số tiền (VND)'],
            'revenue' => ['invoice_no' => 'Số hóa đơn', 'buyer_name' => 'Khách hàng', 'issued_at' => 'Ngày phát hành', 'due_at' => 'Hạn thanh toán', 'total_vnd' => 'Tổng hóa đơn (VND)'],
            'stock' => ['warehouse_code' => 'Mã kho', 'warehouse_name' => 'Kho', 'sku' => 'SKU', 'product_name' => 'Sản phẩm', 'unit' => 'Đơn vị', 'quantity' => 'Tồn on-hand', 'updated_at' => 'Cập nhật'],
            default => [],
        };
    }

    /** @param list<array<string, mixed>> $rows @return list<array<string, mixed>> */
    private static function formatExportRows(string $view, array $rows): array
    {
        foreach ($rows as &$row) {
            foreach (['issued_at', 'due_at', 'occurred_at', 'updated_at'] as $dateField) {
                if (isset($row[$dateField]) && is_string($row[$dateField])) {
                    $row[$dateField] = self::localTime($row[$dateField]);
                }
            }
            if ($view === 'receivables') {
                $row['total_vnd'] = (int) $row['total_vnd'];
                $row['effective_paid_vnd'] = (int) $row['effective_paid_vnd'];
                $row['outstanding_vnd'] = (int) $row['outstanding_vnd'];
                $row['days_overdue'] = (int) $row['days_overdue'];
            } elseif (in_array($view, ['cash', 'revenue'], true)) {
                $row['amount_vnd'] = isset($row['amount_vnd']) ? (int) $row['amount_vnd'] : ($row['amount_vnd'] ?? null);
                $row['total_vnd'] = isset($row['total_vnd']) ? (int) $row['total_vnd'] : ($row['total_vnd'] ?? null);
                $row['source_id'] = isset($row['source_id']) ? (int) $row['source_id'] : ($row['source_id'] ?? null);
            }
        }
        unset($row);
        return $rows;
    }

    private static function postDate(string $field): string
    {
        return isset($_POST[$field]) && is_scalar($_POST[$field]) ? sanitize_text_field((string) wp_unslash($_POST[$field])) : '';
    }

    private static function validDate(string $date): bool
    {
        if (!preg_match('/\A(\d{4})-(\d{2})-(\d{2})\z/D', $date, $parts)) {
            return false;
        }
        return checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }

    private static function pagination(string $view,int $page,int $pageCount,array $query=[]):void
    {
        if($pageCount<=1)return;$base=['page'=>'mini-erp-reports','view'=>$view,...$query];echo '<nav class="erp-pagination" aria-label="Phân trang">';if($page>1)echo '<a class="button" href="'.esc_url(add_query_arg([...$base,'erp_report_page'=>$page-1],home_url('/'))).'">Trang trước</a> ';if($page<$pageCount)echo '<a class="button" href="'.esc_url(add_query_arg([...$base,'erp_report_page'=>$page+1],home_url('/'))).'">Trang sau</a>';echo '</nav>';
    }

    private static function metric(string $title,string $value,string $support='',string $detail=''):void
    {
        echo '<article class="erp-report-card"><h3>'.esc_html($title).'</h3><strong>'.esc_html($value).'</strong>';if($support!=='')echo '<p>'.esc_html($support).'</p>';if($detail!=='')echo '<small>'.esc_html($detail).'</small>';echo '</article>';
    }

    private static function linkIf(Actor $actor,string $capability,string $page,string $label):void
    {
        if($actor->can($capability))echo '<a class="button" href="'.esc_url(add_query_arg(['page'=>$page],home_url('/'))).'">'.esc_html(UIStudioController::pageLabel($page,$label)).'</a> ';
    }

    private static function period():array
    {
        $today=wp_date('Y-m-d');$defaultFrom=wp_date('Y-m-01');$from=isset($_GET['from'])&&is_scalar($_GET['from'])?sanitize_text_field((string)wp_unslash($_GET['from'])):$defaultFrom;$to=isset($_GET['to'])&&is_scalar($_GET['to'])?sanitize_text_field((string)wp_unslash($_GET['to'])):$today;
        if(!preg_match('/\A\d{4}-(\d{2})-(\d{2})\z/D',$from,$fromParts)||!checkdate((int)$fromParts[1],(int)$fromParts[2],(int)substr($from,0,4))||!preg_match('/\A\d{4}-(\d{2})-(\d{2})\z/D',$to,$toParts)||!checkdate((int)$toParts[1],(int)$toParts[2],(int)substr($to,0,4))||$to<$from){$from=$defaultFrom;$to=$today;}return [$from,$to];
    }

    private static function actor():Actor{return CapabilityPolicy::currentActor()??throw new DomainException('Phiên ERP không hợp lệ.');}
    private static function requestId():string{return function_exists('wp_generate_uuid4')?wp_generate_uuid4():sprintf('%08x-%04x-4%03x-%04x-%012x',random_int(0,0xffffffff),random_int(0,0xffff),random_int(0,0xfff),random_int(0,0x3fff)|0x8000,random_int(0,0xffffffffffff));}
    private static function money(mixed $amount):string{return number_format((int)$amount,0,',','.');}
    private static function localTime(string $utc):string{$timestamp=strtotime($utc.' UTC');return $timestamp===false?$utc:wp_date('Y-m-d H:i',$timestamp,new \DateTimeZone('Asia/Ho_Chi_Minh'));}
    private static function unavailable():never{wp_die('Không thể tải báo cáo lúc này. Hãy thử lại sau.','Mini ERP',['response'=>503]);}
}
