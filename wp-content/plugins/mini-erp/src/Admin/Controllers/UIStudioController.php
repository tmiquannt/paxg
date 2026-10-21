<?php
declare(strict_types=1);

namespace MiniERP\Admin\Controllers;

use MiniERP\Frontend\AppRouter;
use MiniERP\Infrastructure\Audit\AuditLogger;
use MiniERP\Infrastructure\Database\TransactionManager;
use MiniERP\Security\CapabilityPolicy;
use MiniERP\Security\Actor;
use Throwable;
use wpdb;

/** Bounded, presentation-only settings for the ERP application shell. */
final class UIStudioController
{
    private const OPTION = 'mini_erp_ui_studio';

    /** @var array<string, array{label:string, capability:string}> */
    private const DASHBOARD_CARDS = [
        'sales_revenue' => ['label' => 'Doanh thu hóa đơn', 'capability' => 'erp_reports_sales'],
        'receivables' => ['label' => 'Công nợ phải thu', 'capability' => 'erp_reports_receivables'],
        'cash' => ['label' => 'Số dư quỹ được phân quyền', 'capability' => 'erp_reports_finance'],
        'stock' => ['label' => 'Tồn kho', 'capability' => 'erp_stock_view'],
    ];

    /** @var array<string,mixed>|null */
    private static ?array $settingsCache = null;

    /** @return array<string, array{label:string, capability:string}> */
    public static function dashboardCardDefinitions(): array
    {
        return self::DASHBOARD_CARDS;
    }

    /** @return array{application_name:string,help_text:string,labels:array<string,string>,navigation_order:array<string,int>,dashboard_cards:list<string>} */
    public static function settings(): array
    {
        if (self::$settingsCache !== null) {
            return self::$settingsCache;
        }

        $raw = get_option(self::OPTION, []);
        $raw = is_array($raw) ? $raw : [];
        $rawLabels = isset($raw['labels']) && is_array($raw['labels']) ? $raw['labels'] : [];
        $rawOrder = isset($raw['navigation_order']) && is_array($raw['navigation_order']) ? $raw['navigation_order'] : [];
        $pageLabels = AppRouter::defaultPageLabels();
        $labels = [];
        $order = [];
        $position = 1;
        foreach ($pageLabels as $slug => $_defaultLabel) {
            $label = $rawLabels[$slug] ?? '';
            $labels[$slug] = is_string($label) ? self::boundedText($label, 80) : '';
            $rawPosition = $rawOrder[$slug] ?? $position;
            $order[$slug] = is_scalar($rawPosition) && filter_var($rawPosition, FILTER_VALIDATE_INT) !== false
                ? max(1, min(count($pageLabels), (int) $rawPosition))
                : $position;
            ++$position;
        }

        $allCards = array_keys(self::DASHBOARD_CARDS);
        $cards = array_key_exists('dashboard_cards', $raw) && is_array($raw['dashboard_cards'])
            ? array_values(array_intersect($allCards, self::normalizeCardKeys($raw['dashboard_cards'])))
            : $allCards;

        self::$settingsCache = [
            'application_name' => isset($raw['application_name']) && is_string($raw['application_name'])
                ? (self::boundedText($raw['application_name'], 64) ?: 'Mini ERP')
                : 'Mini ERP',
            'help_text' => isset($raw['help_text']) && is_string($raw['help_text']) ? self::boundedText($raw['help_text'], 500) : '',
            'labels' => $labels,
            'navigation_order' => $order,
            'dashboard_cards' => array_values(array_unique($cards)),
        ];
        return self::$settingsCache;
    }

    public static function applicationName(): string
    {
        return self::settings()['application_name'];
    }

    public static function helpText(): string
    {
        return self::settings()['help_text'];
    }

    public static function pageLabel(string $slug, string $default): string
    {
        $custom = self::settings()['labels'][$slug] ?? '';
        return $custom !== '' ? $custom : $default;
    }

    /** @param list<string> $slugs @return list<string> */
    public static function orderNavigation(array $slugs): array
    {
        $settings = self::settings()['navigation_order'];
        $original = array_flip($slugs);
        usort($slugs, static function (string $left, string $right) use ($settings, $original): int {
            $leftPosition = $settings[$left] ?? (($original[$left] ?? 0) + 1);
            $rightPosition = $settings[$right] ?? (($original[$right] ?? 0) + 1);
            return ($leftPosition <=> $rightPosition) ?: (($original[$left] ?? 0) <=> ($original[$right] ?? 0));
        });
        return $slugs;
    }

    public static function dashboardCardEnabled(string $key): bool
    {
        return in_array($key, self::settings()['dashboard_cards'], true);
    }

    public static function render(): void
    {
        CapabilityPolicy::requireCapability('erp_ui_studio_manage');
        $settings = self::settings();
        $pageLabels = AppRouter::defaultPageLabels();
        echo '<div class="wrap erp-app erp-ui-studio"><h1>' . esc_html__('ERP UI Studio', 'mini-erp') . '</h1>';
        echo '<p class="erp-ui-studio-intro">' . esc_html__('Tùy chỉnh cách ERP trình bày tên sản phẩm, lối tắt và nội dung hướng dẫn. Quyền truy cập và các luật nghiệp vụ vẫn do hệ thống role kiểm soát.', 'mini-erp') . '</p>';
        if (isset($_GET['erp_ui_studio_saved'])) {
            echo '<div class="notice notice-success"><p>' . esc_html__('Đã lưu tùy chỉnh giao diện.', 'mini-erp') . '</p></div>';
        } elseif (isset($_GET['erp_ui_studio_error'])) {
            echo '<div class="notice notice-error"><p>' . esc_html__('Không thể lưu tùy chỉnh. Hãy thử lại sau.', 'mini-erp') . '</p></div>';
        }

        echo '<form class="erp-ui-studio-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('mini_erp_ui_studio_save');
        echo '<input type="hidden" name="action" value="mini_erp_ui_studio_save">';
        echo '<section class="erp-ui-studio-section"><h2>' . esc_html__('Tên ứng dụng và hướng dẫn', 'mini-erp') . '</h2><div class="erp-ui-studio-fields">';
        echo '<label>' . esc_html__('Tên hiển thị', 'mini-erp') . '<input name="application_name" maxlength="64" value="' . esc_attr($settings['application_name']) . '"></label>';
        echo '<label>' . esc_html__('Ghi chú chung trên Tổng quan', 'mini-erp') . '<textarea name="help_text" maxlength="500" rows="3">' . esc_textarea($settings['help_text']) . '</textarea></label>';
        echo '</div><p class="description">' . esc_html__('Chỉ văn bản thuần được lưu; HTML, CSS và mã lệnh không được thực thi.', 'mini-erp') . '</p></section>';

        echo '<section class="erp-ui-studio-section"><h2>' . esc_html__('Tên và thứ tự lối tắt', 'mini-erp') . '</h2><p class="description">' . esc_html__('Tên hiển thị ở menu và tiêu đề trang; thứ tự menu được sắp xếp bên trong từng nhóm. Màn hình vẫn chỉ xuất hiện với role có quyền.', 'mini-erp') . '</p><div class="erp-ui-studio-list">';
        foreach ($pageLabels as $slug => $defaultLabel) {
            $customLabel = $settings['labels'][$slug];
            $currentLabel = $customLabel !== '' ? $customLabel : $defaultLabel;
            $slugLabel = str_replace('mini-erp-', '', $slug);
            echo '<div class="erp-ui-studio-row"><label for="erp-ui-label-' . esc_attr($slug) . '">' . esc_html($defaultLabel) . '<input id="erp-ui-label-' . esc_attr($slug) . '" name="labels[' . esc_attr($slug) . ']" maxlength="80" value="' . esc_attr($customLabel) . '" placeholder="' . esc_attr($defaultLabel) . '"><small>' . esc_html($slugLabel) . '</small></label>';
            echo '<label class="erp-ui-studio-order" for="erp-ui-order-' . esc_attr($slug) . '">' . esc_html__('Thứ tự', 'mini-erp') . '<input id="erp-ui-order-' . esc_attr($slug) . '" name="navigation_order[' . esc_attr($slug) . ']" type="number" min="1" max="' . count($pageLabels) . '" value="' . (int) $settings['navigation_order'][$slug] . '" aria-label="' . esc_attr(sprintf(__('Thứ tự menu cho %s', 'mini-erp'), $currentLabel)) . '"></label></div>';
        }
        echo '</div></section>';

        echo '<section class="erp-ui-studio-section"><h2>' . esc_html__('Thẻ trên Tổng quan', 'mini-erp') . '</h2><p class="description">' . esc_html__('Bỏ chọn để ẩn thẻ khỏi Tổng quan. Người dùng chỉ thấy số liệu nếu role của họ cũng có capability tương ứng.', 'mini-erp') . '</p><div class="erp-ui-studio-cards">';
        foreach (self::DASHBOARD_CARDS as $key => $card) {
            $checked = in_array($key, $settings['dashboard_cards'], true);
            echo '<label><input type="checkbox" name="dashboard_cards[]" value="' . esc_attr($key) . '" ' . checked($checked, true, false) . '> <span>' . esc_html($card['label']) . '<small>' . esc_html($card['capability']) . '</small></span></label>';
        }
        echo '</div></section><div class="erp-ui-studio-actions"><button class="button button-primary" type="submit">' . esc_html__('Lưu tùy chỉnh', 'mini-erp') . '</button><button class="button" type="submit" name="reset_defaults" value="1">' . esc_html__('Khôi phục mặc định', 'mini-erp') . '</button></div></form>';
        echo '<p class="description erp-ui-studio-boundary">' . esc_html__('Studio không thể tạo role/capability, đổi phạm vi kho/quỹ, ẩn trường bắt buộc, hoặc sửa trạng thái, giá trị và quy tắc ghi sổ.', 'mini-erp') . '</p></div>';
    }

    public static function save(): never
    {
        CapabilityPolicy::requireCapability('erp_ui_studio_manage');
        check_admin_referer('mini_erp_ui_studio_save');
        $actor = CapabilityPolicy::currentActor();
        if (!$actor instanceof Actor || !$actor->can('erp_ui_studio_manage')) {
            wp_die(esc_html__('Bạn không có quyền tùy chỉnh giao diện ERP.', 'mini-erp'), 'Mini ERP', ['response' => 403]);
        }

        $before = self::settings();
        $settings = isset($_POST['reset_defaults'])
            ? self::defaultSettings()
            : self::normalizePostedSettings(wp_unslash($_POST));
        global $wpdb;
        if (!$wpdb instanceof wpdb) {
            self::redirect(false);
        }

        try {
            (new TransactionManager($wpdb))->run(static function (TransactionManager $transaction) use ($settings, $before, $actor): void {
                update_option(self::OPTION, $settings, false);
                if (get_option(self::OPTION, null) !== $settings) {
                    throw new \RuntimeException('ERP UI settings did not persist.');
                }
                (new AuditLogger())->record(
                    $transaction,
                    self::requestId(),
                    $actor->id,
                    'ui.studio.updated',
                    'ui_settings',
                    null,
                    'success',
                    ['name' => $before['application_name']],
                    ['name' => $settings['application_name'], 'status' => 'SAVED']
                );
            });
            self::$settingsCache = $settings;
        } catch (Throwable) {
            self::redirect(false);
        }
        self::redirect(true);
    }

    /** @return array{application_name:string,help_text:string,labels:array<string,string>,navigation_order:array<string,int>,dashboard_cards:list<string>} */
    private static function normalizePostedSettings(array $input): array
    {
        $labelsInput = isset($input['labels']) && is_array($input['labels']) ? $input['labels'] : [];
        $orderInput = isset($input['navigation_order']) && is_array($input['navigation_order']) ? $input['navigation_order'] : [];
        $cardsInput = isset($input['dashboard_cards']) && is_array($input['dashboard_cards']) ? $input['dashboard_cards'] : [];
        $labels = [];
        $order = [];
        $position = 1;
        foreach (array_keys(AppRouter::defaultPageLabels()) as $slug) {
            $label = isset($labelsInput[$slug]) && is_string($labelsInput[$slug]) ? $labelsInput[$slug] : '';
            $labels[$slug] = self::boundedText($label, 80);
            $submittedPosition = $orderInput[$slug] ?? $position;
            $validPosition = is_scalar($submittedPosition) ? filter_var((string) $submittedPosition, FILTER_VALIDATE_INT) : false;
            $order[$slug] = $validPosition === false ? $position : max(1, min(count(AppRouter::defaultPageLabels()), (int) $validPosition));
            ++$position;
        }
        $allowedCards = array_keys(self::DASHBOARD_CARDS);
        $cards = array_values(array_intersect($allowedCards, self::normalizeCardKeys($cardsInput)));

        $applicationName = isset($input['application_name']) && is_string($input['application_name'])
            ? self::boundedText($input['application_name'], 64)
            : 'Mini ERP';
        $helpText = isset($input['help_text']) && is_string($input['help_text'])
            ? self::boundedText($input['help_text'], 500, true)
            : '';

        return [
            'application_name' => $applicationName !== '' ? $applicationName : 'Mini ERP',
            'help_text' => $helpText,
            'labels' => $labels,
            'navigation_order' => $order,
            'dashboard_cards' => array_values(array_unique($cards)),
        ];
    }

    /** @return array{application_name:string,help_text:string,labels:array<string,string>,navigation_order:array<string,int>,dashboard_cards:list<string>} */
    private static function defaultSettings(): array
    {
        $order = [];
        $position = 1;
        foreach (AppRouter::defaultPageLabels() as $slug => $_label) {
            $order[$slug] = $position++;
        }
        return [
            'application_name' => 'Mini ERP',
            'help_text' => '',
            'labels' => array_fill_keys(array_keys(AppRouter::defaultPageLabels()), ''),
            'navigation_order' => $order,
            'dashboard_cards' => array_keys(self::DASHBOARD_CARDS),
        ];
    }

    private static function boundedText(string $text, int $maxLength, bool $multiline = false): string
    {
        $text = $multiline ? sanitize_textarea_field($text) : sanitize_text_field($text);
        if (function_exists('mb_substr')) {
            return trim(mb_substr($text, 0, $maxLength));
        }
        return trim(substr($text, 0, $maxLength));
    }

    /** @param array<mixed> $values @return list<string> */
    private static function normalizeCardKeys(array $values): array
    {
        $keys = [];
        foreach ($values as $value) {
            if (is_string($value)) {
                $keys[] = sanitize_key($value);
            }
        }
        return array_values(array_unique($keys));
    }

    private static function requestId(): string
    {
        return function_exists('wp_generate_uuid4')
            ? wp_generate_uuid4()
            : sprintf('%08x-%04x-4%03x-%04x-%012x', random_int(0, 0xffffffff), random_int(0, 0xffff), random_int(0, 0xfff), random_int(0, 0x3fff) | 0x8000, random_int(0, 0xffffffffffff));
    }

    private static function redirect(bool $saved): never
    {
        $url = add_query_arg($saved ? 'erp_ui_studio_saved' : 'erp_ui_studio_error', '1', admin_url('admin.php?page=mini-erp-ui-studio'));
        wp_safe_redirect($url, 303);
        exit;
    }
}
