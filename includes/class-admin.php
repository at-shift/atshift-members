<?php
namespace Atshift\Membership;
defined('ABSPATH') || exit;
final class Admin {
    private $reg;
    private static $save_actions=[];
    public function __construct($reg) {$this->reg=$reg;}
    public static function pro_active() {return (bool)apply_filters('atshift_members_pro_active',false);}
    public static function can_access_settings() {
        if(!current_user_can('asm_manage_members'))return false;
        if(current_user_can('manage_options'))return true;
        $user=wp_get_current_user();
        return in_array('asm_operator',$user->roles,true)&&Members::active($user->ID)&&(!Scope::limited()||Scope::ready());
    }
    public function hooks() {
        add_action('admin_menu',[self::class,'filter_posting_menus'],PHP_INT_MAX);
        add_action('wp_dashboard_setup',function(){
            if(Members::managed(wp_get_current_user())&&!current_user_can('manage_options'))remove_meta_box('dashboard_activity','dashboard','normal');
        },PHP_INT_MAX);
        add_action('admin_menu',function(){
            if(!self::can_access_settings())return;
            add_menu_page('atshift Members','Members','asm_manage_members','atshift-members',[$this,'page'],'dashicons-groups');
            foreach($this->sections() as $slug=>$section)if(current_user_can($section['capability']))add_submenu_page('atshift-members',$section['title'].' — Members',$section['title'],$section['capability'],$slug,[$this,'page']);
            // Match the tab order and discard WordPress's automatically inserted duplicate parent item.
            global $submenu;
            $items=[];foreach($submenu['atshift-members']??[] as $item)$items[$item[2]]=$item;
            $ordered=[];foreach($this->sections() as $slug=>$section)if(isset($items[$slug])){$ordered[]=$items[$slug];unset($items[$slug]);}
            $submenu['atshift-members']=array_merge($ordered,array_values($items));
        });
        add_action('admin_enqueue_scripts',function($hook){if(str_contains($hook,'atshift-members'))wp_enqueue_style('asm-member-admin',plugins_url('assets/admin.css',dirname(__DIR__).'/atshift-members.php'),[],filemtime(dirname(__DIR__).'/assets/admin.css'));});
        add_action('admin_post_asm_admin',[$this,'save']);
        add_action('admin_notices',function(){if (current_user_can('manage_options') && !$this->reg->ready()) echo ('<div class="notice notice-warning">' . '<p>' . esc_html__('Registration is not ready yet. Check your spam protection keys in Registration Settings. If the issue persists, contact the person who set up your site.', 'atshift-members') . '</p>' . '</div>');});
        add_action('login_form_lostpassword',function(){if (Screens::url('reset')!==home_url('/')) {wp_safe_redirect(Screens::url('reset'));exit;}});
    }
    public static function filter_posting_menus() {
        $user=wp_get_current_user();
        if(!Members::managed($user)||current_user_can('manage_options'))return;
        foreach(Posting::types() as $name=>$type){
            $slug=$name==='post'?'edit.php':'edit.php?post_type='.$name;
            if(!current_user_can($type->cap->edit_posts))remove_menu_page($slug);
            elseif(!current_user_can($type->cap->create_posts))remove_submenu_page($slug,$name==='post'?'post-new.php':'post-new.php?post_type='.$name);
        }
        if(!current_user_can('upload_files')||(Media::enabled()&&!Media::can_upload()))remove_menu_page('upload.php');
        if(!self::can_access_settings())remove_menu_page('atshift-members');
    }
    private function form($action,$id='') {echo '<form'.($id?' id="'.esc_attr($id).'"':'').' method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="asm_admin"><input type="hidden" name="operation" value="'.esc_attr($action).'">';wp_nonce_field('asm_admin');}
    public static function save_action($form,$title,$label,$description) {
        self::$save_actions[$form]=compact('title','label','description');
    }
    public function save() {
        if (!self::can_access_settings()) wp_die(esc_html__('You cannot perform this action.', 'atshift-members'),'',['response'=>403]);
        check_admin_referer('asm_admin');
        $op=is_string($_POST['operation']??null)?sanitize_key($_POST['operation']):'';
        $result=true;
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Structured input is validated by the operation-specific service after the shared nonce; mail fields are individually unslashed and sanitized below. Do not flatten arrays before schema validation.
        if($op==='file_storage')$result=Files::save_storage(wp_unslash($_POST['storage']??[]));
        elseif ($op==='member_settings') $result=Members::save_settings(absint($_POST['user_id']??0),wp_unslash($_POST));
        elseif ($op==='state') $result=Members::set_state(absint($_POST['user_id']??0),sanitize_key(wp_unslash($_POST['state']??'')));
        elseif ($op==='bulk_state') {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Structured input is validated by the operation-specific service after the shared nonce; mail fields are individually unslashed and sanitized below. Do not flatten arrays before schema validation.
            $result=Members::bulk_state($_POST['users']??[],is_string($_POST['bulk_state']??null)?sanitize_key($_POST['bulk_state']):'');
            if(!is_wp_error($result)){
                set_transient('asm_member_result_'.get_current_user_id(),$result,60);
                wp_safe_redirect(self::url());exit;
            }
        } elseif ($op==='notice') {
            $result=new \WP_Error('posting',__('Change posting permissions in Posting Settings.', 'atshift-members'));
        } elseif (!current_user_can('manage_options')) wp_die(esc_html__('Site administrator permissions are required.', 'atshift-members'),'',['response'=>403]);
        elseif ($op==='invite') $result=new \WP_Error('retired',__('Operator invitations are no longer available. Use Member Management to promote an existing member to site operator.', 'atshift-members'));
        elseif ($op==='pages') {
            $ids=get_option('asm_pages',[]);
            foreach (Screens::definitions() as $key=>$def) {
                if (!empty($ids[$key]) && get_post($ids[$key])) continue;
                $id=wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>$def[0],'post_content'=>'['.$def[1].']'],true);
                if (is_wp_error($id)) {$result=$id;break;}$ids[$key]=$id;
            }
            update_option('asm_pages',$ids,false);
        } elseif ($op==='turnstile') {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Structured input is validated by the operation-specific service after the shared nonce; mail fields are individually unslashed and sanitized below. Do not flatten arrays before schema validation.
            $result=Services::save_turnstile(wp_unslash($_POST['turnstile']??[]));
        } elseif ($op==='posting') {
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized,WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Structured input is validated by the operation-specific service after the shared nonce; mail fields are individually unslashed and sanitized below. Do not flatten arrays before schema validation.
            $result=Posting::save(wp_unslash($_POST['posting']??[]),$_POST['posting_stamp']??'');
        } elseif ($op==='settings') {
            $keys=preg_split('/[\s,]+/',sanitize_text_field(wp_unslash($_POST['fields']??implode(',',(array)Registration::option('fields',[])))),-1,PREG_SPLIT_NO_EMPTY);
            $selected=array_values(array_unique(array_map('sanitize_key',$keys)));
            if(!defined('ASM_PUBLIC_FIELDS')&&!Registration::api()){
                $api=Registration::profile_api();$available=call_user_func($api['fields'],$selected);
                if(array_diff($selected,array_keys($available)))$result=new \WP_Error('profile_fields',__('Some field identifiers are unavailable. Custom fields also need an integration that renders and saves them in the forms.', 'atshift-members'));
            }
            if(!is_wp_error($result))update_option('asm_settings',['enabled'=>!empty($_POST['enabled']),'approval'=>!empty($_POST['approval']),'fields'=>(defined('ASM_PUBLIC_FIELDS')||Registration::api())?(array)Registration::option('fields',[]):$selected],false);
        } elseif ($op==='mail') {
            $templates=(array)get_option('asm_mail_templates',[]);
            foreach (Mail::defaults() as $type=>$def) {
                if($type==='invite')continue;
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Structured input is validated by the operation-specific service after the shared nonce; mail fields are individually unslashed and sanitized below. Do not flatten arrays before schema validation.
                $entry=$_POST['templates'][$type]??[];
                $subject=sanitize_text_field(wp_unslash($entry['subject']??''));$body=sanitize_textarea_field(wp_unslash($entry['body']??''));
                if (!$subject || !$body || strlen($subject)>300 || strlen($body)>20000 || (in_array($type,['verify','invite','reset','email_verify'],true) && strpos($body,'{link}')===false)) {$result=new \WP_Error('template',__('Check the subject, body, and required {link} placeholder.', 'atshift-members'));break;}
                $allowed=array_merge(['site','link'],$def['placeholders']??[]);preg_match_all('/\{([^}]+)\}/',$subject.$body,$matches);
                if(array_diff($matches[1],$allowed)){$result=new \WP_Error('template',__('This email contains a placeholder that is not supported for this message type.', 'atshift-members'));break;}
                $templates[$type]=['subject'=>$subject,'body'=>$body];
            }
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Structured input is validated by the operation-specific service after the shared nonce; mail fields are individually unslashed and sanitized below. Do not flatten arrays before schema validation.
            $signature=$_POST['signature']??'';
            if (!is_string($signature)) $result=new \WP_Error('signature',__('Enter the signature as text.', 'atshift-members'));
            if (!is_wp_error($result)) {update_option('asm_mail_templates',$templates,false);update_option('asm_sender_name',sanitize_text_field(wp_unslash($_POST['sender']??'')),false);update_option('asm_mail_signature',sanitize_textarea_field(wp_unslash($signature)),false);}
        }
        if (is_wp_error($result)) wp_die(esc_html($result->get_error_message()),'',['back_link'=>true,'response'=>400]);
        $section=['posting'=>'atshift-members-posting','turnstile'=>'atshift-members-registration','settings'=>'atshift-members-registration','pages'=>'atshift-members-pages','mail'=>'atshift-members-mail'][$op]??'atshift-members';
        if(in_array($op,['state','notice','member_settings'],true)){$url=add_query_arg('member_id',absint($_POST['user_id']??0),self::url());}else $url=self::url($section);
        if($op==='member_settings'&&!Scope::can_manage(absint($_POST['user_id']??0),'view'))$url=self::url();
        wp_safe_redirect(add_query_arg('saved','1',$url));exit;
    }
    public static function url($section='atshift-members') {return admin_url('admin.php?page='.$section);}
    public static function source($guide,$description) {
        echo '<p class="description">'.wp_kses_post(self::product_copy(esc_html($description))).'</p><p class="asm-source"><span class="asm-source-label">'.wp_kses_post(self::product_copy(esc_html($guide))).'</span></p>';
    }
    public static function integration_badges($page) {
        $plugins=[];
        if(in_array($page,['atshift-members-pages','atshift-members-registration'],true)&&Registration::uses_profile_plugin()){
            $plugins['profile-fields']=Registration::uses_profile_classifications()?'atshift User Profile Fields Pro':'atshift User Profile Fields';
        }
        if(in_array($page,['atshift-members','atshift-members-posting'],true)&&Audience::api()){
            $plugins['profile-fields-pro']='atshift User Profile Fields Pro';
        }
        if(in_array($page,['atshift-members-pages','atshift-members-registration'],true)&&Registration::passkey_integration_available())$plugins['freeform-login']='atshift Freeform Login';
        // Add-ons declare their page-specific integrations only while their hooks are available.
        $plugins=apply_filters('atshift_members_admin_integrations',$plugins,$page);
        if(!is_array($plugins))return;
        self::integration_group(array_values($plugins));
    }
    public static function integration_badge($name) {self::integration_group([$name]);}
    public static function integration_group($plugins) {
        $plugins=array_values(array_unique(array_filter($plugins,function($name){return is_string($name)&&$name!=='';})));
        if(!$plugins)return;
        if(in_array('atshift User Profile Fields Pro',$plugins,true))$plugins=array_values(array_diff($plugins,['atshift User Profile Fields']));
        $badges=array_map([self::class,'badge_html'],$plugins);
        $names=implode('<span class="asm-integration-join">'.esc_html__(' and ', 'atshift-members').'</span>',$badges);
        /* translators: %s: Plugin name badges. */
        $message=esc_html__('Functionality extended by %s', 'atshift-members');
        echo '<span class="asm-integration-status">'.wp_kses_post(sprintf($message,$names)).'</span>';
    }
    public static function badge_html($name) {
        ob_start();self::plugin_badge($name);return ob_get_clean();
    }
    public static function plugin_badge($name,$label='') {
        if(!is_string($name)||$name==='')return;
        $labels=['atshift User Profile Fields Pro'=>__('atshift User Profile Fields / Pro Add-on', 'atshift-members'),'atshift Members Pro'=>__('atshift Members Pro Add-on', 'atshift-members'),'atshift Freeform Login Pro'=>__('atshift Freeform Login / Pro Add-on', 'atshift-members')];
        $colors=['atshift Members'=>'green','atshift User Profile Fields'=>'blue','atshift User Profile Fields Pro'=>'purple','atshift Members Pro'=>'orange','atshift Freeform Login'=>'teal','atshift Freeform Login Pro'=>'teal'];
        // The badge contains only the canonical product name, never status or promotional text.
        echo '<span class="asm-integration-badge asm-integration-badge-'.esc_attr($colors[$name]??'neutral').'">'.esc_html($labels[$name]??$name).'</span>';
    }
    public static function integration_description($context) {
        $rows=[];
        if($context==='profile') {
            if(Registration::uses_profile_plugin()) {
                $rows[]=[Registration::uses_profile_classifications()?'atshift User Profile Fields Pro':'atshift User Profile Fields',Registration::uses_profile_classifications()?__('Configures the profile layout and user classifications', 'atshift-members'):__('Configures the profile layout', 'atshift-members')];
            }
            $rows[]=['atshift Members',Registration::uses_profile_plugin()?__('Displays integrated fields in registration, account, and editing forms', 'atshift-members'):__('Displays passkey registration and management on the account editing page', 'atshift-members')];
            if(Registration::passkey_integration_available())$rows[]=['atshift Freeform Login',__('Provides passkey registration and management after signup, and passkey login', 'atshift-members')];
        } elseif(in_array($context,['member-categories','categories'],true)) {
            $rows[]=['atshift User Profile Fields Pro',__('Configures and manages user categories', 'atshift-members')];
            $rows[]=[['atshift Members Pro','atshift User Profile Fields Pro'],__('Displays members within the assigned scope and saves category selections together with member information', 'atshift-members')];
        } elseif(in_array($context,['posting-management','posting'],true)) {
            $rows[]=['atshift Members',__('Sets who can create content for each post type', 'atshift-members')];
            $rows[]=['atshift User Profile Fields Pro',__('Manages user categories', 'atshift-members')];
            if($context==='posting-management'){
                $rows[]=['atshift Members Pro',__('Sets approvers and the approval order', 'atshift-members')];
                $rows[]=[['atshift Members Pro','atshift User Profile Fields Pro'],__('Assigns member management responsibilities by category', 'atshift-members')];
            }
        } elseif($context==='mail') {
            $rows[]=['atshift Members Pro',__('Sets announcement recipients and delivery schedules', 'atshift-members')];
            $rows[]=['atshift Members',__('Provides announcements for email delivery', 'atshift-members')];
        }
        if(!$rows)return;
        echo '<p>'.esc_html__('The following integrations are in use.', 'atshift-members').'</p><ul class="ul-disc asm-integration-description">';
        foreach($rows as [$names,$description]){
            $badges=implode(esc_html__(' and ', 'atshift-members'),array_map([self::class,'badge_html'],(array)$names));
            /* translators: 1: Plugin name badges, 2: Integration description. */
            echo '<li>'.wp_kses_post(sprintf(__('%1$s: %2$s', 'atshift-members'),$badges,esc_html($description))).'</li>';
        }
        echo '</ul>';
    }
    public static function available_integration($context) {
        $messages=[
            /* translators: %s: Plugin name badge. */
            'profile'=>__('%s makes it easy to configure profile fields and extend registration, account display, and editing forms.', 'atshift-members'),
            /* translators: %s: Plugin name badge. */
            'posting'=>__('Use %s to create user categories and choose which post types members in each category can use.', 'atshift-members'),
            /* translators: 1: Members Pro badge, 2: User Profile Fields Pro badge. */
            'categories'=>__('Use %1$s with %2$s to save category selections together with member information and delegate member management by category.', 'atshift-members'),
            /* translators: %s: Plugin name badge. */
            'mail'=>__('Use %s to send announcement emails to members who have opted in, or schedule them for later.', 'atshift-members'),
        ];
        $plugins=['profile'=>['atshift User Profile Fields'],'posting'=>['atshift User Profile Fields Pro'],'categories'=>['atshift Members Pro','atshift User Profile Fields Pro'],'mail'=>['atshift Members Pro']][$context]??[];
        if(!$plugins)return;
        echo '<p class="asm-integration-description asm-integration-available">'.wp_kses_post(sprintf(esc_html($messages[$context]),...array_map([self::class,'badge_html'],$plugins))).'</p>';
    }
    public static function passkey_tip() {
        /* translators: %s: Freeform Login plugin badge. */
        echo '<p class="asm-integration-description">'.wp_kses_post(sprintf(esc_html__('Install and activate %s to enable passkeys on supported environments without additional site setup. After signing up, members can register and manage passkeys on the Edit Account Information page.', 'atshift-members'),self::badge_html('atshift Freeform Login'))).'</p>';
    }
    /** Format product references in static guide copy, preserving existing badges and attributes. */
    public static function product_copy($html) {
        $skip=0;$result='';
        foreach(wp_html_split($html) as $part){
            if(str_starts_with($part,'<')){
                if(preg_match('/^<span\b/i',$part)&&($skip||str_contains($part,'asm-integration-badge')))$skip++;
                elseif($skip&&preg_match('/^<\/span\b/i',$part))$skip--;
                $result.=$part;continue;
            }
            if(!$skip)$part=preg_replace_callback('#atshift (?:User Profile Fields|Freeform Login|Members)(?: / Pro(?: ?アドオン| Add-on)?| Pro(?: ?アドオン| Add-on)?)?#u',function($match){
                $name=str_replace(' / Pro',' Pro',preg_replace('/(?: ?アドオン| Add-on)$/u','',$match[0]));ob_start();self::plugin_badge($name);return ob_get_clean();
            },$part);
            $result.=$part;
        }
        return $result;
    }
    private function sections() {
        $sections=[
            'atshift-members'=>['title'=>__('Member Management', 'atshift-members'),'description'=>'','capability'=>'asm_manage_members','render'=>[$this,'members_page']],
            'atshift-members-registration'=>['title'=>__('Registration Settings', 'atshift-members'),'description'=>__('Choose whether to accept new registrations and whether they need approval. You can also review the name, biography, and other fields members will fill in.', 'atshift-members'),'capability'=>'manage_options','render'=>[$this,'registration_page']],
            'atshift-members-posting'=>['title'=>__('Posting Settings', 'atshift-members'),'description'=>__('Choose which post types members can use and which members can create content.', 'atshift-members'),'capability'=>'manage_options','render'=>[$this,'posting_page']],
            'atshift-members-pages'=>['title'=>__('Member Page Setup', 'atshift-members'),'description'=>'','capability'=>'manage_options','render'=>[$this,'pages_page']],
            'atshift-members-mail'=>['title'=>__('Email Settings', 'atshift-members'),'description'=>__('Customize the automatic emails sent for registration, password changes, and other account events. Default text is provided. Messages are sent through the standard WordPress mail function.', 'atshift-members'),'capability'=>'manage_options','render'=>[$this,'mail_page']],
        ];
        $sections['atshift-members-plugins']=['title'=>__('Related Plugins', 'atshift-members'),'description'=>__('atshift Members provides the essentials for a membership site. Add the following plugins to suit your site\'s needs.', 'atshift-members'),'capability'=>'manage_options','render'=>[$this,'plugins_page']];
        $sections=apply_filters('atshift_members_admin_sections',$sections);
        $ordered=[];
        foreach(self::navigation_groups() as $group)foreach($group['pages'] as $slug)if(isset($sections[$slug])){$ordered[$slug]=$sections[$slug];unset($sections[$slug]);}
        return $ordered+$sections;
    }
    private static function navigation_groups() {
        if(!current_user_can('manage_options'))return [['title'=>__('Manage Members', 'atshift-members'),'pages'=>['atshift-members','atshift-members-operations']]];
        return [
            ['title'=>__('1. Set Up Member Pages', 'atshift-members'),'pages'=>['atshift-members-pages','atshift-members-mail']],
            ['title'=>__('2. Registration and Administration', 'atshift-members'),'pages'=>['atshift-members-registration','atshift-members-posting','atshift-members']],
            ['title'=>__('3. Pro Add-on Features', 'atshift-members'),'pages'=>['atshift-members-permissions','atshift-members-workflow','atshift-members-operations']],
            ['title'=>'','pages'=>['atshift-members-plugins']],
        ];
    }
    public function page() {
        if(!self::can_access_settings())wp_die(esc_html__('This page is unavailable.', 'atshift-members'),'',['response'=>403]);
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin display/filter parameters; mutation handlers separately require nonce and object capability checks.
        $sections=$this->sections();$slug=is_string($_GET['page']??null)?sanitize_key($_GET['page']):'atshift-members';$section=$sections[$slug]??null;
        if(!$section||!current_user_can($section['capability']))wp_die(esc_html__('This page is unavailable.', 'atshift-members'),'',['response'=>403]);
        Screens::private_headers();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin display/filter parameters; mutation handlers separately require nonce and object capability checks.
        echo '<div class="wrap asm-settings'.($slug==='atshift-members'&&empty($_GET['member_id'])?' asm-members-overview':'').'"><p class="asm-kicker">atshift Members'.(self::pro_active()?' <span class="asm-pro-badge">PRO</span>':'').('</p>' . '<nav class="asm-settings-nav" aria-label="' . esc_attr__('atshift Members Settings', 'atshift-members') . '">');
        $remaining=$sections;$groups=self::navigation_groups();$groups[]=['title'=>__('Additional Settings', 'atshift-members'),'pages'=>array_keys($sections)];
        foreach($groups as $group){
            $links='';foreach($group['pages'] as $key){$item=$remaining[$key]??null;unset($remaining[$key]);if($item&&current_user_can($item['capability']))$links.='<a'.(in_array($key,['atshift-members-permissions','atshift-members-workflow','atshift-members-operations'],true)?' class="asm-nav-members-pro"':'').' href="'.esc_url(self::url($key)).'" '.($key===$slug?'aria-current="page"':'').'>'.esc_html($item['title']).'</a>';}
            if($links!=='')echo '<div class="asm-nav-group'.($group['title']===''?' asm-nav-group-unlabelled':'').'">'.($group['title']!==''?'<p class="asm-nav-group-title">'.esc_html($group['title']).'</p>':'').'<div class="asm-nav-group-links">'.wp_kses_post($links).'</div></div>';
        }
        echo '</nav><div class="asm-settings-heading"><h1>'.esc_html($section['title']).'</h1>';
        self::integration_badges($slug);
        echo '</div><hr class="wp-header-end">';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin display/filter parameters; mutation handlers separately require nonce and object capability checks.
        if(isset($_GET['saved']))echo ('<div class="notice notice-success">' . '<p>' . esc_html__('Settings saved.', 'atshift-members') . '</p>' . '</div>');
        self::$save_actions=[];
        ob_start();if(!empty($section['description']))echo '<p class="asm-intro">'.esc_html($section['description']).'</p>';call_user_func($section['render']);if(current_user_can('manage_options')&&in_array($slug,['atshift-members-posting','atshift-members-mail'],true))$this->pro_tip($slug);$body=ob_get_clean();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin display/filter parameters; mutation handlers separately require nonce and object capability checks.
        if($slug==='atshift-members'&&!empty($_GET['member_id']))echo '<p class="asm-member-back"><a href="'.esc_url(self::url()).('">' . esc_html__('← Back to members', 'atshift-members') . '</a>' . '</p>');
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Buffered authorized section renderer; each field is escaped at its HTML context and form markup must be preserved.
        echo '<div class="asm-settings-layout'.(self::$save_actions?' asm-has-save-sidebar':'').'"><div class="asm-settings-main">'.$body.'</div>';
        if(self::$save_actions){
            echo ('<aside class="asm-save-sidebar" aria-label="' . esc_attr__('Save Settings', 'atshift-members') . '">');
            foreach(self::$save_actions as $form=>$action)echo '<section class="postbox asm-save-box" aria-labelledby="'.esc_attr($form).'-title"><div class="postbox-header"><h2 id="'.esc_attr($form).'-title">'.esc_html($action['title']).'</h2></div><div class="inside"><p>'.esc_html($action['description']).'</p></div><div class="asm-save-footer"><button type="submit" class="button button-primary" form="'.esc_attr($form).'">'.esc_html($action['label']).'</button></div></section>';
            echo '</aside>';
        }
        echo '</div></div>';
    }
    private function pro_tip($page) {
        if($page==='atshift-members-mail'){
            if(self::pro_active())self::integration_description('mail');else self::available_integration('mail');
            return;
        }
        if(Scope::ready()){self::integration_description('posting-management');return;}
        if(Audience::api())self::integration_description('posting');else self::available_integration('posting');
        if(Scope::ready())self::integration_description('categories');else self::available_integration('categories');
    }
    public function plugins_page() {
        ob_start();
        echo '<section class="asm-settings-card" id="asm-related-members-pro"><h2 class="asm-product-heading">';self::plugin_badge('atshift Members Pro');echo ('</h2>' . '<p>' . esc_html__('Add tools for reviewing posts, sending announcements, and sharing responsibilities among staff.', 'atshift-members') . '</p>' . '<ul class="asm-benefits">');
        echo ('<li>' . '<strong>' . esc_html__('Post Approval', 'atshift-members') . '</strong>' . '<br>' . esc_html__('Review content before publication. Supports sequential reviews, revision requests, resubmission, and approval history.', 'atshift-members') . '</li>');
        echo ('<li>' . '<strong>' . esc_html__('Announcement Emails and Scheduling', 'atshift-members') . '</strong>' . '<br>' . esc_html__('Email announcement titles and links to members who have opted in. Combine multiple announcements into one email or schedule delivery.', 'atshift-members') . '</li>');
        echo ('<li>' . '<strong>' . esc_html__('Staff Responsibilities', 'atshift-members') . '</strong>' . '<br>' . esc_html__('Assign post approvals, announcement delivery, and CSV member invitations to individual staff members.', 'atshift-members') . '</li>');
        echo ('<li>' . '<strong>' . esc_html__('Member Invitations via CSV', 'atshift-members') . '</strong>' . '<br>' . esc_html__('Send registration invitations to a list of prospective members. Each recipient confirms the invitation and completes registration.', 'atshift-members') . '</li>' . '</ul>');
        echo ('<p class="description">' . esc_html__('Post approval applies to Member Posts, Public Pages, and Member Announcements only. It does not apply to standard WordPress posts or other post types.', 'atshift-members') . '</p>' . '<h3>' . esc_html__('Features Available with User Categories', 'atshift-members') . '</h3>' . '<p>' . esc_html__('Use atshift User Profile Fields and its Pro Add-on to create hierarchical classifications such as branches, departments, or membership types. Use these categories to assign member management responsibilities and target announcements.', 'atshift-members') . '</p>' . '<p class="description">' . esc_html__('This is a development version. Purchase and download links are not yet available.', 'atshift-members') . '</p>' . '</section>');
        echo '<section class="asm-settings-card" id="asm-related-profile-fields"><h2 class="asm-product-heading">';self::plugin_badge('atshift User Profile Fields');echo '</h2>';
        echo ('<p>' . esc_html__('A free plugin for adding fields such as phone numbers and arranging profile forms. It is optional: atshift Members can also provide basic name and biography fields on its own.', 'atshift-members') . '</p>' . '<p class="asm-product-actions">' . '<a class="button" href="https://wordpress.org/plugins/atshift-user-profile-fields/" target="_blank" rel="noopener noreferrer">' . esc_html__('Download from WordPress.org', 'atshift-members') . '</a>' . '<a class="button" href="https://plugins.at-shift.net/user-profile-fields/" target="_blank" rel="noopener noreferrer">' . esc_html__('Official Website', 'atshift-members') . '</a>' . '</p>');
        echo '<h2 class="asm-product-heading asm-product-addon">';self::plugin_badge('atshift User Profile Fields Pro');echo '</h2>';
        echo ('<p>' . esc_html__('Create hierarchical user classifications, such as branches, departments, or membership types. This paid add-on requires the free atshift User Profile Fields plugin.', 'atshift-members') . '</p>' . '<p>' . esc_html__('Together with atshift Members, it lets you choose posting permissions by user category. Add atshift Members Pro to delegate member management by category and send targeted announcements.', 'atshift-members') . '</p>' . '<p class="asm-product-actions">' . '<a class="button" href="https://plugins.at-shift.net/pro/" target="_blank" rel="noopener noreferrer">').(Audience::api()?esc_html__('Official Website', 'atshift-members'):esc_html__('Purchase and Official Website', 'atshift-members')).'</a></p></section>';
        echo '<section class="asm-settings-card" id="asm-related-freeform-login"><h2 class="asm-product-heading">';self::plugin_badge('atshift Freeform Login');echo '</h2>';
        echo ('<p>' . esc_html__('An optional plugin for styling your login page to match your site and enabling passkey login. It is not required for member registration.', 'atshift-members') . '</p>' . '<p class="asm-product-actions">' . '<a class="button" href="https://wordpress.org/plugins/atshift-freeform-login/" target="_blank" rel="noopener noreferrer">' . esc_html__('Download from WordPress.org', 'atshift-members') . '</a>' . '<a class="button" href="https://plugins.at-shift.net/freeform-login/" target="_blank" rel="noopener noreferrer">' . esc_html__('Official Website', 'atshift-members') . '</a>' . '</p>');
        echo '<h2 class="asm-product-heading asm-product-addon">';self::plugin_badge('atshift Freeform Login Pro');echo '</h2>';
        echo ('<p>' . esc_html__('Adds more design controls to the free atshift Freeform Login plugin. Use a custom logo and adjust the form\'s position, opacity, border, corner radius, and shadow. Button borders and shadows can also be customized.', 'atshift-members') . '</p>' . '<p class="asm-product-actions">' . '<a class="button" href="https://plugins.at-shift.net/freeform-login/pro/" target="_blank" rel="noopener noreferrer">').(function_exists('atshift_freeform_login_pro_features_available')&&atshift_freeform_login_pro_features_available()?esc_html__('Official Website', 'atshift-members'):esc_html__('Purchase and Official Website', 'atshift-members')).'</a></p></section>';
        echo wp_kses_post(self::product_copy(ob_get_clean()));
    }
    public function posting_page() {
        $categories=Posting::categories();$available=Audience::api();
        $this->form('posting','asm-save-posting');
        echo '<input type="hidden" name="posting_stamp" value="'.esc_attr(Posting::stamp()).('">' . '<section class="asm-settings-card">' . '<h2>' . esc_html__('Permissions by Post Type', 'atshift-members') . '</h2>' . '<p>' . esc_html__('Choose which members can create content for each post type registered on this site. All Members means members whose status is Active. By default, only standard WordPress posts are available to all members.', 'atshift-members') . '</p>' . '<p class="description">' . esc_html__('Members with permission can create, edit, publish, and delete their own content. They gain no permission to edit other people\'s content. These settings do not change who can read content. Published standard WordPress posts remain visible to the public.', 'atshift-members') . '</p>');
        foreach(Posting::types() as $name=>$type){
            $rule=Posting::rule($name);$mode=$rule['mode']??'none';$selected=is_array($rule['groups']??null)?$rule['groups']:[];
            echo '<div class="asm-posting-rule"><h3>'.esc_html($type->label).' <code>'.esc_html($name).'</code></h3><p><label for="asm-posting-'.esc_attr($name).('">' . esc_html__('Members Allowed to Post', 'atshift-members') . '</label>' . ' ' . '<select id="asm-posting-').esc_attr($name).'" name="posting['.esc_attr($name).'][mode]" data-asm-posting-mode><option value="all" '.selected($mode,'all',false).('>' . esc_html__('All Members', 'atshift-members') . '</option>' . '<option value="none" ').selected($mode,'none',false).('>' . esc_html__('No Members', 'atshift-members') . '</option>');
            if($available)echo '<option value="groups" '.selected($mode,'groups',false).('>' . esc_html__('Selected Categories', 'atshift-members') . '</option>');
            elseif($mode==='groups')echo ('<option value="keep" selected>' . esc_html__('Keep the current category settings', 'atshift-members') . '</option>');
            echo '</select></p>';
            if($available){
                echo '<details class="asm-disclosure asm-posting-groups" '.($mode==='groups'?'open':'').('>' . '<summary>' . esc_html__('Allowed User Categories', 'atshift-members') . '</summary>' . '<p class="description">' . esc_html__('If you select multiple categories, membership in any one of them is sufficient.', 'atshift-members') . '</p>' . '<div class="asm-choice-list">');
                foreach($categories as $key=>$category)echo '<p><label><input type="checkbox" name="posting['.esc_attr($name).'][groups][]" value="'.esc_attr($key).'" '.checked(in_array($key,$selected,true),true,false).'>'.esc_html($category['label']).'</label></p>';
                foreach(array_diff($selected,array_keys($categories)) as $missing)echo '<p><label><input type="checkbox" name="posting['.esc_attr($name).'][groups][]" value="'.esc_attr($missing).('" checked>' . esc_html__('Unavailable category (', 'atshift-members')).esc_html($missing).')</label></p>';
                /* translators: %s: User Profile Fields Pro plugin badge. */
                if(!$categories)echo '<p>'.wp_kses_post(sprintf(esc_html__('Create user categories in %s.', 'atshift-members'),self::badge_html('atshift User Profile Fields Pro'))).'</p>';
                echo '</div>';
                if(Posting::hierarchy_available())echo '<p><label><input type="checkbox" name="posting['.esc_attr($name).'][children]" value="1" '.checked(!empty($rule['children']),true,false).('>' . esc_html__('Include child categories', 'atshift-members') . '</label>' . '</p>');
                elseif(!empty($rule['children']))echo '<input type="hidden" name="posting['.esc_attr($name).'][children]" value="1">';
                echo '</details>';
            }
            echo '</div>';
        }
        echo '</section></form>';
        self::save_action('asm-save-posting',__('Posting Settings', 'atshift-members'),__('Save Posting Settings', 'atshift-members'),__('Save permissions for all post types. Removing permission does not delete existing content.', 'atshift-members'));
        wp_enqueue_script('asm-posting-admin',plugins_url('assets/posting.js',dirname(__DIR__).'/atshift-members.php'),[],filemtime(dirname(__DIR__).'/assets/posting.js'),true);
    }
    public function members_page() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin display/filter parameters; mutation handlers separately require nonce and object capability checks.
        $id=absint($_GET['member_id']??0);
        if($id){$this->member_detail($id);return;}
        $result=get_transient('asm_member_result_'.get_current_user_id());
        /* translators: Numbered placeholders contain field identifiers, plugin names, paths, or counts. */
        if(is_array($result)){delete_transient('asm_member_result_'.get_current_user_id());echo '<div class="notice '.(!empty($result['failed'])?'notice-warning':'notice-success').' inline"><p>'.sprintf(esc_html__('Members updated: %1$d / Unchanged: %2$d / Could not update: %3$d', 'atshift-members'),(int)$result['changed'],(int)$result['unchanged'],(int)$result['failed']).'</p></div>'; }
        if(Scope::limited())echo ('<p>' . esc_html__('Only members within your assigned scope are shown.', 'atshift-members') . '</p>');
        echo ('<details class="asm-disclosure" id="asm-member-status-help">' . '<summary>' . esc_html__('About Member Status and Changes', 'atshift-members') . '</summary>' . '<ul class="ul-disc">' . '<li>' . '<strong>' . esc_html__('Active', 'atshift-members') . '</strong>' . esc_html__(': Can log in and use member features.', 'atshift-members') . '</li>' . '<li>' . '<strong>' . esc_html__('Pending Approval', 'atshift-members') . '</strong>' . esc_html__(': Registration is awaiting review. The member cannot log in yet. Change the status to Active after reviewing their information.', 'atshift-members') . '</li>' . '<li>' . '<strong>' . esc_html__('Suspended', 'atshift-members') . '</strong>' . esc_html__(': Access is temporarily suspended. The member cannot log in, but their information and content are retained. Change the status back to Active to restore access.', 'atshift-members') . '</li>' . '</ul>' . '<p>' . esc_html__('Changing a member\'s status sends them a notification email and ends any active login sessions.', 'atshift-members') . '</p>' . '<p>' . esc_html__('The Available Post Types column shows where each member can create content. Site administrators can change these permissions in Posting Settings.', 'atshift-members') . '</p>' . '</details>');
        require_once __DIR__.'/class-member-list-table.php';$table=new Member_List_Table();$table->prepare_items();
        echo '<form method="get"><input type="hidden" name="page" value="atshift-members">';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin display/filter parameters; mutation handlers separately require nonce and object capability checks.
        if(is_string($_GET['state']??null))echo '<input type="hidden" name="state" value="'.esc_attr(sanitize_key($_GET['state'])).'">';
        $table->search_box(__('Search Members', 'atshift-members'),'asm-members');echo '</form>';$table->views();
        $this->form('bulk_state','asm-member-bulk');$table->display();echo '</form>';
    }
    private function member_detail($id) {
        $user=get_userdata($id);
        if(!$user||!Members::managed($user)||!Scope::can_manage($id,'view'))wp_die(esc_html__('You cannot view this member\'s settings.', 'atshift-members'),'',['response'=>403]);
        $this->form('member_settings','asm-member-state');
        echo '<input type="hidden" name="user_id" value="'.(int)$id.'">';
        echo '<section class="asm-settings-card asm-member-summary"><header class="asm-member-identity"><h2 class="asm-member-name">'.esc_html($user->display_name).'</h2><p class="asm-member-email">'.esc_html($user->user_email).'</p></header>';
        $state=get_user_meta($id,'_asm_state',true);
        $state_badge=['active'=>[__('Currently Active', 'atshift-members'),'active'],'suspended'=>[__('Currently Suspended', 'atshift-members'),'suspended'],'pending'=>[__('Pending Approval', 'atshift-members'),'pending']][$state]??[Members::state_label($state),'other'];
        echo ('<div class="asm-member-status">' . '<h3>' . esc_html__('Member Status', 'atshift-members') . '</h3>' . '<span class="asm-status-badge asm-status-badge-').esc_attr($state_badge[1]).'">'.esc_html($state_badge[0]).'</span></div>';
        if(!is_wp_error(Members::state_error($id))){
            echo '<input type="hidden" name="state_before" value="'.esc_attr($state).('">' . '<div class="asm-member-state-controls">' . '<label for="asm-member-state-select">' . esc_html__('Change status:', 'atshift-members') . '</label>' . '<select id="asm-member-state-select" name="state" required>');
            foreach(['active','suspended','pending'] as $value)echo '<option value="'.esc_attr($value).'" '.selected($state,$value,false).'>'.esc_html(Members::state_label($value)).'</option>';
            echo ('</select>' . '</div>' . '<div class="asm-pro-tip asm-warning asm-posting-guidance">' . '<p>' . esc_html__('Saving a status change sends the member a notification email and ends any active login sessions.', 'atshift-members') . '</p>' . '</div>');
            self::save_action('asm-member-state',__('Member Information', 'atshift-members'),__('Save Changes', 'atshift-members'),__('Save this member\'s status.', 'atshift-members'));
        }
        if(!is_wp_error(Members::role_error($id))){
            $role=$user->roles[0];
            echo ('<div class="asm-member-role-section">' . '<h3>' . esc_html__('Member Role', 'atshift-members') . '</h3>' . '<input type="hidden" name="role_before" value="').esc_attr($role).('">' . '<div class="asm-member-state-controls">' . '<label for="asm-member-role-select">' . esc_html__('Change role:', 'atshift-members') . '</label>' . '<select id="asm-member-role-select" name="member_role">');
            foreach(['asm_member'=>__('Member (Posting Permissions)', 'atshift-members'),'asm_operator'=>__('Site Operator', 'atshift-members')] as $value=>$label)echo '<option value="'.esc_attr($value).'" '.selected($role,$value,false).'>'.esc_html($label).'</option>';
            echo ('</select>' . '</div>' . '<p class="description">' . esc_html__('Site operators can approve members and change their status. They can also be changed back to members. Changing a role ends the user\'s active login sessions.', 'atshift-members') . '</p>' . '</div>');
            self::save_action('asm-member-state',__('Member Information', 'atshift-members'),__('Save Changes', 'atshift-members'),__('Save the member status and role changes made on this screen.', 'atshift-members'));
        }
        echo ('<div class="asm-member-posting-section">' . '<div class="asm-member-posting">' . '<h3>' . esc_html__('Available Post Types', 'atshift-members') . '</h3>');
        $count=0;$color=0;
        foreach(Posting::types() as $type){
            if(!Posting::allowed($type->name,$id))continue;
            $tone=$color++%6;
            $label=$type->label.($type->name==='post'?__(' (default)', 'atshift-members'):'');
            echo '<span class="asm-posting-badge asm-posting-badge-'.(int)$tone.'">'.esc_html($label).'</span>';
            $count++;
        }
        if(!$count)echo ('<span>' . esc_html__('No posting permissions.', 'atshift-members') . '</span>');
        echo '</div><div class="asm-pro-tip asm-warning asm-posting-guidance"><p>'.esc_html(current_user_can('manage_options')?__('Change the allowed post types in Posting Settings.', 'atshift-members'):__('Ask a site administrator to change the allowed post types.', 'atshift-members')).'</p></div></div></section>';
        do_action('atshift_members_member_controls',$user);
        echo '</form>';
        $api=Audience::api();
        if(!Scope::ready()||!is_callable($api['set_memberships_in_transaction']??null))$this->member_integration_guide();
    }
    private function member_integration_guide() {
        echo ('<section class="asm-settings-card asm-member-integration-guide">' . '<h2>' . esc_html__('User Categories and Posting Permissions', 'atshift-members') . '</h2>');
        if(Audience::api())self::integration_description('posting');else self::available_integration('posting');
        if(Scope::ready())self::integration_description('categories');else self::available_integration('categories');
        echo '</section>';
    }
    public function pages_page() {
        echo ('<section class="asm-settings-card">' . '<h2>' . esc_html__('Member Pages', 'atshift-members') . '</h2>');
        echo '<p class="description">'.esc_html(__('Create the five pages used for registration and account management. Click Create Member Pages to add all five. Pages that already exist are left unchanged; only missing pages are created.', 'atshift-members')).'</p>';
        echo '<p class="description">'.esc_html(__('These pages are published in WordPress, but that does not make members\' personal information public. The plugin checks access and displays account details, editing forms, and withdrawal forms only to the account owner. Leave these pages published.', 'atshift-members')).'</p>';
        $descriptions=['register'=>__('Prospective members enter their email address here, then follow the confirmation email to continue registration.', 'atshift-members'),'account'=>__('Logged-in members can view their information here and proceed to edit it or close their account.', 'atshift-members'),'edit'=>__('Members can edit their name, biography, and other information here. Changing an email address requires confirmation of the new address.', 'atshift-members'),'reset'=>__('Members who have forgotten their password can request a password reset email here.', 'atshift-members'),'withdraw'=>__('Members can close their account here. Their content is deleted by default, but they can choose contributions to transfer to the site operator.', 'atshift-members')];
        $ids=get_option('asm_pages',[]);
        foreach(Screens::definitions() as $key=>$def){echo '<details class="asm-disclosure"><summary>'.esc_html($def[0]).'</summary><p>'.esc_html($descriptions[$key]??__('A page for member account procedures.', 'atshift-members')).('</p>' . '<p>' . esc_html__('To create a page yourself, paste this shortcode into its content: ', 'atshift-members') . '<code>' . '[').esc_html($def[1]).']</code></p>';
            if(!empty($ids[$key])&&get_post($ids[$key]))echo '<p><a href="'.esc_url(Screens::url($key)).('" target="_blank" rel="noopener">' . esc_html__('View Page', 'atshift-members') . '</a>' . ' · ' . '<a href="').esc_url(get_edit_post_link($ids[$key])).('">' . esc_html__('Edit Page', 'atshift-members') . '</a>' . '</p>');
            if(in_array($key,['register','edit','account'],true)){
                echo '<hr style="margin:20px 0;border:0;border-top:1px solid #dcdcde">';
                if(Registration::uses_profile_plugin()||Registration::passkey_integration_available())self::integration_description('profile');
                if(!Registration::uses_profile_plugin()||!Registration::passkey_integration_available())echo ('<h3 class="asm-extension-heading">' . esc_html__('Extend Your Site Further', 'atshift-members') . '</h3>');
                if(!Registration::uses_profile_plugin())self::available_integration('profile');
                if(!Registration::passkey_integration_available()){
                    self::passkey_tip();
                }
            }
            echo '</details>';
        }
        $this->form('pages','asm-save-pages');echo '</form></section>';self::save_action('asm-save-pages',__('Member Page Setup', 'atshift-members'),__('Create Member Pages', 'atshift-members'),__('Create all missing member pages. Existing pages are left unchanged.', 'atshift-members'));
        $this->files_page();
    }
    public function registration_page() {
        $this->form('settings','asm-save-settings');echo ('<section class="asm-settings-card">' . '<h2>' . esc_html__('Accepting Member Registrations', 'atshift-members') . '</h2>');
        echo ('<p class="description">' . esc_html__('Decide whether new members can start using the site immediately. Approval is unnecessary if the site is open to everyone.', 'atshift-members') . '</p>');
        echo ('<p class="description">' . esc_html__('Turning this off stops new registration requests. People who have already received a confirmation email can still complete registration before the link expires. Existing members can continue to log in.', 'atshift-members') . '</p>');
        echo ('<fieldset>' . '<legend class="screen-reader-text">' . esc_html__('New Member Registrations', 'atshift-members') . '</legend>' . '<p>' . '<label>' . '<input type="radio" name="enabled" value="1" ').checked((bool)Registration::option('enabled'),true,false).('>' . esc_html__('On (accept new registration requests)', 'atshift-members') . '</label>' . '</p>' . '<p>' . '<label>' . '<input type="radio" name="enabled" value="0" ').checked((bool)Registration::option('enabled'),false,false).('>' . esc_html__('Off (do not accept new registration requests)', 'atshift-members') . '</label>' . '</p>' . '</fieldset>');
        echo ('<h2>' . esc_html__('Registration Approval', 'atshift-members') . '</h2>' . '<p class="description">' . esc_html__('Enable approval below if your organization needs to check membership eligibility.', 'atshift-members') . '</p>' . '<p class="description">' . esc_html__('When enabled, new members must wait for review. Set their status to Active in Member Management to grant access. If unsure, you can start with this unchecked.', 'atshift-members') . '</p>' . '<p>' . '<label>' . '<input type="checkbox" name="approval" value="1" ').checked(Registration::option('approval'),true,false).('>' . esc_html__('Require operator approval after registration', 'atshift-members') . '</label>' . '</p>' . '</section>');
        echo ('<section class="asm-settings-card" id="asm-profile-form-settings">' . '<h2>' . esc_html__('Registration and Editing Forms', 'atshift-members') . '</h2>');
        echo ('<p>' . esc_html__('Profile information collected during registration. Members can view it on Your Account Information and update it on Edit Account Information.', 'atshift-members') . '</p>');
        $api=Registration::profile_api();$fields=call_user_func($api['fields'],Registration::fields());
        echo ('<h3>' . esc_html__('Required Account Registration and Editing Fields', 'atshift-members') . '</h3>' . '<ul class="ul-disc">' . '<li>' . esc_html__('Email address (required; verified by confirmation email)', 'atshift-members') . '</li>' . '<li>' . esc_html__('Password (required; set during registration)', 'atshift-members') . '</li>' . '</ul>');
        $profile_api=Registration::api();
        $configured_fields=$profile_api&&is_callable($profile_api['settings_fields']??null)?call_user_func($profile_api['settings_fields']):[];
        if(Registration::uses_profile_plugin()){
            echo ('<h3>' . esc_html__('Profile Fields from the Integration', 'atshift-members') . '</h3>' . '<p>' . esc_html__('The profile configured in atshift User Profile Fields is used for registration and account editing.', 'atshift-members') . '</p>');
            if($configured_fields){
                echo '<ul class="ul-disc">';
                foreach($configured_fields as $field){
                    echo '<li>'.esc_html($field['label']);
                    if(!empty($field['classification_linked'])){echo esc_html__(' (user classification) ', 'atshift-members');self::plugin_badge('atshift User Profile Fields Pro');}
                    if(($field['status']??'')==='disconnected'){echo ' ';self::plugin_badge('atshift User Profile Fields Pro');}
                    if(in_array($field['status']??'',['passkey','passkey-disconnected'],true)){echo ' ';self::plugin_badge('atshift Freeform Login');}
                    if(!empty($field['status_label']))echo ' <span class="asm-field-status asm-field-status-'.esc_attr($field['status']).'">'.esc_html($field['status_label']).'</span>';
                    echo '</li>';
                }
                echo ('</ul>' . '<p class="description">' . esc_html__('Excluded fields are omitted from member forms. Integration Not Implemented means the field does not yet have an atshift Members integration. Email addresses, passwords, and saving are handled by atshift Members account procedures.', 'atshift-members') . '</p>');
            }else echo ('<p>' . esc_html__('Create profile fields in atshift User Profile Fields.', 'atshift-members') . '</p>');
        }else{
            echo ('<h3>' . esc_html__('Profile Fields for Registration and Editing', 'atshift-members') . '</h3>');
            if($fields){
                echo '<ul class="ul-disc">';
                foreach($fields as $key=>$field){
                    echo '<li>'.esc_html($field['label']??$key);
                    if(!empty($field['required']))echo ' <span class="description">'.esc_html__(' (required)', 'atshift-members').'</span>';
                    echo '</li>';
                }
                echo '</ul>';
            }
            echo ('<p>' . esc_html__('Without a profile plugin integration, registration requires only an email address and password by default. Add any additional profile fields using the settings below.', 'atshift-members') . '</p>');
        }
        if(Registration::passkey_integration_available()&&!Registration::uses_profile_passkeys())echo ('<h3>' . esc_html__('Authentication Features Available After Registration', 'atshift-members') . '</h3>' . '<p>' . esc_html__('Members can register and manage passkeys. After signing up, add a passkey on Edit Account Information to use it for future logins.', 'atshift-members') . '</p>');
        echo '<details class="asm-disclosure"'.(Registration::uses_profile_plugin()?' open':'').('>' . '<summary>' . esc_html__('Add or Change Fields', 'atshift-members') . '</summary>');
        if(Registration::api()){
            echo ('<p>' . esc_html__('The profile layout, field order, and conditional rules are used automatically. You do not need to select fields again or grant additional front-end display or self-editing permissions here.', 'atshift-members') . '</p>' . '<p>' . '<a class="button" href="').esc_url(admin_url('admin.php?page=atshift-user-profile-fields')).('">' . esc_html__('Edit Profile Fields in atshift User Profile Fields', 'atshift-members') . '</a>' . '</p>');
        } else {
            echo ('<p>' . esc_html__('To use custom fields defined in code, provide the integration that renders and saves them in registration and editing forms, then specify their identifiers. Leave this empty if no additional fields are needed.', 'atshift-members') . '</p>');
        /* translators: Numbered placeholders contain field identifiers, plugin names, paths, or counts. */
            if(defined('ASM_PUBLIC_FIELDS'))echo ('<p>' . sprintf(esc_html__('The fields are specified by %s in the WordPress configuration file (wp-config.php).', 'atshift-members'),'<code>ASM_PUBLIC_FIELDS</code>') . '</p>');
        /* translators: Numbered placeholders contain field identifiers, plugin names, paths, or counts. */
            else echo ('<p>' . '<label>' . esc_html__('Field Identifiers to Use', 'atshift-members') . '<input class="large-text" name="fields" value="').esc_attr(implode(',',Registration::selected_fields())).('">' . '</label>' . '</p>' . '<p class="description">' . esc_html__('Leave empty to add no profile fields. If needed, specify identifiers for names, nickname, display name, website URL, biography, or other supported fields.', 'atshift-members') . '</p>' . '<p class="description">' . sprintf(esc_html__('Enter field identifiers used in code, not labels such as Biography. For example, use %1$s for the nickname and %2$s for the biography. To use just these two fields, enter %3$s.', 'atshift-members'),'<code>nickname</code>','<code>bio</code>','<code>nickname,bio</code>') . '</p>' . '<p class="description">' . esc_html__('For custom fields added in code, use the identifiers assigned when they were created. Code to render the fields in registration and editing forms and save their values is also required.', 'atshift-members') . '</p>' . '<p class="description">' . esc_html__('Click Save Registration Settings on the right to apply your changes.', 'atshift-members') . '</p>');
        }
        if(!Registration::uses_profile_plugin()||!Registration::passkey_integration_available())echo ('<hr style="margin:20px 0;border:0;border-top:1px solid #dcdcde">' . '<h3 class="asm-extension-heading">' . esc_html__('Extend Your Site Further', 'atshift-members') . '</h3>');
        if(!Registration::uses_profile_plugin())self::available_integration('profile');
        if(!Registration::passkey_integration_available()){self::passkey_tip();}
        echo '</details>';
        if(Registration::uses_profile_plugin()||Registration::passkey_integration_available()){echo '<hr style="margin:20px 0;border:0;border-top:1px solid #dcdcde">';self::integration_description('profile');}
        echo '</section>';
        echo '</form>';self::save_action('asm-save-settings',__('Registration Settings', 'atshift-members'),__('Save Registration Settings', 'atshift-members'),__('Save registration availability, approval requirements, and profile field settings.', 'atshift-members'));
        $this->turnstile_form();
        echo ('<aside class="asm-pro-tip asm-warning" aria-labelledby="asm-cache-warning-title">' . '<h2 id="asm-cache-warning-title">' . esc_html__('Check Cache Settings Before Launch', 'atshift-members') . '</h2>');
        echo ('<p>' . esc_html__('Before launching a membership site, check any page caching plugins and server caches to prevent one member\'s information from being shown to someone else. ', 'atshift-members') . '<strong>' . esc_html__('Exclude member pages from caching.', 'atshift-members') . '</strong>' . '</p>' . '</aside>');
    }
    private function turnstile_form() {
        $keys=Services::turnstile_keys();
        echo ('<details class="asm-disclosure" id="asm-turnstile-settings">' . '<summary>' . esc_html__('Spam Protection (Cloudflare Turnstile)', 'atshift-members') . '</summary>' . '<p>' . esc_html__('Helps prevent automated registration and password reset requests. Add the two keys issued by Cloudflare to enable checks on this plugin\'s request forms.', 'atshift-members') . '</p>');
        echo ('<p>' . esc_html__('Key status: ', 'atshift-members')).($keys['site']&&$keys['secret']?esc_html__('Configured', 'atshift-members'):esc_html__('Not Configured', 'atshift-members')).'</p>';
        if (Services::turnstile_locked()) {
            echo ('<p>' . esc_html__('These keys are configured on the server by the person who set up your site. Ask them to make any changes.', 'atshift-members') . '</p>');
        } else {
            // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Ordinary Cloudflare documentation/dashboard hyperlinks, not offloaded scripts or assets.
            echo ('<details class="asm-disclosure">' . '<summary>' . esc_html__('How to Get Your Keys', 'atshift-members') . '</summary>' . '<ol>' . '<li>' . '<a href="https://dash.cloudflare.com/" target="_blank" rel="noopener noreferrer">' . esc_html__('Open the Cloudflare dashboard (new tab)', 'atshift-members') . '</a>' . esc_html__('. Create an account if you do not have one.', 'atshift-members') . '</li>' . '<li>' . esc_html__('Add a Turnstile widget for this site and register the domains where it will be used.', 'atshift-members') . '</li>' . '<li>' . esc_html__('Copy the issued Site Key and Secret Key into the fields below, then save them.', 'atshift-members') . '</li>' . '</ol>' . '<p>' . '<a href="https://developers.cloudflare.com/turnstile/get-started/" target="_blank" rel="noopener noreferrer">' . esc_html__('Read Cloudflare\'s official instructions (new tab)', 'atshift-members') . '</a>' . '</p>' . '</details>');
            $this->form('turnstile','asm-save-turnstile');
            echo ('<p>' . '<label>' . esc_html__('Site Key', 'atshift-members') . '<input class="large-text" type="text" name="turnstile[site]" value="').esc_attr($keys['site']).('" maxlength="256" autocomplete="off" spellcheck="false" aria-describedby="asm-site-help">' . '</label>' . '</p>' . '<p id="asm-site-help" class="description">' . esc_html__('Used to display the verification widget on your forms.', 'atshift-members') . '</p>');
            echo ('<p>' . '<label>' . esc_html__('Secret Key', 'atshift-members') . '<input class="large-text" type="password" name="turnstile[secret]" value="" maxlength="256" autocomplete="new-password" spellcheck="false" aria-describedby="asm-secret-help">' . '</label>' . '</p>' . '<p id="asm-secret-help" class="description">' . esc_html__('A private key used to verify responses with Cloudflare. Saved values are never displayed. Leave this blank to keep the current key. If you change the site key, enter its matching secret key as well.', 'atshift-members') . '</p>');
            if ($keys['site']||$keys['secret']) echo ('<details class="asm-disclosure">' . '<summary>' . esc_html__('Delete Saved Keys', 'atshift-members') . '</summary>' . '<p>' . '<label>' . '<input type="checkbox" name="turnstile[clear]" value="1">' . esc_html__('Delete both keys', 'atshift-members') . '</label>' . '</p>' . '<p>' . esc_html__('New registration and password reset requests will be unavailable until you save new keys.', 'atshift-members') . '</p>' . '</details>');
            echo ('</form>' . '<section class="postbox asm-save-box" aria-labelledby="asm-save-turnstile-title">' . '<div class="postbox-header">' . '<h2 id="asm-save-turnstile-title">' . esc_html__('Spam Protection', 'atshift-members') . '</h2>' . '</div>' . '<div class="inside">' . '<p>' . esc_html__('Save your Cloudflare Turnstile keys separately from the registration settings.', 'atshift-members') . '</p>' . '</div>' . '<div class="asm-save-footer">' . '<button type="submit" class="button button-primary" form="asm-save-turnstile">' . esc_html__('Save Turnstile Settings', 'atshift-members') . '</button>' . '</div>' . '</section>');
        }
        echo ('<p class="description">' . esc_html__('Configured means the keys have been saved. Test an actual request form afterward to verify connectivity and domain settings.', 'atshift-members') . '</p>' . '</details>');
    }
    public function mail_page() {
        $this->form('mail','asm-save-mail');echo ('<section class="asm-settings-card">' . '<h2>' . esc_html__('Sender Information', 'atshift-members') . '</h2>');
        echo ('<p class="description">' . esc_html__('Use your organization\'s or site\'s name so recipients can recognize the sender, for example, Example Association Office.', 'atshift-members') . '</p>');
        echo ('<p>' . '<label>' . esc_html__('Sender Name ', 'atshift-members') . '<input name="sender" value="').esc_attr(get_option('asm_sender_name','')).('">' . '</label>' . '</p>' . '<p class="description">' . esc_html__('Leave blank to use the WordPress sender name.', 'atshift-members') . '</p>');
        /* translators: Numbered placeholders contain field identifiers, plugin names, paths, or counts. */
        echo ('<p>' . '<label for="asm-mail-signature">' . esc_html__('Shared Signature', 'atshift-members') . '</label>' . '<textarea id="asm-mail-signature" name="signature" class="large-text" rows="6" aria-describedby="asm-mail-signature-help" placeholder="' . esc_attr__('{site} Office
Contact: info@example.com
https://example.com/', 'atshift-members') . '">').esc_textarea(get_option('asm_mail_signature','')).('</textarea>' . '</p>' . '<p id="asm-mail-signature-help" class="description">' .
        /* translators: 1: Signature example, 2: Site name placeholder. */
        sprintf(esc_html__('Enter your organization name and contact details. For example, %1$s uses %2$s as a placeholder for the site name. The signature is appended to all emails sent by this plugin, including atshift Members Pro announcements. You do not need to repeat it in each template. Leave this field empty to omit the signature.', 'atshift-members'),'<code>'.esc_html__('{site} Office', 'atshift-members').'</code>','<code>{site}</code>') . '</p>');
        /* translators: Numbered placeholders contain field identifiers, plugin names, paths, or counts. */
        echo ('<p>' . sprintf(esc_html__('In email subjects and bodies, %1$s is replaced with the site name and %2$s with a link to the relevant account procedure. Keep %2$s in emails that provide a link.', 'atshift-members'),'<code>{site}</code>','<code>{link}</code>') . '</p>' . '</section>');
        $help=[
            'verify'=>__('Sent to the submitted email address with a confirmation link when a registration request is accepted.', 'atshift-members'),
            'welcome'=>__('Sent when registration is complete and no approval is required.', 'atshift-members'),
            'pending'=>__('Tells the member that their account is awaiting approval.', 'atshift-members'),
            'activated'=>__('Sent when approval or removal of a suspension activates the account.', 'atshift-members'),
            'withdrawn'=>__('Sent to the former member when account closure and deletion have finished.', 'atshift-members'),
            'suspended'=>__('Sent when an operator suspends a member\'s account.', 'atshift-members'),
            'reset'=>__('Sent to the member with a reset link when a password reset is requested.', 'atshift-members'),
            'password_changed'=>__('Confirms that the password has changed. The password itself is never included.', 'atshift-members'),
            'email_verify'=>__('Verifies ownership of the new address when a member changes their email.', 'atshift-members'),
            'email_changed'=>__('Notifies both the old and new addresses when an email change is complete.', 'atshift-members'),
        ];
        $saved=get_option('asm_mail_templates',[]);
        $pro_heading=false;
        foreach(Mail::defaults() as $type=>$def){if($type==='invite')continue;
            if(($def['plugin']??'')==='atshift Members Pro'&&!$pro_heading){$pro_heading=true;echo '<hr><h3>';self::plugin_badge('atshift Members Pro');echo (esc_html__(' Email Notifications', 'atshift-members') . '</h3>' . '<p>' . esc_html__('Notifies the member only when a user category change is finally approved or rejected. No email is sent on submission, intermediate approval, or cancellation.', 'atshift-members') . '</p>' . '<p>' . '<code>' . '{category}' . '</code>' . esc_html__(': Classification name; ', 'atshift-members') . '<code>' . '{selection}' . '</code>' . esc_html__(': Requested classification; ', 'atshift-members') . '<code>' . '{reason}' . '</code>' . esc_html__(': Rejection reason', 'atshift-members') . '</p>');}
            $t=$saved[$type]??$def;echo '<details class="asm-disclosure"><summary>'.esc_html($def['label']).'</summary><p class="description">'.esc_html($help[$type]??__('Explain what happened and what the recipient should do next.', 'atshift-members')).('</p>' . '<p>' . '<label>' . esc_html__('Subject', 'atshift-members') . '<input class="large-text" name="templates[').esc_attr($type).'][subject]" value="'.esc_attr($t['subject']).('" required>' . '</label>' . '</p>' . '<p>' . '<label>' . esc_html__('Body', 'atshift-members') . '<textarea class="large-text" rows="5" name="templates[').esc_attr($type).'][body]" required>'.esc_textarea($t['body']).'</textarea></label></p></details>';}
        echo '</form>';self::save_action('asm-save-mail',__('Email Settings', 'atshift-members'),__('Save Email Settings', 'atshift-members'),__('Save the sender name, shared signature, and all email subjects and bodies.', 'atshift-members'));
    }
    private function storage_form($kind,$config) {
        if(defined('ASM_PRIVATE_DIR')||defined('ASM_PUBLIC_ROOT')||defined('ASM_FILE_STORE')){
            return;
        }
        $this->form('file_storage','asm-file-storage-'.$kind);
        echo '<input type="hidden" name="storage[kind]" value="'.esc_attr($kind).'">';
        echo ('<p>' . '<label>' . esc_html__('Absolute Path to the Public Directory Containing WordPress', 'atshift-members') . '<input class="large-text" name="storage[public]" value="').esc_attr($config['public']).'" placeholder="/home/public_html" required></label></p>';
        echo ('<p>' . '<label>' . esc_html__('Absolute Path to the Members-Only Storage Directory', 'atshift-members') . '<input class="large-text" name="storage[private]" value="').esc_attr($config['kind']===$kind?$config['private']:'').'" placeholder="'.($kind==='network'?'/mnt/member-files':'/home/private-folder').'" required></label></p>';
        echo ('<p>' . esc_html__('Before saving, the plugin checks that it can create, read, and delete a test file in the specified directory.', 'atshift-members')).($kind==='network'?esc_html__('A small marker file is also placed in shared storage to verify the connection.', 'atshift-members'):'').('</p>' . '<p>' . '<button class="button button-primary">' . esc_html__('Check and Save Storage Location', 'atshift-members') . '</button>' . '</p>' . '</form>');
    }
    public function files_page() {
        echo ('<details class="asm-settings-card asm-disclosure" id="asm-file-storage">' . '<summary>' . esc_html__('Storage for Member Documents and Images', 'atshift-members') . '</summary>');
        echo ('<p>' . esc_html__('Documents, reports, images, and videos intended only for members need a storage location that is not publicly accessible. This includes PDF, Word, Excel, and PowerPoint files.', 'atshift-members') . '</p>');
        $config=Files::storage_config();
        echo ('<p>' . esc_html__('Use a private directory on the web server or a file server/NAS share already mounted on that server.', 'atshift-members') . '</p>');
        echo ('<details class="asm-disclosure">' . '<summary>' . esc_html__('Use the Web Server Running WordPress', 'atshift-members') . '</summary>');
        echo ('<p>' . esc_html__('Create the members-only directory outside the server\'s public directory first. Set the path here or in wp-config.php. The plugin does not create the directory automatically.', 'atshift-members') . '</p>');
        /* translators: Numbered placeholders contain field identifiers, plugin names, paths, or counts. */
        echo ('<details class="asm-pro-tip asm-disclosure asm-storage-example">' . '<summary>' . esc_html__('Example Configuration for wp-config.php', 'atshift-members') . '</summary>' . '<p>' . sprintf(esc_html__('If the public directory is %1$s, create a members-only directory outside it, such as %2$s. Replace these examples with the actual paths on your server.', 'atshift-members'),'<code>/home/public_html</code>','<code>/home/private-folder</code>') . '</p>');
        echo '<pre><code>'.esc_html("define('ASM_PUBLIC_ROOT', '/home/public_html');\ndefine('ASM_PRIVATE_DIR', '/home/private-folder');").'</code></pre>';
        /* translators: Numbered placeholders contain field identifiers, plugin names, paths, or counts. */
        echo ('<p>' . sprintf(esc_html__('%1$s specifies the public directory containing WordPress, and %2$s specifies members-only storage. WordPress must be able to read and write in the private directory.', 'atshift-members'),'<code>ASM_PUBLIC_ROOT</code>','<code>ASM_PRIVATE_DIR</code>') . '</p>' . '<p>' . sprintf(esc_html__('Add these settings to %s before the comment that says to stop editing. If they already exist, edit them instead of adding duplicates.', 'atshift-members'),'<code>wp-config.php</code>') . '</p>' . '<p>' . esc_html__('Make sure no other server configuration exposes the members-only directory to the public.', 'atshift-members') . '</p>' . '</details>');
        $this->storage_form('local',$config);
        echo '</details>';
        echo ('<details class="asm-disclosure">' . '<summary>' . esc_html__('Use a File Server or NAS', 'atshift-members') . '</summary>');
        echo ('<ol>' . '<li>' . esc_html__('Make the shared directory accessible from the WordPress server. If connecting from outside your organization, arrange a connection such as a VPN on the server first.', 'atshift-members') . '</li>' . '<li>' . esc_html__('Grant a dedicated account read, write, and delete permissions, then mount the shared directory on the server.', 'atshift-members') . '</li>' . '<li>' . esc_html__('Prepare a non-public directory exclusively for member files and specify its path on the server.', 'atshift-members') . '</li>' . '</ol>' . '<p>' . esc_html__('Configure IP addresses and NAS credentials on the server. atshift Members uses the mounted directory. It does not set up VPN or cloud API connections, import existing documents, or migrate files when storage locations change.', 'atshift-members') . '</p>');
        $this->storage_form('network',$config);
        echo '</details>';
        echo ('<p>' . esc_html__('Once storage is configured, media controls in posts and the Media screen offer Public and Members Only options. Members-only images, galleries, and videos appear within posts; documents use download links. Only authorized members can access them.', 'atshift-members') . '</p>');
        echo ('<div class="asm-pro-tip asm-warning">' . '<p>' . esc_html__('Files marked Public are saved to the standard WordPress media directory. Anyone who knows the file URL can open them directly, even if the post is restricted to members. Storage does not change automatically when a post\'s visibility changes.', 'atshift-members') . '</p>' . '</div>');
        if(Files::upload_available()){
            $store=defined('ASM_FILE_STORE')?ASM_FILE_STORE:'local';
            echo ('<p class="asm-storage-current">' . '<strong>' . esc_html__('Current storage: ', 'atshift-members')).esc_html($store==='local'?($config['kind']==='network'?__('File Server / NAS (Mounted Share)', 'atshift-members'):__('WordPress Web Server (Private Directory)', 'atshift-members')):$store).'</strong>';
            if(defined('ASM_PRIVATE_DIR')||defined('ASM_PUBLIC_ROOT')||defined('ASM_FILE_STORE'))echo ('<br>' . esc_html__('Configured in wp-config.php', 'atshift-members'));
            if($store==='local'){$root=Files::root();if(!is_wp_error($root))echo ('<br>' . esc_html__('Storage path: ', 'atshift-members') . '<code>').esc_html($root).'</code>';}
            echo '</p>';
        }
        echo ('<p>' . esc_html__('Supported attachments include JPEG, PNG, GIF, WebP, PDF, Word (doc, docx), Excel (xls, xlsx), PowerPoint (ppt, pptx), text (txt), ZIP, and video (MP4, WebM). Upload size limits follow WordPress and server settings. Attachments added through this feature require member login even when attached to a public page.', 'atshift-members') . '</p>' . '</details>');
    }
}
