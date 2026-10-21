<?php
declare(strict_types=1);

namespace MiniERP\Admin\Controllers;

use MiniERP\Infrastructure\Audit\AuditLogger;
use MiniERP\Infrastructure\Database\DatabaseException;
use MiniERP\Infrastructure\Database\TransactionManager;
use MiniERP\Security\CapabilityPolicy;
use Throwable;
use wpdb;

/** Stores the company identity copied into every subsequently issued invoice snapshot. */
final class CompanyProfileController
{
    public static function save(): void
    {
        CapabilityPolicy::requireCapability('erp_settings_manage');check_admin_referer('mini_erp_company_profile_save');global $wpdb;
        if(!$wpdb instanceof wpdb||!is_string($wpdb->options)||!preg_match('/\A[A-Za-z0-9_]{1,64}\z/D',$wpdb->options))wp_die('Cơ sở dữ liệu không khả dụng.');
        $name=self::value('company_name',191);$address=self::value('company_address',1000);$taxId=self::value('company_tax_id',64);
        if($name==='')self::redirect('invalid');
        $actor=CapabilityPolicy::currentActor();if($actor===null)wp_die('Phiên ERP không hợp lệ.');
        try{
            $transaction=new TransactionManager($wpdb);
            $transaction->run(function(TransactionManager $tx)use($wpdb,$actor,$name,$address,$taxId):void{
                foreach(['erp_company_name'=>$name,'erp_company_address'=>$address,'erp_company_tax_id'=>$taxId] as $key=>$value){
                    $tx->execute("INSERT INTO `{$wpdb->options}` (option_name,option_value,autoload) VALUES (%s,%s,'no') ON DUPLICATE KEY UPDATE option_value=VALUES(option_value),autoload='no'",[$key,$value]);
                }
                (new AuditLogger())->record($tx,self::uuid(),$actor->id,'settings.company_profile.updated','company_profile',null,'success');
            });
            if(function_exists('wp_cache_delete')){wp_cache_delete('erp_company_name','options');wp_cache_delete('erp_company_address','options');wp_cache_delete('erp_company_tax_id','options');wp_cache_delete('alloptions','options');wp_cache_delete('notoptions','options');}
            self::redirect('saved');
        }catch(DatabaseException){self::redirect('unavailable');}catch(Throwable){self::redirect('unavailable');}
    }

    public static function render():void
    {
        CapabilityPolicy::requireCapability('erp_settings_manage');
        $actor = CapabilityPolicy::currentActor();
        if ($actor === null) wp_die(esc_html__('Phiên ERP không hợp lệ.', 'mini-erp'), '', ['response' => 403]);
        echo '<div class="wrap erp-app"><h1>Hồ sơ công ty</h1><p>Thông tin này được chụp vào hóa đơn nội bộ khi phát hành. Sửa hồ sơ sẽ không thay đổi chứng từ đã phát hành.</p>';
        $notice = isset($_GET['notice']) && is_scalar($_GET['notice']) ? sanitize_key((string) wp_unslash($_GET['notice'])) : '';
        if ($notice === 'saved') echo '<div class="notice notice-success"><p>Đã cập nhật hồ sơ công ty.</p></div>';
        elseif (in_array($notice, ['invalid', 'unavailable'], true)) echo '<div class="notice notice-error"><p>' . esc_html($notice === 'invalid' ? 'Tên công ty chưa hợp lệ.' : 'Không thể cập nhật hồ sơ lúc này.') . '</p></div>';
        echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'" data-master-form="company-profile" data-master-actor="'.esc_attr((string)$actor->id).'" data-master-scope="profile"><input type="hidden" name="action" value="mini_erp_company_profile_save">';wp_nonce_field('mini_erp_company_profile_save');
        self::field('company_name','Tên công ty',(string)get_option('erp_company_name',get_option('blogname','')),191,true);
        self::field('company_address','Địa chỉ',(string)get_option('erp_company_address',''),1000,false,true);
        self::field('company_tax_id','Mã số thuế',(string)get_option('erp_company_tax_id',''),64,false);
        echo '<p><button class="button button-primary">Lưu hồ sơ</button></p></form></div>';
    }

    private static function field(string $name,string $label,string $value,int $max,bool $required,bool $textarea=false):void
    {
        echo '<p><label for="erp-'.esc_attr($name).'">'.esc_html($label).'</label><br>';
        if($textarea)echo '<textarea class="large-text" id="erp-'.esc_attr($name).'" name="'.esc_attr($name).'" maxlength="'.esc_attr((string)$max).'" rows="3">'.esc_textarea($value).'</textarea>';
        else echo '<input class="regular-text" type="text" id="erp-'.esc_attr($name).'" name="'.esc_attr($name).'" maxlength="'.esc_attr((string)$max).'" value="'.esc_attr($value).'"'.($required?' required':'').'>';
        echo '</p>';
    }

    private static function value(string $key,int $max):string{$raw=isset($_POST[$key])&&is_scalar($_POST[$key])?(string)wp_unslash($_POST[$key]):'';return mb_substr(sanitize_textarea_field($raw),0,$max);}
    private static function redirect(string $notice):never{wp_safe_redirect(add_query_arg(['page'=>'mini-erp-company','notice'=>$notice,'recover_form'=>'company-profile','recover_scope'=>'profile'],home_url('/')),303);exit;}
    private static function uuid():string{if(function_exists('wp_generate_uuid4'))return wp_generate_uuid4();$bytes=random_bytes(16);$bytes[6]=chr((ord($bytes[6])&15)|64);$bytes[8]=chr((ord($bytes[8])&63)|128);return vsprintf('%s%s-%s-%s-%s-%s%s%s',str_split(bin2hex($bytes),4));}
}
