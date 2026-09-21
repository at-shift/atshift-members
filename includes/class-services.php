<?php
namespace Atshift\Membership;
defined('ABSPATH') || exit;
final class Services {
    public static function turnstile_locked() {
        return defined('ATSHME_TURNSTILE_SITE_KEY') || defined('ATSHME_TURNSTILE_SECRET');
    }
    public static function turnstile_keys() {
        // A server-managed pair must never be mixed with keys saved in the UI.
        $keys=self::turnstile_locked()
            ? ['site'=>defined('ATSHME_TURNSTILE_SITE_KEY')?ATSHME_TURNSTILE_SITE_KEY:'','secret'=>defined('ATSHME_TURNSTILE_SECRET')?ATSHME_TURNSTILE_SECRET:'']
            : get_option('atshme_turnstile',[]);
        return ['site'=>is_string($keys['site']??null)?$keys['site']:'','secret'=>is_string($keys['secret']??null)?$keys['secret']:''];
    }
    public static function save_turnstile($input) {
        if (!current_user_can('manage_options')) return new \WP_Error('forbidden',__('Site administrator permissions are required.', 'atshift-members'));
        if (self::turnstile_locked()) return new \WP_Error('fixed',__('This site\'s keys are configured on the server.', 'atshift-members'));
        if (!is_array($input)) return new \WP_Error('keys',__('Enter the two keys issued by Cloudflare.', 'atshift-members'));
        if (($input['clear']??'')==='1') {delete_option('atshme_turnstile');return true;}
        $old=self::turnstile_keys();$keys=[];
        foreach (['site','secret'] as $key) {
            if (!is_string($input[$key]??null)) return new \WP_Error('keys',__('Enter the two keys issued by Cloudflare.', 'atshift-members'));
            $keys[$key]=trim($input[$key]);
            if ($key==='secret' && $keys[$key]==='') $keys[$key]=$old[$key];
            if (!preg_match('/^[A-Za-z0-9_-]{1,256}$/D',$keys[$key])) return new \WP_Error('keys',__('Both keys are required. Check them and copy them from the Cloudflare dashboard.', 'atshift-members'));
        }
        if ($keys['site']!==$old['site'] && trim($input['secret'])==='') return new \WP_Error('keys',__('When changing the site key, enter the matching secret key from the same widget settings.', 'atshift-members'));
        if (wp_get_environment_type()==='production' && (preg_match('/^[123]x0/',$keys['site']) || preg_match('/^[123]x0/',$keys['secret']))) return new \WP_Error('keys',__('Test keys cannot be used on a production site. Get keys for this site from Cloudflare.', 'atshift-members'));
        update_option('atshme_turnstile',$keys,false);
        return true;
    }
    public static function configured() {
        $keys=self::turnstile_keys();
        if (!$keys['site'] || !$keys['secret']) return false;
        if (wp_get_environment_type() === 'production' && (preg_match('/^[123]x0/', $keys['site']) || preg_match('/^[123]x0/', $keys['secret']))) return false;
        return is_ssl() || wp_get_environment_type() === 'local';
    }
    public static function turnstile($token) {
        if (!self::configured() || !is_string($token) || !$token || strlen($token)>2048) return false;
        // phpcs:ignore PluginCheck.CodeAnalysis.Offloading.OffloadedContent -- Required Cloudflare Turnstile service endpoint/script, not a hosted plugin asset; external service use and privacy are documented in readme.
        $res = wp_remote_post('https://challenges.cloudflare.com/turnstile/v0/siteverify', ['timeout'=>8,'redirection'=>0,'body'=>['secret'=>self::turnstile_keys()['secret'],'response'=>$token]]);
        if (is_wp_error($res) || wp_remote_retrieve_response_code($res)!==200) return false;
        $data = json_decode(wp_remote_retrieve_body($res), true);
        $stamp = isset($data['challenge_ts']) ? strtotime($data['challenge_ts']) : false;
        return is_array($data) && ($data['success']??false) === true
            && ($data['hostname']??'') === wp_parse_url(home_url(),PHP_URL_HOST)
            && ($data['action']??'') === 'atshme_register'
            && $stamp !== false && $stamp <= time()+30 && $stamp >= time()-300;
    }
    public static function password($password) {
        if (!is_string($password) || preg_match('//u',$password)!==1 || preg_match_all('/./us',$password)<12 || strlen($password)>256) return new \WP_Error('password',__('Use a password of at least 12 characters and no more than 256 bytes.', 'atshift-members'));
        // WordPress trims before hashing. Reject ambiguous inputs before range lookup.
        if (trim($password)!==$password || preg_match('/[\x00-\x1F\x7F]/',$password)) return new \WP_Error('password',__('Leading or trailing spaces and control characters are not allowed.', 'atshift-members'));
        if (defined('ATSHME_PWNED_PASSWORDS_ENABLED') && !ATSHME_PWNED_PASSWORDS_ENABLED) return true;
        $hash = strtoupper(sha1($password));
        $response = wp_remote_get('https://api.pwnedpasswords.com/range/'.substr($hash,0,5), ['timeout'=>5,'redirection'=>0,'limit_response_size'=>2000000,'headers'=>['Add-Padding'=>'true']]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response)!==200) return new \WP_Error('password_service',__('Cannot connect to the password checking service. Please try again later.', 'atshift-members'));
        $lines = preg_split('/\r?\n/', trim(wp_remote_retrieve_body($response)));
        if (!$lines) return new \WP_Error('password_service',__('The verification service returned an invalid response. Please try again.', 'atshift-members'));
        foreach ($lines as $line) {
            if (!preg_match('/^([A-F0-9]{35}):([0-9]+)$/D',$line,$m)) return new \WP_Error('password_service',__('The verification service returned an invalid response. Please try again.', 'atshift-members'));
            if (hash_equals(substr($hash,5),$m[1]) && (int)$m[2]>0) return new \WP_Error('password_compromised',__('This password has appeared in a data breach. Choose a different password.', 'atshift-members'));
        }
        return true;
    }
}
