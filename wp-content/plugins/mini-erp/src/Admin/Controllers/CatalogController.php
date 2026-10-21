<?php
declare(strict_types=1);

namespace MiniERP\Admin\Controllers;

use DomainException;
use MiniERP\Application\Services\CatalogService;
use MiniERP\Infrastructure\Audit\AuditLogger;
use MiniERP\Infrastructure\Database\DatabaseException;
use MiniERP\Infrastructure\Database\TransactionManager;
use MiniERP\Security\Actor;
use MiniERP\Security\CapabilityPolicy;
use RuntimeException;
use Throwable;
use wpdb;

/** WordPress admin UI and nonce-protected actions for categories and products. */
final class CatalogController
{
    private const PAGE_SIZE = 50;

    public static function saveCategory(): void
    {
        CapabilityPolicy::requireCapability('erp_products_manage');
        check_admin_referer('mini_erp_category_save');
        global $wpdb;
        if (!$wpdb instanceof wpdb) {
            self::redirectWithRecovery('unavailable', 'category', null);
        }

        $code = strtoupper(sanitize_text_field(self::requestText($_POST, 'code')));
        $name = sanitize_text_field(self::requestText($_POST, 'name'));
        if (!preg_match('/\A[A-Z0-9][A-Z0-9._-]{0,63}\z/D', $code) || self::textLength($name) < 1 || self::textLength($name) > 191) {
            self::redirectWithRecovery('invalid', 'category', null);
        }

        try {
            self::service($wpdb)->createCategory(self::actor(), $code, $name);
            self::redirectWithRecovery('saved', 'category', null);
        } catch (DatabaseException $failure) {
            self::redirectWithRecovery($failure->getCode() === 1062 ? 'duplicate' : 'unavailable', 'category', null);
        } catch (Throwable) {
            self::redirectWithRecovery('unavailable', 'category', null);
        }
    }

    public static function saveProduct(): void
    {
        CapabilityPolicy::requireCapability('erp_products_manage');
        check_admin_referer('mini_erp_product_save');
        global $wpdb;
        if (!$wpdb instanceof wpdb) {
            $id = self::postId('id');
            self::redirectWithRecovery('unavailable', 'product', $id);
        }

        $id = self::postId('id');
        if (array_key_exists('id', $_POST) && $id === null) {
            self::redirect('invalid');
        }
        $values = self::productInput($wpdb, $id);
        if ($values === null) {
            self::redirectWithRecovery('invalid', 'product', $id);
        }
        $expectedUpdatedAt = isset($_POST['updated_at']) ? sanitize_text_field(self::requestText($_POST, 'updated_at')) : null;

        try {
            self::service($wpdb)->saveProduct(self::actor(), $values, $id, $expectedUpdatedAt);
            self::redirectWithRecovery('saved', 'product', $id);
        } catch (DatabaseException $failure) {
            self::redirectWithRecovery($failure->getCode() === 1062 ? 'duplicate' : 'unavailable', 'product', $id);
        } catch (DomainException $failure) {
            self::redirectWithRecovery(str_contains($failure->getMessage(), 'cập nhật ở nơi khác') ? 'conflict' : 'invalid', 'product', $id);
        } catch (Throwable) {
            self::redirectWithRecovery('unavailable', 'product', $id);
        }
    }

    public static function setProductActive(): void
    {
        self::setActive('product');
    }

    public static function setCategoryActive(): void
    {
        self::setActive('category');
    }

    private static function setActive(string $entity): void
    {
        CapabilityPolicy::requireCapability('erp_products_manage');
        check_admin_referer('mini_erp_' . $entity . '_status');
        global $wpdb;
        if (!$wpdb instanceof wpdb) {
            self::redirect('unavailable');
        }
        $id = self::postId('id');
        $active = self::requestText($_POST, 'active') === '1';
        $updatedAt = sanitize_text_field(self::requestText($_POST, 'updated_at'));
        if ($id === null) {
            self::redirect('invalid');
        }

        try {
            $service = self::service($wpdb);
            if ($entity === 'product') {
                $service->setProductActive(self::actor(), $id, $active, $updatedAt);
            } else {
                $service->setCategoryActive(self::actor(), $id, $active, $updatedAt);
            }
            self::redirect('saved');
        } catch (DatabaseException $failure) {
            self::redirect($failure->getCode() === 1062 ? 'duplicate' : 'unavailable');
        } catch (Throwable) {
            self::redirect('conflict');
        }
    }

    public static function render(): void
    {
        CapabilityPolicy::requireCapability('erp_products_view');
        $actor = self::actor();
        global $wpdb;
        if (!$wpdb instanceof wpdb) {
            wp_die(esc_html__('Cơ sở dữ liệu ERP chưa sẵn sàng.', 'mini-erp'), '', ['response' => 503]);
        }

        $categoryTable = self::table($wpdb, 'erp_categories');
        $productTable = self::table($wpdb, 'erp_products');
        $notice = self::notice();
        $canManage = $actor->can('erp_products_manage');
        $showPrice = $canManage || $actor->can('erp_sales_create');
        $search = isset($_GET['q']) ? mb_substr(sanitize_text_field(self::requestText($_GET, 'q')), 0, 100) : '';
        $page = self::queryPage('product_page');
        $offset = ($page - 1) * self::PAGE_SIZE;
        $whereParts = [];
        $args = [];
        if (!$canManage) {
            $whereParts[] = 'p.is_active = 1';
        }
        if ($search !== '') {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $whereParts[] = '(p.sku LIKE %s OR p.name LIKE %s)';
            $args = [$like, $like];
        }
        $where = $whereParts === [] ? '' : ' WHERE ' . implode(' AND ', $whereParts);
        $countSql = "SELECT COUNT(*) FROM {$productTable} p{$where}";
        $total = (int) ($args === [] ? $wpdb->get_var($countSql) : $wpdb->get_var($wpdb->prepare($countSql, $args)));
        $totalPages = max(1, (int) ceil($total / self::PAGE_SIZE));
        $page = min($page, $totalPages);
        $offset = ($page - 1) * self::PAGE_SIZE;
        $priceColumn = $showPrice ? ', p.sale_price_vnd' : '';
        $listSql = "SELECT p.id, p.sku, p.name, p.category_id, p.unit, p.unit_step, p.is_active, p.updated_at, c.name AS category_name{$priceColumn} FROM {$productTable} p JOIN {$categoryTable} c ON c.id = p.category_id{$where} ORDER BY p.name ASC, p.id ASC LIMIT %d OFFSET %d";
        $listArgs = [...$args, self::PAGE_SIZE, $offset];
        $products = $wpdb->get_results($wpdb->prepare($listSql, $listArgs), ARRAY_A);

        $categoryPage = self::queryPage('category_page');
        $categoryTotal = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$categoryTable}");
        $categoryPages = max(1, (int) ceil($categoryTotal / self::PAGE_SIZE));
        $categoryPage = min($categoryPage, $categoryPages);
        $categories = $wpdb->get_results($wpdb->prepare(
            "SELECT id, code, name, is_active, updated_at FROM {$categoryTable} ORDER BY name ASC, id ASC LIMIT %d OFFSET %d",
            self::PAGE_SIZE,
            ($categoryPage - 1) * self::PAGE_SIZE
        ), ARRAY_A);

        $editing = null;
        $editId = self::queryId('edit');
        if ($editId !== null && $canManage) {
            $editing = $wpdb->get_row($wpdb->prepare(
                "SELECT id, sku, name, category_id, unit, unit_step, sale_price_vnd, updated_at FROM {$productTable} WHERE id = %d",
                $editId
            ), ARRAY_A);
        }
        $formCategories = $editing
            ? $wpdb->get_results($wpdb->prepare("SELECT id, code, name, is_active FROM {$categoryTable} WHERE is_active = 1 OR id = %d ORDER BY name ASC", (int) $editing['category_id']), ARRAY_A)
            : $wpdb->get_results("SELECT id, code, name, is_active FROM {$categoryTable} WHERE is_active = 1 ORDER BY name ASC", ARRAY_A);

        echo '<div class="wrap erp-app"><h1>' . esc_html__('Danh mục và sản phẩm', 'mini-erp') . '</h1>';
        if ($notice !== '') {
            $success = $notice === 'saved';
            echo '<div class="notice ' . ($success ? 'notice-success' : 'notice-error') . ' is-dismissible"><p>' . esc_html(self::noticeText($notice)) . '</p></div>';
        }

        echo '<h2>' . esc_html__('Sản phẩm', 'mini-erp') . '</h2>';
        echo '<form class="erp-table-filters" method="get" action="' . esc_url(home_url('/')) . '"><input type="hidden" name="page" value="mini-erp-products"><label for="erp-product-search">' . esc_html__('Tìm SKU hoặc tên', 'mini-erp') . ' <input id="erp-product-search" type="search" name="q" maxlength="100" value="' . esc_attr($search) . '"></label><button class="button">' . esc_html__('Tìm', 'mini-erp') . '</button></form>';
        echo '<div class="erp-table-scroll erp-table-stack"><table class="widefat striped"><thead><tr><th>' . esc_html__('SKU', 'mini-erp') . '</th><th>' . esc_html__('Sản phẩm', 'mini-erp') . '</th><th>' . esc_html__('Danh mục', 'mini-erp') . '</th><th>' . esc_html__('Đơn vị', 'mini-erp') . '</th>';
        if ($showPrice) {
            echo '<th>' . esc_html__('Giá bán (VND)', 'mini-erp') . '</th>';
        }
        echo '<th>' . esc_html__('Trạng thái', 'mini-erp') . '</th>' . ($canManage ? '<th>' . esc_html__('Thao tác', 'mini-erp') . '</th>' : '') . '</tr></thead><tbody>';
        foreach (is_array($products) ? $products : [] as $product) {
            echo '<tr><td>' . esc_html((string) $product['sku']) . '</td><td>' . esc_html((string) $product['name']) . '</td><td>' . esc_html((string) $product['category_name']) . '</td><td>' . esc_html((string) $product['unit']) . ' <span class="description">(' . esc_html((string) $product['unit_step']) . ')</span></td>';
            if ($showPrice) {
                echo '<td>' . esc_html(number_format((int) $product['sale_price_vnd'], 0, ',', '.')) . '</td>';
            }
            echo '<td>' . esc_html((int) $product['is_active'] === 1 ? __('Đang dùng', 'mini-erp') : __('Ngừng dùng', 'mini-erp')) . '</td>';
            if ($canManage) {
                $editUrl = add_query_arg(['page' => 'mini-erp-products', 'edit' => (int) $product['id']], home_url('/'));
                echo '<td><a href="' . esc_url($editUrl) . '">' . esc_html__('Sửa', 'mini-erp') . '</a> · ';
                self::statusForm('product', (int) $product['id'], (string) $product['updated_at'], (int) $product['is_active'] === 1);
                echo '</td>';
            }
            echo '</tr>';
        }
        if (!is_array($products) || $products === []) {
            echo '<tr><td colspan="' . ($showPrice ? '7' : '6') . '">' . esc_html__('Chưa có sản phẩm phù hợp.', 'mini-erp') . '</td></tr>';
        }
        echo '</tbody></table></div>';
        self::pagination($page, $totalPages, 'product_page', ['q' => $search]);

        if ($canManage) {
            self::renderProductForm(is_array($editing) ? $editing : null, is_array($formCategories) ? $formCategories : [], $actor->id);
            self::renderCategorySection(is_array($categories) ? $categories : [], $categoryPage, $categoryPages, $actor->id);
        }
        echo '</div>';
    }

    /** @param array<string, mixed>|null $editing @param list<array<string, mixed>> $categories */
    private static function renderProductForm(?array $editing, array $categories, int $actorId): void
    {
        $isEditing = $editing !== null;
        $scope = self::recoveryScope($isEditing ? (int) $editing['id'] : null);
        echo '<hr><h2>' . esc_html($isEditing ? __('Sửa sản phẩm', 'mini-erp') : __('Thêm sản phẩm', 'mini-erp')) . '</h2>';
        if ($categories === []) {
            echo '<p>' . esc_html__('Tạo danh mục đang hoạt động trước khi thêm sản phẩm.', 'mini-erp') . '</p>';
            return;
        }
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" data-master-form="product" data-master-actor="' . esc_attr((string) $actorId) . '" data-master-scope="' . esc_attr($scope) . '"><input type="hidden" name="action" value="mini_erp_product_save">';
        wp_nonce_field('mini_erp_product_save');
        if ($isEditing) {
            echo '<input type="hidden" name="id" value="' . esc_attr((string) $editing['id']) . '"><input type="hidden" name="updated_at" value="' . esc_attr((string) $editing['updated_at']) . '">';
        }
        self::textInput('sku', __('SKU', 'mini-erp'), (string) ($editing['sku'] ?? ''), 64, true);
        self::textInput('name', __('Tên sản phẩm', 'mini-erp'), (string) ($editing['name'] ?? ''), 191, true);
        echo '<p><label for="erp-category-id">' . esc_html__('Danh mục', 'mini-erp') . '</label><br><select required id="erp-category-id" name="category_id">';
        foreach ($categories as $category) {
            $selected = (int) ($editing['category_id'] ?? 0) === (int) $category['id'];
            echo '<option value="' . esc_attr((string) $category['id']) . '"' . selected($selected, true, false) . '>' . esc_html((string) $category['code'] . ' · ' . (string) $category['name'] . ((int) $category['is_active'] === 1 ? '' : ' · ' . __('Ngừng dùng', 'mini-erp'))) . '</option>';
        }
        echo '</select></p>';
        self::textInput('unit', __('Đơn vị', 'mini-erp'), (string) ($editing['unit'] ?? ''), 32, true);
        self::textInput('unit_step', __('Bước số lượng', 'mini-erp'), (string) ($editing['unit_step'] ?? '1.000'), 22, true, 'number', '0.001');
        self::textInput('sale_price_vnd', __('Giá bán (VND)', 'mini-erp'), (string) ($editing['sale_price_vnd'] ?? '0'), 19, true, 'number', '1');
        submit_button($isEditing ? __('Lưu sản phẩm', 'mini-erp') : __('Tạo sản phẩm', 'mini-erp'));
        if ($isEditing) {
            echo ' <a class="button" href="' . esc_url(home_url('/?page=mini-erp-products')) . '">' . esc_html__('Hủy sửa', 'mini-erp') . '</a>';
        }
        echo '</form>';
    }

    /** @param list<array<string, mixed>> $categories */
    private static function renderCategorySection(array $categories, int $page, int $pages, int $actorId): void
    {
        echo '<hr><h2>' . esc_html__('Danh mục sản phẩm', 'mini-erp') . '</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '" data-master-form="category" data-master-actor="' . esc_attr((string) $actorId) . '" data-master-scope="new"><input type="hidden" name="action" value="mini_erp_category_save">';
        wp_nonce_field('mini_erp_category_save');
        self::textInput('code', __('Mã danh mục', 'mini-erp'), '', 64, true);
        self::textInput('name', __('Tên danh mục', 'mini-erp'), '', 191, true);
        submit_button(__('Tạo danh mục', 'mini-erp'));
        echo '</form><div class="erp-table-scroll erp-table-stack"><table class="widefat striped"><thead><tr><th>' . esc_html__('Mã', 'mini-erp') . '</th><th>' . esc_html__('Tên', 'mini-erp') . '</th><th>' . esc_html__('Trạng thái', 'mini-erp') . '</th><th>' . esc_html__('Thao tác', 'mini-erp') . '</th></tr></thead><tbody>';
        foreach ($categories as $category) {
            echo '<tr><td>' . esc_html((string) $category['code']) . '</td><td>' . esc_html((string) $category['name']) . '</td><td>' . esc_html((int) $category['is_active'] === 1 ? __('Đang dùng', 'mini-erp') : __('Ngừng dùng', 'mini-erp')) . '</td><td>';
            self::statusForm('category', (int) $category['id'], (string) $category['updated_at'], (int) $category['is_active'] === 1);
            echo '</td></tr>';
        }
        if ($categories === []) {
            echo '<tr><td colspan="4">' . esc_html__('Chưa có danh mục.', 'mini-erp') . '</td></tr>';
        }
        echo '</tbody></table></div>';
        self::pagination($page, $pages, 'category_page', []);
    }

    private static function textInput(string $name, string $label, string $value, int $maxLength, bool $required, string $type = 'text', ?string $step = null): void
    {
        $id = 'erp-' . $name;
        echo '<p><label for="' . esc_attr($id) . '">' . esc_html($label) . '</label><br><input class="regular-text" id="' . esc_attr($id) . '" name="' . esc_attr($name) . '" type="' . esc_attr($type) . '" maxlength="' . esc_attr((string) $maxLength) . '" value="' . esc_attr($value) . '"' . ($step === null ? '' : ' step="' . esc_attr($step) . '"') . ($required ? ' required' : '') . '></p>';
    }

    private static function statusForm(string $entity, int $id, string $updatedAt, bool $active): void
    {
        echo '<form class="erp-inline-action" method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="mini_erp_' . esc_attr($entity) . '_status"><input type="hidden" name="id" value="' . esc_attr((string) $id) . '"><input type="hidden" name="updated_at" value="' . esc_attr($updatedAt) . '"><input type="hidden" name="active" value="' . ($active ? '0' : '1') . '">';
        wp_nonce_field('mini_erp_' . $entity . '_status', '_wpnonce', false);
        echo '<button class="button-link" type="submit">' . esc_html($active ? __('Ngừng dùng', 'mini-erp') : __('Kích hoạt', 'mini-erp')) . '</button></form>';
    }

    /** @param array<string, string> $extra */
    private static function pagination(int $page, int $pages, string $parameter, array $extra): void
    {
        if ($pages < 2) {
            return;
        }
        echo '<p class="tablenav"><span>' . esc_html(sprintf(__('Trang %1$d / %2$d', 'mini-erp'), $page, $pages)) . '</span> ';
        foreach (['prev' => $page - 1, 'next' => $page + 1] as $direction => $target) {
            if ($target < 1 || $target > $pages) {
                continue;
            }
            $url = add_query_arg(['page' => 'mini-erp-products', $parameter => $target] + $extra, home_url('/'));
            echo '<a class="button" href="' . esc_url($url) . '">' . esc_html($direction === 'prev' ? __('Trước', 'mini-erp') : __('Sau', 'mini-erp')) . '</a> ';
        }
        echo '</p>';
    }

    /** @return array{sku:string,name:string,category_id:int,unit:string,unit_step:string,sale_price_vnd:string}|null */
    private static function productInput(wpdb $db, ?int $productId): ?array
    {
        $sku = strtoupper(sanitize_text_field(self::requestText($_POST, 'sku')));
        $name = sanitize_text_field(self::requestText($_POST, 'name'));
        $categoryId = self::postId('category_id');
        $unit = sanitize_text_field(self::requestText($_POST, 'unit'));
        $stepRaw = trim(self::requestText($_POST, 'unit_step'));
        $price = trim(self::requestText($_POST, 'sale_price_vnd'));
        $step = self::decimalStep($stepRaw);
        $maxInt = (string) PHP_INT_MAX;
        if (!preg_match('/\A[A-Z0-9][A-Z0-9._-]{0,63}\z/D', $sku)
            || self::textLength($name) < 1 || self::textLength($name) > 191
            || $categoryId === null || self::textLength($unit) < 1 || self::textLength($unit) > 32
            || $step === null || !preg_match('/\A(?:0|[1-9][0-9]{0,18})\z/D', $price)
            || (strlen($price) === strlen($maxInt) && strcmp($price, $maxInt) > 0)
        ) {
            return null;
        }
        $categoryTable = self::table($db, 'erp_categories');
        $categoryIsActive = (string) $db->get_var($db->prepare("SELECT is_active FROM {$categoryTable} WHERE id = %d", $categoryId)) === '1';
        if (!$categoryIsActive) {
            $sameInactiveCategory = $productId !== null && (string) $db->get_var($db->prepare(
                'SELECT COUNT(*) FROM ' . self::table($db, 'erp_products') . ' WHERE id = %d AND category_id = %d',
                $productId,
                $categoryId
            )) === '1';
            if (!$sameInactiveCategory) {
                return null;
            }
        }
        return ['sku' => $sku, 'name' => $name, 'category_id' => $categoryId, 'unit' => $unit, 'unit_step' => $step, 'sale_price_vnd' => $price];
    }

    private static function decimalStep(string $value): ?string
    {
        if (!preg_match('/\A(?:0|[1-9][0-9]{0,14})(?:\.([0-9]{1,3}))?\z/D', $value, $matches)) {
            return null;
        }
        $fraction = str_pad($matches[1] ?? '', 3, '0');
        if ((int) $value === 0 && (int) $fraction === 0) {
            return null;
        }
        return explode('.', $value, 2)[0] . '.' . $fraction;
    }

    private static function service(wpdb $db): CatalogService
    {
        return new CatalogService($db, new TransactionManager($db), new AuditLogger());
    }

    private static function actor(): Actor
    {
        return CapabilityPolicy::currentActor() ?? throw new RuntimeException('ERP identity is unavailable.');
    }

    private static function table(wpdb $db, string $suffix): string
    {
        $prefix = $db->prefix;
        if (!is_string($prefix) || !preg_match('/\A[A-Za-z0-9_]{1,32}\z/D', $prefix)
            || !in_array($suffix, ['erp_categories', 'erp_products'], true)
        ) {
            throw new RuntimeException('The ERP table prefix is not supported.');
        }
        return '`' . $prefix . $suffix . '`';
    }

    private static function queryPage(string $name): int
    {
        $value = isset($_GET[$name]) ? filter_var(self::requestText($_GET, $name), FILTER_VALIDATE_INT) : 1;
        return is_int($value) && $value > 0 ? min($value, 1000000) : 1;
    }

    private static function queryId(string $name): ?int
    {
        if (!isset($_GET[$name])) {
            return null;
        }
        $value = filter_var(self::requestText($_GET, $name), FILTER_VALIDATE_INT);
        return is_int($value) && $value > 0 ? $value : null;
    }

    private static function postId(string $name): ?int
    {
        $value = isset($_POST[$name]) ? filter_var(self::requestText($_POST, $name), FILTER_VALIDATE_INT) : false;
        return is_int($value) && $value > 0 ? $value : null;
    }

    /** @param array<string, mixed> $source */
    private static function requestText(array $source, string $name): string
    {
        $value = $source[$name] ?? '';
        return is_scalar($value) ? (string) wp_unslash((string) $value) : '';
    }

    private static function textLength(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }

    private static function notice(): string
    {
        $value = isset($_GET['notice']) ? sanitize_key(self::requestText($_GET, 'notice')) : '';
        return in_array($value, ['saved', 'invalid', 'duplicate', 'conflict', 'unavailable'], true) ? $value : '';
    }

    private static function noticeText(string $notice): string
    {
        return match ($notice) {
            'saved' => __('Đã lưu thay đổi.', 'mini-erp'),
            'invalid' => __('Dữ liệu chưa hợp lệ hoặc danh mục không còn hoạt động.', 'mini-erp'),
            'duplicate' => __('Mã SKU hoặc mã danh mục đã tồn tại.', 'mini-erp'),
            'conflict' => __('Bản ghi đã thay đổi hoặc không còn tồn tại. Hãy tải lại trang.', 'mini-erp'),
            default => __('Không thể lưu lúc này. Hãy thử lại sau.', 'mini-erp'),
        };
    }

    private static function redirect(string $notice): never
    {
        $url = add_query_arg(['page' => 'mini-erp-products', 'notice' => $notice], home_url('/'));
        wp_safe_redirect($url, 303);
        exit;
    }

    private static function recoveryScope(?int $id): string
    {
        return $id === null ? 'new' : 'id-' . $id;
    }

    private static function redirectWithRecovery(string $notice, string $form, ?int $id): never
    {
        $args = ['page' => 'mini-erp-products', 'notice' => $notice, 'recover_form' => $form, 'recover_scope' => self::recoveryScope($id)];
        if ($notice !== 'saved' && $form === 'product' && $id !== null) {
            $args['edit'] = $id;
        }
        wp_safe_redirect(add_query_arg($args, home_url('/')), 303);
        exit;
    }
}
