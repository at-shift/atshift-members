<?php
namespace Atshift\Membership;
defined('ABSPATH') || exit;
final class Registration {
    public $store;
    const ACCEPTED = 'Request received. If we can process it, you will receive an email. If it does not arrive, please wait before trying again.';
    public static function accepted_message() { return __('Request received. If we can process it, you will receive an email. If it does not arrive, please wait before trying again.', 'atshift-members'); }
    public function __construct(Store $store) { $this->store=$store; }
    public static function option($key,$default=false) { $options=get_option('asm_settings',[]);return $options[$key]??$default; }
    public static function fields() {
        if($api=self::owner_profile_api())return array_keys(call_user_func($api['fields'],null));
        if(self::automatic_profile())return array_keys(Profile::linked_fields());
        if(self::api())return [];
        return array_values(array_unique(array_merge(['last_name','first_name'],self::selected_fields())));
    }
    public static function selected_fields() {return defined('ASM_PUBLIC_FIELDS') ? (array)ASM_PUBLIC_FIELDS : (array)self::option('fields',[]);}
    public static function automatic_schema() {
        $api=self::api();
        if(!$api||!isset($api['form_schema'])||!is_callable($api['form_schema']))return null;
        $schema=call_user_func($api['form_schema']);
        return is_array($schema)&&is_array($schema['standard']??null)&&is_array($schema['custom']??null)?$schema:null;
    }
    public static function automatic_profile() {$schema=self::automatic_schema();return $schema&&!empty($schema['configured']);}
    public static function uses_profile_plugin() {return (bool)self::api();}
    public static function api() {
        $api=apply_filters('atshift_upf_public_profile_api',null);
        if (!is_array($api) || ($api['version']??0)!==1) return null;
        foreach (['fields','render','validate','save','values'] as $key) if (!isset($api[$key]) || !is_callable($api[$key])) return null;
        return $api;
    }
    public static function passkey_integration_available() {
        return shortcode_exists('atshift_passkey_profile')&&(bool)apply_filters('atshift_freeform_login_passkeys_available',false);
    }
    public static function uses_profile_passkeys() {
        $api=self::owner_profile_api();if(!$api)return false;
        foreach(call_user_func($api['fields'],null) as $field)if(($field['type']??'')==='passkeys')return true;
        return false;
    }
    public static function uses_profile_classifications() {
        $api=self::owner_profile_api();if(!$api)return false;
        foreach(call_user_func($api['fields'],null) as $field)if(($field['type']??'')==='pro_organization_groups')return true;
        return false;
    }
    public static function owner_profile_api() {
        $api=self::api()['owner_form']??null;
        if(!is_array($api)||($api['version']??0)!==1)return null;
        foreach(['fields','render','validate','save','values'] as $method)if(!is_callable($api[$method]??null))return null;
        return $api;
    }
    public static function profile_api() {
        if($api=self::owner_profile_api())return $api;
        if(self::automatic_profile())return Profile::linked_api();
        if(self::api())return Profile::api();
        $api=apply_filters('atshift_members_profile_api',Profile::api());
        if(!is_array($api)||($api['version']??0)!==1)return Profile::api();
        foreach(['fields','render','validate','save','values'] as $method)if(!is_callable($api[$method]??null))return Profile::api();
        return $api;
    }
    public function ready() {return !is_multisite() && Services::configured() && $this->store->ready();}
    public static function signals($server,$browser) {
        $ip=filter_var($server['REMOTE_ADDR']??'',FILTER_VALIDATE_IP) ?: 'unknown';
        $packed=@inet_pton($ip);
        $prefix=$packed ? (strlen($packed)===4 ? bin2hex(substr($packed,0,3)) : bin2hex(substr($packed,0,8))) : 'unknown';
        // Proxy headers are untrusted. Configure the trusted proxy at the web server.
        return ['ip'=>$ip,'network'=>$prefix,'browser'=>preg_match('/^[a-f0-9]{64}$/D',$browser)?$browser:'missing'];
    }
    public function limits($scope,$signals,$email='') {
        $ok=true;
        foreach (['ip'=>30,'network'=>200,'browser'=>15] as $kind=>$max) {
            // Never make a short-circuit skip other counters.
            $pass=$this->store->hit($scope.'_'.$kind,$signals[$kind]??'missing',$max,900);
            $ok=$pass && $ok;
        }
        $pass=$this->store->hit($scope.'_site','site',500,900);$ok=$pass && $ok;
        if ($email!=='') {$pass=$this->store->hit($scope.'_email',strtolower($email),5,3600);$ok=$pass && $ok;}
        return $ok;
    }
    public function start($email,$turnstile,$signals) {
        if (!self::option('enabled') || !$this->ready()) return new \WP_Error('unavailable',__('Registration is currently unavailable. Please try again later.', 'atshift-members'));
        $email=is_string($email)?strtolower(trim($email)):'';
        if (!$this->limits('start',$signals,$email)) return self::accepted_message();
        if (!Services::turnstile($turnstile)) return new \WP_Error('challenge',__('Verification failed. Reload the page and try again.', 'atshift-members'));
        if (is_email($email) && strlen($email)<=254 && $this->store->hit('mail_destination',$email,3,3600) && $this->store->hit('mail_site','site',200,3600)) $this->store->queue($email);
        // No account lookup or synchronous email on this path.
        return self::accepted_message();
    }
    public function mail_worker() {
        if (!$this->store->ready()) return;
        foreach ($this->store->queued() as $row) {
            $token=Store::token();
            if (!$this->store->claim_mail($row->id,$token)) continue;
            if ($row->kind==='reset') {
                $user=get_user_by('email',$row->email);
                // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- This is an existing WordPress core hook or standard DONOTCACHEPAGE integration constant, not a newly declared plugin API.
                if ($user && !Members::blocked($user->ID) && apply_filters('allow_password_reset',true,$user->ID)===true) {
                    $key=get_password_reset_key($user);
                    if (!is_wp_error($key)) Mail::send('reset',$row->email,add_query_arg(['key'=>$key,'login'=>$user->user_login],Screens::url('reset')));
                }
                $this->store->revoke($row->id);
                continue;
            }
            if ($row->kind==='email') {
                $user=get_userdata($row->target_id);
                if (!$user || !Members::active($user->ID) || email_exists($row->email)) {$this->store->revoke($row->id);continue;}
                $url=add_query_arg('asm_email_token',$token,Screens::url('email'));
                if (!Mail::send('email_verify',$row->email,$url)) $this->store->revoke($row->id);
                continue;
            }
            if (email_exists($row->email)) {$this->store->revoke($row->id);continue;}
            $url=add_query_arg('asm_token',$token,Screens::url($row->kind==='operator'?'staff':'register'));
            if (!Mail::send($row->kind==='operator'?'invite':'verify',$row->email,$url)) $this->store->revoke($row->id);
        }
    }
    public function verify($token,$browser,$signals) {
        if (!$this->limits('verify',$signals)) return new \WP_Error('proof',__('Could not verify. Please wait before requesting a new confirmation email.', 'atshift-members'));
        $session=Store::token();
        if (!$this->store->verify($token,$session,$browser)) return new \WP_Error('proof',__('Could not verify. Request a new confirmation email.', 'atshift-members'));
        return $session;
    }
    public static function validate_username($login) {
        if (!is_string($login) || !preg_match('/^[A-Za-z0-9_.-]{1,60}$/D',$login) || !validate_username($login)) {
            return new \WP_Error('username',__('Enter a username of 1–60 characters using letters, numbers, periods, hyphens, or underscores.', 'atshift-members'));
        }
        if (in_array(strtolower($login),array_map('strtolower',(array)apply_filters('illegal_user_logins',[])),true)) {
            return new \WP_Error('username',__('This username is not allowed. Choose another one.', 'atshift-members'));
        }
        if (username_exists($login)) return new \WP_Error('username_exists',__('This username is already taken. Choose another one.', 'atshift-members'));
        return $login;
    }
    public function complete($session,$browser,$input,$signals) {
        $row=$this->store->session($session,$browser);
        if (!$row || !in_array($row->kind,['member','operator'],true)) return new \WP_Error('proof',__('Could not verify. Request a new confirmation email.', 'atshift-members'));
        if (!$this->ready()) return new \WP_Error('unavailable',__('Registration is currently unavailable.', 'atshift-members'));
        if (!$this->limits('create',$signals,$row->email)) return new \WP_Error('limited',__('Please try again later.', 'atshift-members'));
        if (array_diff(array_keys($input),['username','password','fields'])) return new \WP_Error('input',__('Some registration fields are not allowed.', 'atshift-members'));
        $login=self::validate_username($input['username']??'');
        if (is_wp_error($login)) return $login;
        $password=$input['password']??'';
        $check=Services::password($password);if (is_wp_error($check)) return $check;
        $api=self::profile_api();$values=call_user_func($api['validate'],self::fields(),$input['fields']??[]);
        if (is_wp_error($values)) return $values;
        if (!$this->store->lock($row->email)) return new \WP_Error('busy',__('Registration is in progress. Please wait a moment and try again.', 'atshift-members'));
        $login_lock='username:'.strtolower($login);
        if (!$this->store->lock($login_lock)) {$this->store->unlock($row->email);return new \WP_Error('busy',__('Registration is in progress. Please wait a moment and try again.', 'atshift-members'));}
        try {
            if (username_exists($login)) return new \WP_Error('username_exists',__('This username is already taken. Choose another one.', 'atshift-members'));
            if (!$this->store->claim_creation($row->id)) return new \WP_Error('proof',__('This verification information cannot be used.', 'atshift-members'));
            if (email_exists($row->email)) {$this->store->revoke($row->id);return new \WP_Error('complete',__('Cannot complete registration. Please try logging in or resetting your password.', 'atshift-members'));}
            // Email and case-insensitive username locks serialize Members registrations.
            $id=wp_insert_user(['user_login'=>$login,'user_email'=>$row->email,'user_pass'=>$password,'display_name'=>$login,'role'=>$row->kind==='operator'?'asm_operator':'asm_member','meta_input'=>['_asm_state'=>'provisioning']]);
            if (is_wp_error($id)) {$this->store->log('creation_failed',$row->email);return new \WP_Error('creation',__('Could not complete registration. Request a new confirmation email.', 'atshift-members'));}
            $saved=call_user_func($api['save'],$id,self::fields(),$values);
            if (is_wp_error($saved)) {$this->store->log('provisioning_failed',(string)$id);return $saved;}
            $state=$row->kind==='operator'?'active':(self::option('approval')?'pending':'active');
            if (!update_user_meta($id,'_asm_state',$state) || false===$this->store->finish($row->id,$id)) {
                update_user_meta($id,'_asm_state','provisioning');
                return new \WP_Error('creation',__('Could not complete registration. Please contact the administrator.', 'atshift-members'));
            }
            $this->store->log('created',(string)$id);
            do_action('atshift_members_registered',$id,$row);
            Mail::send($state==='active'?'welcome':'pending',$row->email);
            return ['user_id'=>$id,'state'=>$state];
        } finally { $this->store->unlock($login_lock);$this->store->unlock($row->email); }
    }
    public function invite($email) {
        if (!current_user_can('manage_options')) return new \WP_Error('forbidden',__('Site administrator permissions are required to send invitations.', 'atshift-members'));
        $email=strtolower(trim($email));
        if (!is_email($email) || email_exists($email)) return new \WP_Error('email',__('Enter an email address that is not already registered.', 'atshift-members'));
        if (!$this->store->hit('invite_destination',$email,3,3600) || !$this->store->hit('mail_site','site',200,3600)) return new \WP_Error('limited',__('Please try again later.', 'atshift-members'));
        return $this->store->queue($email,'operator') ? true : new \WP_Error('queue',__('Could not save the invitation.', 'atshift-members'));
    }
    public function request_reset($email,$turnstile,$signals) {
        if (!$this->ready()) return new \WP_Error('unavailable',__('The verification service is currently unavailable.', 'atshift-members'));
        $email=is_string($email)?strtolower(trim($email)):'';
        if (!$this->limits('reset',$signals,$email)) return self::accepted_message();
        if (!Services::turnstile($turnstile)) return new \WP_Error('challenge',__('Verification failed. Please try again.', 'atshift-members'));
        if (is_email($email) && $this->store->hit('mail_destination',$email,3,3600) && $this->store->hit('mail_site','site',200,3600)) $this->store->queue($email,'reset');
        return self::accepted_message();
    }
    public function reset_password($proof,$password,$signals) {
        if (!$this->limits('reset_finish',$signals)) return new \WP_Error('limited',__('Please try again later.', 'atshift-members'));
        if (!is_array($proof) || count($proof)!==2 || !is_string($proof[0]) || !is_string($proof[1])) return new \WP_Error('proof',__('Request a new password reset link.', 'atshift-members'));
        $check=Services::password($password);if(is_wp_error($check))return $check;
        $lock='reset|'.$proof[1];
        if (!$this->store->lock($lock)) return new \WP_Error('busy',__('Please wait a moment and try again.', 'atshift-members'));
        try {
            $user=check_password_reset_key($proof[0],$proof[1]);
            if (is_wp_error($user)) return new \WP_Error('proof',__('Request a new password reset link.', 'atshift-members'));
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- This is an existing WordPress core hook or standard DONOTCACHEPAGE integration constant, not a newly declared plugin API.
            if (Members::blocked($user->ID) || apply_filters('allow_password_reset',true,$user->ID)!==true) return new \WP_Error('state',__('This account is currently unavailable.', 'atshift-members'));
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- This is an existing WordPress core hook or standard DONOTCACHEPAGE integration constant, not a newly declared plugin API.
            $errors=new \WP_Error();do_action('validate_password_reset',$errors,$user);
            if ($errors->has_errors())return $errors;
            reset_password($user,$password);\WP_Session_Tokens::get_instance($user->ID)->destroy_all();
            return true;
        } finally {$this->store->unlock($lock);}
    }
    public function request_email($id,$email,$signals) {
        if ($id!==get_current_user_id() || !Members::active($id)) return new \WP_Error('forbidden',__('You cannot perform this action.', 'atshift-members'));
        $email=strtolower(trim($email));
        if (!is_email($email) || !$this->limits('email_change',$signals,$email)) return new \WP_Error('email',__('Check the address or try again later.', 'atshift-members'));
        if ($this->store->hit('mail_destination',$email,3,3600) && $this->store->hit('mail_site','site',200,3600)) $this->store->queue($email,'email',$id);
        return self::accepted_message();
    }
    public function confirm_email($session,$browser,$id) {
        $row=$this->store->session($session,$browser);
        if (!$row || $row->kind!=='email' || (int)$row->target_id!==$id || $id!==get_current_user_id() || !Members::active($id)) return new \WP_Error('proof',__('This verification information cannot be used.', 'atshift-members'));
        if (!$this->store->lock($row->email)) return new \WP_Error('busy',__('Please wait a moment and try again.', 'atshift-members'));
        try {
            if (!$this->store->claim_creation($row->id) || email_exists($row->email)) return new \WP_Error('proof',__('Cannot change the email address.', 'atshift-members'));
            $old=get_userdata($id)->user_email;
            Members::$verified_email_change=true;
            try {$result=wp_update_user(['ID'=>$id,'user_email'=>$row->email]);}
            finally {Members::$verified_email_change=false;}
            if (is_wp_error($result)) return $result;
            $this->store->finish($row->id,$id);
            \WP_Session_Tokens::get_instance($id)->destroy_all();
            Mail::send('email_changed',$old);Mail::send('email_changed',$row->email);
            return true;
        } finally {$this->store->unlock($row->email);}
    }
}
