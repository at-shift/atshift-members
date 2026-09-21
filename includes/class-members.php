<?php
namespace Atshift\Membership;
defined('ABSPATH') || exit;
final class Members {
    public static function state_label($state) {return ['active'=>__('Active', 'atshift-members'),'pending'=>__('Pending Approval', 'atshift-members'),'banned'=>__('Banned', 'atshift-members'),'suspended'=>__('Suspended', 'atshift-members'),'provisioning'=>__('Registration Needs Review', 'atshift-members'),'withdrawn'=>__('Account Removed', 'atshift-members'),'withdrawing'=>__('Account Closure in Progress', 'atshift-members'),''=>__('Site Administrator', 'atshift-members')][$state]??__('Unavailable', 'atshift-members');}
    public static function roles($translate=true) {
        $base = ['read'=>true];
        foreach (['atshme_posts','atshme_pages'] as $plural) {
            foreach (['edit_','edit_published_','delete_','delete_published_','publish_'] as $prefix) $base[$prefix.$plural]=true;
        }
        add_role('atshme_member',$translate?__('User (Posting Permissions)', 'atshift-members'):'User (Posting Permissions)',$base);
        $operator = $base + ['atshme_manage_members'=>true];
        foreach (['edit_','edit_published_','delete_','delete_published_','publish_'] as $prefix) $operator[$prefix.'atshme_notices']=true;
        foreach (['atshme_posts','atshme_pages','atshme_notices'] as $plural) foreach (['edit_others_','delete_others_','read_private_','edit_private_','delete_private_'] as $prefix) $operator[$prefix.$plural]=true;
        add_role('atshme_operator',$translate?__('Site Operator', 'atshift-members'):'Site Operator',$operator);
        $admin = get_role('administrator');
        if ($admin) foreach ($operator as $cap=>$grant) $admin->add_cap($cap);
    }
    public static function hooks() {
        add_filter('authenticate',[self::class,'authenticate'],PHP_INT_MAX,3);
        add_filter('wp_authenticate_user',[self::class,'authenticate'],PHP_INT_MAX,2);
        add_filter('determine_current_user',[self::class,'current_user'],PHP_INT_MAX);
        add_filter('send_auth_cookies',[self::class,'send_cookies'],PHP_INT_MAX,6);
        add_filter('allow_password_reset',[self::class,'allow_reset'],PHP_INT_MAX,2);
        add_filter('map_meta_cap',[self::class,'caps'],PHP_INT_MAX,4);
        add_filter('pre_option_users_can_register','__return_zero');
        add_filter('registration_errors',function($e){$e->add('atshme_registration',__('Please use the member registration page.', 'atshift-members'));return $e;},PHP_INT_MAX);
        add_action('user_profile_update_errors',[self::class,'email_edit'],PHP_INT_MAX,3);
        add_filter('wp_pre_insert_user_data',[self::class,'preserve_email'],PHP_INT_MAX,4);
        add_filter('rest_pre_insert_user',[self::class,'rest_email'],PHP_INT_MAX,2);
        add_action('after_password_reset',function($user){ Mail::send('password_changed',$user->user_email); });
        add_action('validate_password_reset',function($errors,$user){
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Additional validation hook inside WordPress password/profile processing; the caller owns its nonce/reset proof, and this hook only rejects unsafe passwords.
            if ($user instanceof \WP_User && self::managed($user) && isset($_POST['pass1']) && is_string($_POST['pass1'])) {
                // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Additional validation hook inside WordPress password/profile processing; the caller owns its nonce/reset proof, and this hook only rejects unsafe passwords. Password must retain its exact bytes; wp_unslash then Services::password performs length, character and breach validation.
                $check=Services::password(wp_unslash($_POST['pass1']));
                if (is_wp_error($check)) $errors->add($check->get_error_code(),$check->get_error_message());
            }
        },20,2);
        add_filter('password_change_email',function($mail,$before,$after){
            if (self::managed(get_userdata($before['ID']))) {
                $templates=get_option('atshme_mail_templates',[]);$template=$templates['password_changed']??Mail::defaults()['password_changed'];
                $replace=['{site}'=>wp_specialchars_decode(get_bloginfo('name'),ENT_QUOTES),'{link}'=>Screens::url('account')];
                $mail['subject']=strtr($template['subject'],$replace);$mail['message']=Mail::with_signature(strtr($template['body'],$replace));
            }
            return $mail;
        },10,3);
    }
    public static function managed($user) {
        return $user instanceof \WP_User && (array_intersect(['atshme_member','atshme_operator'],$user->roles) || get_user_meta($user->ID,'_atshme_state',true)!=='');
    }
    public static function active($id) {
        $user = get_userdata($id);
        return $user && self::managed($user) && get_user_meta($id,'_atshme_state',true)==='active';
    }
    public static function blocked($id) { $u=get_userdata($id); return (!$u && $id>0) || get_user_meta($id,'_atshme_custodian',true)==='1' || (self::managed($u) && !self::active($id)); }
    public static function reader() { return current_user_can('manage_options') || self::active(get_current_user_id()); }
    public static function authenticate($user, ...$unused) {
        return $user instanceof \WP_User && self::blocked($user->ID) ? new \WP_Error('atshme_unavailable',__('This account is currently unavailable. Please contact the site administrator.', 'atshift-members')) : $user;
    }
    public static function current_user($id) { return $id && self::blocked($id) ? 0 : $id; }
    public static function send_cookies($send,$expire,$expiration,$id,$scheme,$token) { return $send && !self::blocked($id); }
    public static function allow_reset($allowed,$id) { return self::blocked($id) ? false : $allowed; }
    public static function caps($caps,$cap,$id,$args) {
        if (self::blocked($id) && $cap!=='exist') return ['do_not_allow'];
        return $caps;
    }
    /** Persisted obligations must still block removal if the Pro add-on is disabled. */
    public static function handoff_error($id){
        $u=get_userdata($id);if(!$u)return null;
        $needed=false;
        foreach(['atshme_review','atshme_send_notices','atshme_import_members'] as $cap)if(!empty($u->caps[$cap]))$needed=true;
        $scope=get_user_meta($id,Scope::META,true);if(is_array($scope)&&($scope['mode']??'')==='global'||!empty($scope['groups']))$needed=true;
        if(in_array($id,array_map('intval',(array)get_option('atshme_pro_reviewers',[])),true))$needed=true;
        foreach((array)get_option('atshme_category_approval',[]) as $reviewers)if(in_array($id,array_map('intval',(array)$reviewers),true))$needed=true;
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- A live handoff guard must inspect persisted Pro obligations even when the add-on is disabled; the query contains only core table identifiers and fixed literals.
        $rows=$wpdb->get_results("SELECT p.post_author,p.post_type,m.meta_value FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id=p.ID AND m.meta_key IN ('_atshme_review','_atshme_category_review') WHERE p.post_status='pending' AND p.post_type IN ('atshme_review','atshme_cat_review','atshme_campaign')");
        foreach($rows as $row){$d=maybe_unserialize($row->meta_value);if(is_array($d)&&in_array($id,array_map('intval',array_slice($d['reviewers']??[],(int)($d['step']??0))),true))$needed=true;if($row->post_type==='atshme_campaign'&&(int)$row->post_author===$id)$needed=true;}
        return apply_filters('atshift_members_handoff_error',$needed?new \WP_Error('handoff',__('This person has staff responsibilities. Choose and configure a replacement in Staff and Permissions before restricting access or removing responsibilities.', 'atshift-members')):null,$id);
    }
    public static function state_error($id) {
        if (!Scope::can_manage($id,'state')) return new \WP_Error('forbidden',__('You cannot perform this action.', 'atshift-members'));
        $target = get_userdata($id);
        if (!$target || !self::managed($target) || in_array('administrator',$target->roles,true) || $id===get_current_user_id()) return new \WP_Error('forbidden',__('This account cannot be changed.', 'atshift-members'));
        if (in_array('atshme_operator',$target->roles,true) && !current_user_can('manage_options')) return new \WP_Error('forbidden',__('Administrator permissions are required to change operators.', 'atshift-members'));
        $before=get_user_meta($id,'_atshme_state',true);
        if (in_array($before,['withdrawn','withdrawing'],true)) return new \WP_Error('withdrawn',__('A closed account cannot be restored by removing its suspension.', 'atshift-members'));
        return null;
    }
    public static function bulk_state($ids,$state) {
        if(!current_user_can('atshme_manage_members') || !in_array($state,['active','suspended','pending','banned'],true))return new \WP_Error('state',__('Choose the new member status.', 'atshift-members'));
        if(!is_array($ids)||!$ids||count($ids)>50)return new \WP_Error('selection',__('Select between 1 and 50 members to update.', 'atshift-members'));
        foreach($ids as $id)if((!is_int($id)&&!is_string($id))||!preg_match('/^[1-9][0-9]*$/D',(string)$id))return new \WP_Error('selection',__('Check the selected members.', 'atshift-members'));
        $ids=array_values(array_unique(array_map('intval',$ids)));
        // Validate the entire selection before making any change or sending any notification.
        foreach($ids as $id)if(is_wp_error(self::state_error($id)))return new \WP_Error('forbidden',__('Some selected members cannot be updated. Select them again. No changes have been made.', 'atshift-members'));
        $result=['changed'=>0,'unchanged'=>0,'failed'=>0];
        foreach($ids as $id){
            $before=get_user_meta($id,'_atshme_state',true);$saved=self::set_state($id,$state);
            if(is_wp_error($saved))++$result['failed'];elseif($before===$state)++$result['unchanged'];else ++$result['changed'];
        }
        return $result;
    }
    public static function notice_editable($id) {
        $user=get_userdata($id);
        return $user&&$user->roles===['atshme_member']&&Scope::can_manage($id,'privilege')&&!in_array(get_user_meta($id,'_atshme_state',true),['withdrawn','withdrawing'],true);
    }
    public static function set_notice_permission($id,$allowed) {
        if(!is_bool($allowed)||!self::notice_editable($id))return new \WP_Error('target',__('This member\'s posting permissions cannot be changed.', 'atshift-members'));
        $user=get_userdata($id);
        foreach (['edit_','edit_published_','delete_','delete_published_','publish_'] as $prefix) {
            if($allowed)$user->add_cap($prefix.'atshme_notices');else $user->remove_cap($prefix.'atshme_notices');
        }
        atshift_members()->store->log('notice_permission',(string)$id);
        return true;
    }
    public static function role_error($id) {
        $actor=get_current_user_id();$actor_user=get_userdata($actor);
        $admin=current_user_can('manage_options')&&current_user_can('promote_user',$id);
        $operator=$actor_user&&in_array('atshme_operator',$actor_user->roles,true)&&self::active($actor)&&!Scope::limited($actor)&&Scope::can_manage($id,'privilege');
        if((!$admin&&!$operator)||self::blocked($actor))return new \WP_Error('forbidden',__('Only site administrators and operators with site-wide access can change member roles.', 'atshift-members'));
        $user=get_userdata($id);
        if(!$user||$id===$actor||count($user->roles)!==1||!in_array($user->roles[0],['atshme_member','atshme_operator'],true)||user_can($user,'manage_options')||!in_array(get_user_meta($id,'_atshme_state',true),['active','pending','suspended'],true))return new \WP_Error('role',__('This account\'s role cannot be changed.', 'atshift-members'));
        return null;
    }
    /** Save the detail form together; notify only after its database changes commit. */
    public static function save_settings($id,$input) {
        if(!is_array($input)||!Scope::can_manage($id,'view'))return new \WP_Error('forbidden',__('You cannot perform this action.', 'atshift-members'));
        global $wpdb;
        // Both native state and linked memberships are user metadata. Refuse partial saves on nontransactional storage.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Security-sensitive current state or atomic transaction/lock operation; WordPress object caching cannot provide these fresh predicates or synchronization semantics.
        $engine=$wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=%s',$wpdb->usermeta));
        if(strtoupper((string)$engine)!=='INNODB')return new \WP_Error('storage',__('The user tables must use InnoDB to save member information together. Please check with your site administrator.', 'atshift-members'));
        $store=atshift_members()->store;$lock='member-settings:'.$id;
        if(!$store->lock($lock))return new \WP_Error('busy',__('This member\'s information is being saved. Please wait a moment and try again.', 'atshift-members'));
        if(!$store->lock('staff-continuity')){$store->unlock($lock);return new \WP_Error('busy',__('This member\'s information is being saved. Please wait a moment and try again.', 'atshift-members'));}
        $committed=false;$changed=false;$linked=null;$role_changed=false;$old_role=null;$role=null;
        try {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Security-sensitive current state or atomic transaction/lock operation; WordPress object caching cannot provide these fresh predicates or synchronization semantics.
            if($wpdb->query('START TRANSACTION')===false)return new \WP_Error('storage',__('Could not start saving.', 'atshift-members'));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Security-sensitive current state or atomic transaction/lock operation; WordPress object caching cannot provide these fresh predicates or synchronization semantics.
            if($wpdb->get_results($wpdb->prepare("SELECT umeta_id FROM {$wpdb->usermeta} WHERE user_id=%d FOR UPDATE",$id))===null)return new \WP_Error('storage',__('Could not verify the member information.', 'atshift-members'));
            wp_cache_delete($id,'user_meta');
            if(!Scope::can_manage($id,'view'))return new \WP_Error('forbidden',__('You cannot perform this action.', 'atshift-members'));
            $before=get_user_meta($id,'_atshme_state',true);$state=$before;
            if(array_key_exists('state',$input)){
                $error=self::state_error($id);if(is_wp_error($error))return $error;
                $state=$input['state'];
                if(!is_string($state)||!in_array($state,['active','suspended','pending','banned'],true))return new \WP_Error('state',__('Choose the new status.', 'atshift-members'));
                if(($input['state_before']??null)!==$before)return new \WP_Error('changed',__('The member status has changed. Reopen this screen.', 'atshift-members'));
                $changed=$state!==$before;
            }
            if(array_key_exists('member_role',$input)){
                $error=self::role_error($id);if(is_wp_error($error))return $error;
                $target=get_userdata($id);$old_role=$target->roles[0];$role=$input['member_role'];
                if(!is_string($role)||!in_array($role,['atshme_member','atshme_operator'],true)||!get_role($role))return new \WP_Error('role',__('Choose Member or Site Operator.', 'atshift-members'));
                if(($input['role_before']??null)!==$old_role)return new \WP_Error('changed',__('The member role has changed. Reopen this screen.', 'atshift-members'));
                $role_changed=$role!==$old_role;
            }
            if(($changed&&$state!=='active')||($role_changed&&$role==='atshme_member')){$guard=self::handoff_error($id);if(is_wp_error($guard))return $guard;}
            if(($state==='banned'||$before==='banned')&&!current_user_can('manage_options'))return new \WP_Error('forbidden',__('Only site administrators can ban or restore a banned account.', 'atshift-members'));
            if(array_key_exists('memberships',$input)){
                $linked=apply_filters('atshift_members_save_profile_selection',new \WP_Error('integration',__('The classification integration could not be verified. Reopen this screen.', 'atshift-members')),$id,$input['memberships']);
                if(is_wp_error($linked))return $linked;
            }
            if($changed&&!update_user_meta($id,'_atshme_state',$state))return new \WP_Error('storage',__('Could not save the member status.', 'atshift-members'));
            if($role_changed){
                $caps=$target->caps;unset($caps[$old_role]);$caps[$role]=true;
                if($role==='atshme_member'){
                    // Remove operator-only direct grants and archive the scope for a later reappointment.
                    $operator_only=array_diff_key(get_role('atshme_operator')->capabilities,get_role('atshme_member')->capabilities);
                    foreach(array_merge(array_keys($operator_only),['atshme_review','atshme_send_notices','atshme_import_members']) as $cap)unset($caps[$cap]);
                    if(metadata_exists('user',$id,Scope::META)){
                        $scope=get_user_meta($id,Scope::META,true);
                        if(get_user_meta($id,'_atshme_previous_operator_scope',true)!==$scope&&!update_user_meta($id,'_atshme_previous_operator_scope',$scope))return new \WP_Error('storage',__('Could not save the management scope.', 'atshift-members'));
                        if(!delete_user_meta($id,Scope::META))return new \WP_Error('storage',__('Could not remove the management scope.', 'atshift-members'));
                    }
                }elseif(!metadata_exists('user',$id,Scope::META)&&metadata_exists('user',$id,'_atshme_previous_operator_scope')){
                    if(!update_user_meta($id,Scope::META,get_user_meta($id,'_atshme_previous_operator_scope',true)))return new \WP_Error('storage',__('Could not restore the management scope.', 'atshift-members'));
                }
                if(!update_user_meta($id,$target->cap_key,$caps))return new \WP_Error('storage',__('Could not save the member role.', 'atshift-members'));
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Security-sensitive current state or atomic transaction/lock operation; WordPress object caching cannot provide these fresh predicates or synchronization semantics.
            if($wpdb->query('COMMIT')===false)return new \WP_Error('storage',__('Could not save the member information.', 'atshift-members'));
            $committed=true;
        } finally {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- Security-sensitive current state or atomic transaction/lock operation; WordPress object caching cannot provide these fresh predicates or synchronization semantics.
            if(!$committed)$wpdb->query('ROLLBACK');
            clean_user_cache($id);
            $store->unlock('staff-continuity');$store->unlock($lock);
        }
        if($role_changed){
            // Match WordPress role-change events after commit, so failed saves cannot notify other plugins.
            $target=new \WP_User($id);$target->update_user_level_from_caps();
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- This is an existing WordPress core hook or standard DONOTCACHEPAGE integration constant, not a newly declared plugin API.
            do_action('remove_user_role',$id,$old_role);
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- This is an existing WordPress core hook or standard DONOTCACHEPAGE integration constant, not a newly declared plugin API.
            do_action('add_user_role',$id,$role);
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- This is an existing WordPress core hook or standard DONOTCACHEPAGE integration constant, not a newly declared plugin API.
            do_action('set_user_role',$id,$role,[$old_role]);
            $store->log('member_role_'.$role,(string)$id);
        }
        if($linked!==null)do_action('atshift_members_profile_selection_saved',$id,$linked);
        if($changed||$role_changed)\WP_Session_Tokens::get_instance($id)->destroy_all();
        if($changed){
            $store->log('member_'.$state,(string)$id);
            Mail::send($state==='active'?'activated':($state==='banned'?'banned':($state==='suspended'?'suspended':'pending')),get_userdata($id)->user_email);
        }
        return true;
    }
    public static function set_state($id,$state) {
        return self::save_settings($id,['state'=>$state,'state_before'=>get_user_meta($id,'_atshme_state',true)]);
    }
    public static function recent_login($id) {
        $token=wp_get_session_token();
        $session=$token ? \WP_Session_Tokens::get_instance($id)->get($token) : null;
        return $session && isset($session['login']) && (int)$session['login']>=time()-600;
    }
    public static function email_edit($errors,$update,$user) {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Additional validation hook inside WordPress password/profile processing; the caller owns its nonce/reset proof, and this hook only rejects unsafe passwords.
        if ($update && !empty($user->ID) && self::managed(get_userdata($user->ID)) && isset($_POST['pass1']) && is_string($_POST['pass1']) && $_POST['pass1']!=='') {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Additional validation hook inside WordPress password/profile processing; the caller owns its nonce/reset proof, and this hook only rejects unsafe passwords. Password must retain its exact bytes; wp_unslash then Services::password performs length, character and breach validation.
            $check=Services::password(wp_unslash($_POST['pass1']));
            if (is_wp_error($check)) $errors->add($check->get_error_code(),$check->get_error_message());
        }
        if ($update && !current_user_can('manage_options') && !empty($user->ID) && self::managed(get_userdata($user->ID)) && isset($user->user_email) && strtolower($user->user_email)!==strtolower(get_userdata($user->ID)->user_email)) $errors->add('atshme_email',__('Verify your email address change on the account editing page.', 'atshift-members'));
    }
    public static $verified_email_change = false;
    public static function preserve_email($data,$update,$id,$input) {
        if ($update && !self::$verified_email_change && !current_user_can('manage_options') && self::managed(get_userdata($id))) $data['user_email']=get_userdata($id)->user_email;
        return $data;
    }
    public static function rest_email($prepared,$request) {
        $id=(int)($request['id']??0);
        if ($id && self::managed(get_userdata($id)) && !current_user_can('manage_options') && isset($prepared->user_email) && strtolower($prepared->user_email)!==strtolower(get_userdata($id)->user_email)) return new \WP_Error('atshme_email',__('Please use the email verification page.', 'atshift-members'),['status'=>403]);
        return $prepared;
    }
}
