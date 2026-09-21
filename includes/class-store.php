<?php
namespace Atshift\Membership;
defined('ABSPATH') || exit;

/** Durable claims and fixed-window counters. No read/delete transient locks. */
final class Store {
    private $db;
    public $requests;
    public $limits;
    public $audit;
    public function __construct($db) {
        $this->db = $db;
        $this->requests = $db->prefix . 'atshme_requests';
        $this->limits = $db->prefix . 'atshme_limits';
        $this->audit = $db->prefix . 'atshme_audit';
    }
    public function install() {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $this->db->get_charset_collate();
        dbDelta("CREATE TABLE {$this->requests} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            email varchar(254) NOT NULL,
            email_key char(64) NOT NULL,
            token_hash char(64) DEFAULT NULL,
            session_hash char(64) DEFAULT NULL,
            browser_hash char(64) DEFAULT NULL,
            state varchar(16) NOT NULL DEFAULT 'queued',
            kind varchar(16) NOT NULL DEFAULT 'member',
            target_id bigint unsigned NOT NULL DEFAULT 0,
            expires bigint unsigned NOT NULL,
            user_id bigint unsigned NOT NULL DEFAULT 0,
            PRIMARY KEY  (id),
            UNIQUE KEY token_hash (token_hash),
            UNIQUE KEY session_hash (session_hash),
            KEY expires (expires),
            KEY email_key (email_key)
        ) ENGINE=InnoDB $charset;");
        dbDelta("CREATE TABLE {$this->limits} (
            counter_key char(64) NOT NULL,
            hits bigint unsigned NOT NULL,
            expires bigint unsigned NOT NULL,
            PRIMARY KEY  (counter_key),
            KEY expires (expires)
        ) ENGINE=InnoDB $charset;");
        dbDelta("CREATE TABLE {$this->audit} (
            id bigint unsigned NOT NULL AUTO_INCREMENT,
            event varchar(32) NOT NULL,
            subject char(64) NOT NULL,
            created bigint unsigned NOT NULL,
            PRIMARY KEY  (id),
            KEY created (created)
        ) ENGINE=InnoDB $charset;");
    }
    public function ready() {
        foreach ([$this->requests, $this->limits, $this->audit, $this->db->users, $this->db->usermeta] as $table) {
            $row = $this->db->get_row($this->db->prepare('SHOW TABLE STATUS WHERE Name = %s', $table));
            if (!$row || strtolower($row->Engine) !== 'innodb') return false;
        }
        return true;
    }
    public static function key($value) { return hash_hmac('sha256', $value, wp_salt('auth')); }
    public static function token() { return bin2hex(random_bytes(32)); }
    public static function digest($token) { return hash('sha256', $token); }
    public function hit($scope, $identity, $max, $seconds) {
        $bucket = intdiv(time(), $seconds);
        $key = self::key($scope . '|' . $identity . '|' . $bucket);
        $ok = $this->db->query($this->db->prepare(
            "INSERT INTO {$this->limits} (counter_key,hits,expires) VALUES (%s,LAST_INSERT_ID(1),%d)
             ON DUPLICATE KEY UPDATE hits=LAST_INSERT_ID(hits+1)", $key, ($bucket + 1) * $seconds
        ));
        if ($ok === false) return false;
        return (int)$this->db->get_var('SELECT LAST_INSERT_ID()') <= $max;
    }
    public function queue($email, $kind = 'member', $target_id = 0) {
        if (!in_array($kind, ['member','email','reset'], true)) return false;
        return $this->db->insert($this->requests, ['email'=>$email, 'email_key'=>self::key($email), 'kind'=>$kind, 'target_id'=>$target_id, 'expires'=>time()+1800]);
    }
    public function revoke($id) { return $this->db->update($this->requests, ['state'=>'revoked','token_hash'=>null,'session_hash'=>null,'email'=>''], ['id'=>$id]); }
    public function queued() { return $this->db->get_results("SELECT * FROM {$this->requests} WHERE state='queued' AND expires>UNIX_TIMESTAMP() ORDER BY id LIMIT 10"); }
    public function claim_mail($id, $token) {
        return 1 === $this->db->query($this->db->prepare("UPDATE {$this->requests} SET state='emailed',token_hash=%s WHERE id=%d AND state='queued' AND expires>%d", self::digest($token), $id, time()));
    }
    /** Read-only preview: a token never reveals another user's requested address. */
    public function email_preview($token,$user) {
        if(!preg_match('/^[a-f0-9]{64}$/D',$token)||$user!==get_current_user_id()||!Members::active($user))return null;
        return $this->db->get_var($this->db->prepare("SELECT email FROM {$this->requests} WHERE token_hash=%s AND kind='email' AND target_id=%d AND state='emailed' AND expires>%d",self::digest($token),$user,time()));
    }
    public function verify($token, $session, $browser) {
        if (!preg_match('/^[a-f0-9]{64}$/D', $token)) return false;
        return 1 === $this->db->query($this->db->prepare("UPDATE {$this->requests} SET state='verified',token_hash=NULL,session_hash=%s,browser_hash=%s,expires=%d WHERE token_hash=%s AND state='emailed' AND expires>%d", self::digest($session), self::digest($browser), time()+900, self::digest($token), time()));
    }
    public function session($session, $browser) {
        if (!preg_match('/^[a-f0-9]{64}$/D', $session) || !preg_match('/^[a-f0-9]{64}$/D', $browser)) return null;
        return $this->db->get_row($this->db->prepare("SELECT * FROM {$this->requests} WHERE session_hash=%s AND browser_hash=%s AND state='verified' AND expires>%d", self::digest($session), self::digest($browser), time()));
    }
    public function claim_creation($id) {
        return 1 === $this->db->query($this->db->prepare("UPDATE {$this->requests} SET state='creating',session_hash=NULL WHERE id=%d AND state='verified' AND expires>%d", $id, time()));
    }
    public function finish($id, $user_id) {
        return $this->db->update($this->requests, ['state'=>'complete','user_id'=>$user_id,'email'=>'','browser_hash'=>null], ['id'=>$id]);
    }
    private function lock_name($value) {
        $prefix='atshme_';
        return $prefix.substr(self::key($this->requests.'|'.$value),0,64-strlen($prefix));
    }
    public function lock($email) { return '1' === (string)$this->db->get_var($this->db->prepare('SELECT GET_LOCK(%s,0)', $this->lock_name($email))); }
    public function unlock($email) { $this->db->get_var($this->db->prepare('SELECT RELEASE_LOCK(%s)', $this->lock_name($email))); }
    public function log($event, $subject = '') {
        // Only internally defined event names; never request payloads or remote error strings.
        $this->db->insert($this->audit, ['event'=>$event,'subject'=>$subject ? self::key($subject) : '', 'created'=>time()]);
    }
    public function cleanup() {
        $this->db->query($this->db->prepare("DELETE FROM {$this->requests} WHERE expires<%d", time()));
        $this->db->query($this->db->prepare("DELETE FROM {$this->limits} WHERE expires<%d", time()));
        $this->db->query($this->db->prepare("DELETE FROM {$this->audit} WHERE created<%d", time()-7*DAY_IN_SECONDS));
    }
}
