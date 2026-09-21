<?php
namespace Atshift\Membership;
defined('ABSPATH') || exit;
final class Screens {
    private $reg;
    private $message='';
    private $browser='';
    private $withdrawal_review=null;
    private $withdrawal_done=false;
    private $passkey_html=null;
    public function __construct($reg) {$this->reg=$reg;}
    public static function definitions() {
        $pages=['register'=>[__('Member Registration', 'atshift-members'),'atshme_registration'],'account'=>[__('Your Account Information', 'atshift-members'),'atshme_account'],'edit'=>[__('Edit Account Information', 'atshift-members'),'atshme_account_edit'],'reset'=>[__('Reset Password', 'atshift-members'),'atshme_password_reset'],'withdraw'=>[__('Close Account', 'atshift-members'),'atshme_withdraw']];
        return $pages;
    }
    public static function url($key) {
        if($key==='email')return add_query_arg('atshme_view','email',self::url('edit'));

        $id=(int)(get_option('atshme_pages',[])[$key]??0);
        return $id && get_post_status($id)==='publish' ? get_permalink($id) : home_url('/');
    }
    public static function private_headers() {
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- This is an existing WordPress core hook or standard DONOTCACHEPAGE integration constant, not a newly declared plugin API.
        if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE',true);
        if (!headers_sent()) {nocache_headers();header('Cache-Control: private, no-store, max-age=0');header('Referrer-Policy: no-referrer');header('X-Robots-Tag: noindex, nofollow');}
    }
    public function hooks() {
        // Labels trigger WordPress translation loading. Register on init while
        // keeping request/authentication guards registered early.
        add_action('init',function(){
            foreach (self::definitions() as $key=>$def) add_shortcode($def[1],function()use($key){return $this->render($key);});
        },1);
        add_action('template_redirect',[$this,'handle'],1);
        add_filter('wp_robots',function($robots){if ($this->screen()) {$robots['noindex']=true;$robots['nofollow']=true;unset($robots['index']);}return $robots;});
    }
    private function screen() {
        if (!is_singular()) return '';
        $content=(string)get_post_field('post_content',get_queried_object_id());
        foreach (self::definitions() as $key=>$def) if (has_shortcode($content,$def[1])) return $key;
        return '';
    }
    private static function cookie($name,$value,$seconds) {
        if (headers_sent()) return false;
        $ok=setcookie($name,$value,['expires'=>time()+$seconds,'path'=>'/','secure'=>is_ssl(),'httponly'=>true,'samesite'=>'Lax']);
        if ($ok) $_COOKIE[$name]=$value;
        return $ok;
    }
    private static function str($source,$key) {return isset($source[$key]) && is_string($source[$key]) ? wp_unslash($source[$key]) : '';}
    private static function profile_fields_input() {
        $current='atshme_fields';$legacy=Legacy::name('fields');
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Called only after handle() verifies the browser/user-bound CSRF HMAC.
        if(isset($_POST[$current],$_POST[$legacy]))return null;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Called only after handle() verifies the browser/user-bound CSRF HMAC.
        $key=isset($_POST[$current])?$current:$legacy;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Typed fields are unslashed here and schema-validated by the profile API before persistence.
        return isset($_POST[$key])?wp_unslash($_POST[$key]):[];
    }
    private function csrf() {return Store::key('csrf|'.$this->browser.'|'.get_current_user_id());}
    private function signals() {return Registration::signals($_SERVER,$this->browser);}
    public function handle() {
        $screen=$this->screen();if (!$screen) return;
        self::private_headers();
        // Render the public integration before wp_head so its styles and scripts load in time.
        if($screen==='edit')$this->passkey_profile();
        wp_enqueue_style('atshift-members-account',plugins_url('assets/account.css',dirname(__DIR__).'/atshift-members.php'),[],'0.1.1');
        $this->browser=self::str($_COOKIE,'atshme_browser');
        if (!preg_match('/^[a-f0-9]{64}$/D',$this->browser)) {$this->browser=Store::token();self::cookie('atshme_browser',$this->browser,3600);}
        // Move native WP reset secrets to HttpOnly cookies, then remove them from address bar.
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- POST actions below are gated by the browser/user-bound CSRF HMAC in handle(); GET only stages or displays proofs, which are validated on submission.
        if ($screen==='reset' && isset($_GET['key'],$_GET['login'])) {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- POST actions below are gated by the browser/user-bound CSRF HMAC in handle(); GET only stages or displays proofs, which are validated on submission.
            $key=self::str($_GET,'key');$login=self::str($_GET,'login');
            if (strlen($key)<=128 && strlen($login)<=128) {self::cookie('atshme_reset',base64_encode(wp_json_encode([$key,$login])),900);wp_safe_redirect(self::url('reset'));exit;}
        }
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Typed profile fields are unslashed and schema-validated by the profile API; withdrawal IDs are validated by Withdrawal::review. Request method is an exact non-mutating comparison.
        $method=is_string($_SERVER['REQUEST_METHOD']??null)?strtoupper(sanitize_key(wp_unslash($_SERVER['REQUEST_METHOD']))):'GET';
        if ($method!=='POST') return;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- POST actions below are gated by the browser/user-bound CSRF HMAC in handle(); GET only stages or displays proofs, which are validated on submission.
        if (!isset($_COOKIE['atshme_browser']) || !hash_equals($this->csrf(),self::str($_POST,'atshme_csrf'))) {$this->message=__('Reload the form.', 'atshift-members');return;}
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- POST actions below are gated by the browser/user-bound CSRF HMAC in handle(); GET only stages or displays proofs, which are validated on submission.
        $action=self::str($_POST,'atshme_action');$result=null;
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- POST actions below are gated by the browser/user-bound CSRF HMAC in handle(); GET only stages or displays proofs, which are validated on submission.
        if ($action==='start' && $screen==='register') $result=$this->reg->start(self::str($_POST,'email'),self::str($_POST,'cf-turnstile-response'),$this->signals());
        elseif ($action==='verify' && $screen==='register') {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- POST actions below are gated by the browser/user-bound CSRF HMAC in handle(); GET only stages or displays proofs, which are validated on submission.
            $result=$this->reg->verify(self::str($_POST,'token'),$this->browser,$this->signals());
            if (!is_wp_error($result)) {self::cookie('atshme_session',$result,900);wp_safe_redirect(self::url($screen));exit;}
        } elseif ($action==='complete' && $screen==='register') {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- POST actions below are gated by the browser/user-bound CSRF HMAC in handle(); GET only stages or displays proofs, which are validated on submission.
            $legacy_fields=Legacy::name('fields');$extra=array_diff(array_keys($_POST),['atshme_action','atshme_csrf','username','password','atshme_fields',$legacy_fields]);
            // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- POST actions below are gated by the browser/user-bound CSRF HMAC in handle(); GET only stages or displays proofs, which are validated on submission. Typed profile fields are unslashed and schema-validated by the profile API; withdrawal IDs are validated by Withdrawal::review. Request method is an exact non-mutating comparison.
            $fields=self::profile_fields_input();$data=['username'=>self::str($_POST,'username'),'password'=>self::str($_POST,'password'),'fields'=>$fields??[]];
            if($fields===null)$extra[]=$legacy_fields;
            if ($extra) $data['unapproved']=true;
            $result=$this->reg->complete(self::str($_COOKIE,'atshme_session'),$this->browser,$data,$this->signals());
            if (!is_wp_error($result)) {
                self::cookie('atshme_session','',-3600);
                if ($result['state']==='active') {
                    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- This is an existing WordPress core hook or standard DONOTCACHEPAGE integration constant, not a newly declared plugin API.
                    $user=apply_filters('authenticate',get_userdata($result['user_id']),'','');
                    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- This is an existing WordPress core hook or standard DONOTCACHEPAGE integration constant, not a newly declared plugin API.
                    if ($user instanceof \WP_User) $user=apply_filters('wp_authenticate_user',$user,'');
                    // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- This is an existing WordPress core hook or standard DONOTCACHEPAGE integration constant, not a newly declared plugin API.
                    if ($user instanceof \WP_User && $user->ID===$result['user_id']) {wp_set_current_user($user->ID);wp_set_auth_cookie($user->ID,false,is_ssl());do_action('wp_login',$user->user_login,$user);wp_safe_redirect(self::url('account'));exit;}
                    $result=__('Registration is complete. Please go to the login page.', 'atshift-members');
                } else $result=__('Your registration has been submitted. We will notify you after administrator review.', 'atshift-members');
            }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- POST actions below are gated by the browser/user-bound CSRF HMAC in handle(); GET only stages or displays proofs, which are validated on submission.
        } elseif ($action==='reset_request' && $screen==='reset') $result=$this->reg->request_reset(self::str($_POST,'email'),self::str($_POST,'cf-turnstile-response'),$this->signals());
        elseif ($action==='reset_password' && $screen==='reset') {
            $proof=json_decode((string)base64_decode(self::str($_COOKIE,'atshme_reset'),true),true);
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- POST actions below are gated by the browser/user-bound CSRF HMAC in handle(); GET only stages or displays proofs, which are validated on submission.
            $result=$this->reg->reset_password($proof,self::str($_POST,'password'),$this->signals());
            if ($result===true) {self::cookie('atshme_reset','',-3600);$result=__('Your password has been reset. Please log in.', 'atshift-members');}
        } elseif ($action==='withdraw_review' && $screen==='withdraw') {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- POST actions below are gated by the browser/user-bound CSRF HMAC in handle(); GET only stages or displays proofs, which are validated on submission.
            $mode=self::str($_POST,'withdraw_mode');
            // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- POST actions below are gated by the browser/user-bound CSRF HMAC in handle(); GET only stages or displays proofs, which are validated on submission. Typed profile fields are unslashed and schema-validated by the profile API; withdrawal IDs are validated by Withdrawal::review. Request method is an exact non-mutating comparison.
            $result=Withdrawal::review(get_current_user_id(),$mode,$mode==='transfer'?wp_unslash($_POST['transfer_posts']??[]):[],self::str($_POST,'transfer_consent')==='yes');
            if(!is_wp_error($result)) {$this->withdrawal_review=$result;$result=__('Review the details and confirm account closure.', 'atshift-members');}
        } elseif ($action==='withdraw_commit' && $screen==='withdraw') {
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- POST actions below are gated by the browser/user-bound CSRF HMAC in handle(); GET only stages or displays proofs, which are validated on submission.
            $result=Withdrawal::commit(get_current_user_id(),self::str($_POST,'withdraw_token'),self::str($_POST,'confirm')==='yes');
            if(!is_wp_error($result)) {
                wp_logout();$this->withdrawal_done=true;
                $result=$result['status']==='complete'?__('Your account has been closed, and the account data and content selected for deletion have been removed.', 'atshift-members'):__('Your account closure request has been accepted. Login is disabled, and your data is being deleted.', 'atshift-members');
            }
        } elseif ($screen==='edit' && Members::active(get_current_user_id())) {
            $id=get_current_user_id();
            if ($action==='profile' && !self::email_view()) {
                $api=Registration::profile_api();
                // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- POST actions below are gated by the browser/user-bound CSRF HMAC in handle(); GET only stages or displays proofs, which are validated on submission. Typed profile fields are unslashed and schema-validated by the profile API; withdrawal IDs are validated by Withdrawal::review. Request method is an exact non-mutating comparison.
                $fields=self::profile_fields_input();$result=$fields===null?new \WP_Error('profile_fields',__('Check the submitted profile fields.', 'atshift-members')):call_user_func($api['save'],$id,Registration::fields(),$fields);
                if ($result===true) $result=apply_filters('atshift_members_profile_saved_message',__('Profile saved.', 'atshift-members'),$id);
            // phpcs:ignore WordPress.Security.NonceVerification.Missing -- POST actions below are gated by the browser/user-bound CSRF HMAC in handle(); GET only stages or displays proofs, which are validated on submission.
            } elseif ($action==='email_request') $result=$this->reg->request_email($id,self::str($_POST,'email'),$this->signals());
            elseif ($action==='email_confirm') {
                // phpcs:ignore WordPress.Security.NonceVerification.Missing -- POST actions below are gated by the browser/user-bound CSRF HMAC in handle(); GET only stages or displays proofs, which are validated on submission.
                $token=self::str($_POST,'token');
                $session=$this->reg->store->email_preview($token,$id)?$this->reg->verify($token,$this->browser,$this->signals()):new \WP_Error('proof',__('This verification information cannot be used.', 'atshift-members'));
                $result=is_wp_error($session)?$session:$this->reg->confirm_email($session,$this->browser,$id);
                if ($result===true) {wp_logout();$result=__('Your email address has changed. Log in with your new address.', 'atshift-members');}
            }
        }
        $this->message=is_wp_error($result)?$result->get_error_message():(is_string($result)?$result:__('Could not complete the action.', 'atshift-members'));
    }
    private function form($action) {echo '<form method="post" action="'.esc_url(self::url(str_starts_with($action,'email_')?'email':$this->screen())).'"><input type="hidden" name="atshme_action" value="'.esc_attr($action).'"><input type="hidden" name="atshme_csrf" value="'.esc_attr($this->csrf()).'">';}
    private function button($label) {echo '<p><button type="submit">'.esc_html($label).'</button></p></form>';}
    private function username() {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Redisplay only; complete() validates the submitted username after CSRF verification.
        $value=self::str($_POST,'username');
        echo '<p><label>'.esc_html__('Username', 'atshift-members').'<br><input type="text" name="username" autocomplete="username" maxlength="60" pattern="[A-Za-z0-9_.\\-]+" value="'.esc_attr($value).'" aria-describedby="atshme-username-help" required></label></p>';
        echo '<p id="atshme-username-help">'.esc_html__('Use 1–60 letters, numbers, periods, hyphens, or underscores. Your username cannot be changed after registration. You can also log in with your email address.', 'atshift-members').'</p>';
    }
    private function password() {echo ('<p>' . '<label>' . esc_html__('Password (at least 12 characters)', 'atshift-members') . '<br>' . '<input type="password" name="password" autocomplete="new-password" minlength="12" maxlength="256" required>' . '</label>' . '</p>');}
    private function challenge() {
        // phpcs:ignore PluginCheck.CodeAnalysis.EnqueuedResourceOffloading.OffloadedContent,WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Required Cloudflare Turnstile service endpoint/script, not a hosted plugin asset; external service use and privacy are documented in readme. Cloudflare owns the dynamically updated Turnstile service script and requires its canonical URL without a plugin version query.
        if (Services::configured()) {wp_enqueue_script('atshme-turnstile','https://challenges.cloudflare.com/turnstile/v0/api.js',[],null,true);echo '<div class="cf-turnstile" data-sitekey="'.esc_attr(Services::turnstile_keys()['site']).'" data-action="atshme_register"></div>';}
    }
    private static function passkeys_available() {
        return Members::reader()&&shortcode_exists('atshift_passkey_profile')&&(bool)apply_filters('atshift_freeform_login_passkey_profile_available',false);
    }
    private function passkey_profile() {
        if(!self::passkeys_available())return '';
        if($this->passkey_html===null)$this->passkey_html=do_shortcode('[atshift_passkey_profile heading="false"]');
        return $this->passkey_html;
    }
    private static function email_view() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing -- Read-only route selection; mutations in handle() require the browser/user-bound CSRF HMAC.
        return self::str($_GET,'atshme_view')==='email'||self::str($_GET,'atshme_email_token')!==''||str_starts_with(self::str($_POST,'atshme_action'),'email_');
    }
    private function email_screen() {
        echo '<h2>'.esc_html__('Change Email Address', 'atshift-members').'</h2>';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.NonceVerification.Missing -- GET only previews a server-side proof; POST confirmation is protected by the browser/user-bound CSRF HMAC in handle().
        $token=self::str($_GET,'atshme_email_token')?:self::str($_POST,'token');
        if($token){
            $email=$this->reg->store->email_preview($token,get_current_user_id());
            if(!$email){echo '<p>'.esc_html__('This verification information cannot be used.', 'atshift-members').'</p>';return;}
            $user=wp_get_current_user();
            echo '<p>'.esc_html($user->display_name).'</p><dl><dt>'.esc_html__('New Email Address', 'atshift-members').'</dt><dd>'.esc_html($email).'</dd></dl>';
            $this->form('email_confirm');echo '<input type="hidden" name="token" value="'.esc_attr($token).'">';$this->button(__('Confirm Email Address Change', 'atshift-members'));
        }else{
            echo '<p>'.esc_html__('Your current email address will remain unchanged until you complete the confirmation process.', 'atshift-members').'</p>';
            $this->form('email_request');echo '<p><label>'.esc_html__('New Email Address', 'atshift-members').'<input type="email" name="email" autocomplete="email" required></label></p>';$this->button(__('Send Confirmation to the New Address', 'atshift-members'));
        }
        echo '<p><a href="'.esc_url(self::url('account')).'">'.esc_html__('Your Account Information', 'atshift-members').'</a></p>';
    }
    public function render($screen) {
        ob_start();echo '<div class="atshme-account">';
        if ($this->message) echo '<p role="status">'.esc_html($this->message).'</p>';
        if($screen==='withdraw' && $this->withdrawal_done) {if ($screen==='account' && shortcode_exists('atshme_notice_preferences')) echo do_shortcode('[atshme_notice_preferences]');
        echo '</div>';return ob_get_clean();}
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only continuation URL; the server-side email proof is validated before any address is disclosed or changed.
        if (in_array($screen,['account','edit','withdraw'],true) && !Members::reader()) {echo ('<p>' . esc_html__('Please log in to continue.', 'atshift-members') . '</p>' . '<a href="').esc_url(wp_login_url($screen==='edit'&&self::email_view()?add_query_arg('atshme_email_token',self::str($_GET,'atshme_email_token'),self::url('email')):self::url($screen))).('">' . esc_html__('Log In', 'atshift-members') . '</a>' . '</div>');return ob_get_clean();}
        $api=Registration::profile_api();
        if(in_array($screen,['account','edit'],true))do_action('atshift_members_profile_status');
        if ($screen==='account') {
            $user=wp_get_current_user();
            echo '<dl><dt>'.esc_html__('Username', 'atshift-members').'</dt><dd>'.esc_html($user->user_login).'</dd></dl>';
            echo ('<dl>' . '<dt>' . esc_html__('Email Address', 'atshift-members') . '</dt>' . '<dd>').esc_html($user->user_email).('</dd>' . '<dt>' . esc_html__('Membership Status', 'atshift-members') . '</dt>' . '<dd>').esc_html(Members::state_label(get_user_meta($user->ID,'_atshme_state',true))).'</dd></dl>';
            if($api&&is_callable($api['display']??null))call_user_func($api['display'],$user->ID,Registration::fields());
            elseif ($api) foreach (call_user_func($api['fields'],Registration::fields()) as $key=>$field) {$values=call_user_func($api['values'],$user->ID,Registration::fields());echo '<p>'.esc_html($field['label']??$key).': '.esc_html((string)($values[$key]??'')).'</p>';}
            do_action('atshift_members_account_responsibilities',$user->ID);
            foreach(['edit'=>__('Edit Account Information', 'atshift-members'),'email'=>__('Change Email Address', 'atshift-members'),'reset'=>__('Reset Password', 'atshift-members')] as $key=>$label)echo '<p><a href="'.esc_url(self::url($key)).'">'.esc_html($label).'</a></p>';
            if (self::passkeys_available()) echo '<p><a href="'.esc_url(self::url('edit').'#atshme-passkeys').('">' . esc_html__('Register and Manage Passkeys', 'atshift-members') . '</a>' . '</p>');
            if (Members::active($user->ID) && !current_user_can('manage_options')) echo '<p><a href="'.esc_url(self::url('withdraw')).('">' . esc_html__('Close Account', 'atshift-members') . '</a>' . '</p>');
            echo '<p><a href="'.esc_url(wp_logout_url(home_url('/'))).('">' . esc_html__('Log Out', 'atshift-members') . '</a>' . '</p>');
        } elseif ($screen==='edit' && self::email_view()) {
            $this->email_screen();
        } elseif ($screen==='edit') {
            if ($api && Registration::fields()) {$this->form('profile');call_user_func($api['render'],Registration::fields(),call_user_func($api['values'],get_current_user_id(),Registration::fields()));$this->button(__('Save', 'atshift-members'));}
            $passkeys=$this->passkey_profile();
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted Freeform Login shortcode HTML; provider escapes fields and supplies its own forms and scripts.
            if($passkeys!==''&&!Registration::uses_profile_passkeys())echo ('<section id="atshme-passkeys" class="atshme-account-passkeys" aria-labelledby="atshme-passkeys-title">' . '<h2 id="atshme-passkeys-title">' . esc_html__('Register and Manage Passkeys', 'atshift-members') . '</h2>' . '<p class="atshme-auth-integration">' . esc_html__('Register and manage passkeys with atshift Freeform Login.', 'atshift-members') . '</p>').$passkeys.'</section>';
        } elseif ($screen==='withdraw') {
            $id=get_current_user_id();
            if(current_user_can('manage_options')) echo ('<p>' . esc_html__('Site administrators cannot close their account from this screen.', 'atshift-members') . '</p>');
            elseif(!Members::recent_login($id)) echo ('<p>' . esc_html__('To verify your identity, log in again and complete this within 10 minutes.', 'atshift-members') . '</p>' . '<a href="').esc_url(wp_login_url(self::url('withdraw'),true)).('">' . esc_html__('Log In Again', 'atshift-members') . '</a>');
            elseif($this->withdrawal_review) {
                $review=$this->withdrawal_review;$plan=$review['plan'];
                echo ('<h2>' . esc_html__('Final Account Closure Review', 'atshift-members') . '</h2>' . '<p>' . esc_html__('Your account, profile, authentication information, comments posted while logged in, and posts and attachments not selected for transfer will be deleted, including items in the trash. Comments on deleted posts will also be removed.', 'atshift-members') . '</p>');
                if($plan['posts']) {
                    echo ('<h3>' . esc_html__('Posts to Transfer to the Site Operator', 'atshift-members') . '</h3>' . '<ul>');
                    foreach(array_keys($plan['posts']) as $post_id)echo '<li>'.esc_html(get_the_title($post_id)?:__('(Untitled)', 'atshift-members')).'</li>';
                    /* translators: %d: Number of attachments transferred with the post. */
                    echo '</ul><p>'.sprintf(esc_html__('This post and its associated attachments (%d) will be transferred to the site operator as drafts. Personal information in text and images is not anonymized automatically.', 'atshift-members'),count($plan['media'])).'</p>';
                    if($plan['media']) {echo '<ul>';foreach(array_keys($plan['media']) as $media_id)echo '<li>'.esc_html(get_the_title($media_id)?:__('(Unnamed Attachment)', 'atshift-members')).'</li>';echo '</ul>';}
                } else echo ('<p>' . '<strong>' . esc_html__('No posts will be retained by the site operator.', 'atshift-members') . '</strong>' . '</p>');
                echo ('<p>' . esc_html__('For files that share storage with another owner\'s files, only your record is removed. Data held by other services, unsupported plugins, and backups cannot be deleted by this action. Temporary rate-limit records remain until they expire.', 'atshift-members') . '</p>');
                $this->form('withdraw_commit');echo '<input type="hidden" name="withdraw_token" value="'.esc_attr($review['token']).('">' . '<label>' . '<input type="checkbox" name="confirm" value="yes" required>' . esc_html__('I have reviewed what will be deleted and transferred. This action cannot be undone.', 'atshift-members') . '</label>');$this->button(__('Confirm Account Closure', 'atshift-members'));
                echo '<p><a href="'.esc_url(self::url('withdraw')).('">' . esc_html__('Change Selection', 'atshift-members') . '</a>' . '</p>');
            } else {
                echo ('<p>' . esc_html__('Closing your account deletes your account and personal data and prevents further login. If you want to preserve contributions, you can transfer selected posts to the site operator. Review the deletion and transfer details on the next screen.', 'atshift-members') . '</p>');
                $this->form('withdraw_review');
                echo ('<fieldset>' . '<legend>' . esc_html__('Your Posts When Closing Your Account', 'atshift-members') . '</legend>' . '<label>' . '<input type="radio" name="withdraw_mode" value="delete" checked>' . esc_html__('Delete everything and close my account', 'atshift-members') . '</label>' . '<label>' . '<input type="radio" name="withdraw_mode" value="transfer">' . esc_html__('Transfer selected posts to the site operator and close my account', 'atshift-members') . '</label>' . '</fieldset>');
                echo ('<div class="atshme-transfer-options">' . '<h3>' . esc_html__('Select Posts to Retain', 'atshift-members') . '</h3>' . '<p>' . esc_html__('Open each post and check its text, images, and attachments for information you do not want to retain.', 'atshift-members') . '</p>');
                $candidates=Withdrawal::candidates($id);
                if(!$candidates)echo ('<p>' . esc_html__('No posts are available for transfer.', 'atshift-members') . '</p>');
                foreach($candidates as $post)echo '<p><label><input type="checkbox" name="transfer_posts[]" value="'.(int)$post->ID.'">'.esc_html($post->post_title?:__('(Untitled)', 'atshift-members')).'</label> <a href="'.esc_url(get_edit_post_link($post->ID)).('" target="_blank" rel="noopener">' . esc_html__('Review and Edit Content', 'atshift-members') . '</a>' . '</p>');
                echo ('<label>' . '<input type="checkbox" name="transfer_consent" value="yes">' . esc_html__('I have reviewed the selected posts and my associated attachments, and I agree to their retention, editing, and republication under the site operator\'s ownership.', 'atshift-members') . '</label>' . '</div>');
                $this->button(__('Review Account Closure', 'atshift-members'));
            }
        } elseif ($screen==='reset') {
            if (!empty($_COOKIE['atshme_reset'])) {$this->form('reset_password');$this->password();$this->button(__('Reset Your Password', 'atshift-members'));}
            else {$this->form('reset_request');echo ('<p>' . '<label>' . esc_html__('Email Address ', 'atshift-members') . '<input type="email" name="email" autocomplete="email" required>' . '</label>' . '</p>');$this->challenge();$this->button(__('Request Password Reset Email', 'atshift-members'));}
        } else {
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- POST actions below are gated by the browser/user-bound CSRF HMAC in handle(); GET only stages or displays proofs, which are validated on submission.
            $token=self::str($_GET,'atshme_token');$session=$this->reg->store->session(self::str($_COOKIE,'atshme_session'),$this->browser);
            if (is_user_logged_in()) echo ('<p>' . esc_html__('Log out before registering.', 'atshift-members') . '</p>');
            elseif ($token) {$this->form('verify');echo '<input type="hidden" name="token" value="'.esc_attr($token).('">' . '<p>' . esc_html__('Confirm your email address to continue registration.', 'atshift-members') . '</p>');$this->button(__('Confirm Email Address', 'atshift-members'));}
            elseif ($session && $session->kind==='member' && $api) {$this->form('complete');echo ('<p>' . esc_html__('Verified email address: ', 'atshift-members')).esc_html($session->email).'</p>';$this->username();$this->password();call_user_func($api['render'],Registration::fields(),apply_filters('atshift_members_registration_defaults',[],$session));$this->button(__('Register', 'atshift-members'));}
            elseif (!$this->reg->ready() || !Registration::option('enabled')) echo ('<p>' . esc_html__('Member registration is currently closed.', 'atshift-members') . '</p>');
            else {$this->form('start');echo ('<p>' . '<label>' . esc_html__('Email Address ', 'atshift-members') . '<input type="email" name="email" autocomplete="email" required>' . '</label>' . '</p>');$this->challenge();$this->button(__('Request Confirmation Email', 'atshift-members'));}
        }
        if ($screen==='account' && shortcode_exists('atshme_notice_preferences')) echo do_shortcode('[atshme_notice_preferences]');
        echo '</div>';return ob_get_clean();
    }
}
