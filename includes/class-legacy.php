<?php
namespace Atshift\Membership;
defined('ABSPATH') || exit;

/** One-time migration from the pre-directory-review three-letter identifier prefix. */
final class Legacy {
    const VERSION='1';
    private static $error=null;
    private static function old_prefix() {return 'as'.'m_';}
    private static function old_private_prefix() {return '_'.self::old_prefix();}
    public static function name($suffix,$private=false) {return ($private?'_':'').self::old_prefix().$suffix;}
    public static function constant_name($suffix) {return strtoupper(self::old_prefix()).$suffix;}
    private static function map_identifier($value) {
        if(!is_string($value))return $value;
        if(str_starts_with($value,self::old_private_prefix()))return '_atshme_'.substr($value,strlen(self::old_private_prefix()));
        if(str_starts_with($value,self::old_prefix()))return 'atshme_'.substr($value,strlen(self::old_prefix()));
        return $value;
    }
    private static function map_value($value) {
        if(is_array($value)){
            $mapped=[];
            foreach($value as $key=>$item)$mapped[self::map_identifier($key)]=self::map_value($item);
            return $mapped;
        }
        return self::map_identifier($value);
    }
    /** Preserve server-defined configuration while sites rename wp-config constants. */
    public static function constants() {
        foreach(['TURNSTILE_SITE_KEY','TURNSTILE_SECRET','PWNED_PASSWORDS_ENABLED','PUBLIC_FIELDS','FILE_STORE','PRIVATE_DIR','PUBLIC_ROOT'] as $suffix){
            $new='ATSHME_'.$suffix;$old=self::constant_name($suffix);
            // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.VariableConstantNameFound -- Both names are assembled from fixed, plugin-owned prefix and suffix allowlists for one-time compatibility.
            if(!defined($new)&&defined($old))define($new,self::map_value(constant($old)));
        }
    }
    public static function error() {return self::$error;}
    public static function maybe_migrate() {
        if(get_option('atshme_prefix_migration')===self::VERSION)return true;
        global $wpdb;
        $store=atshift_members()->store;
        $store->install();
        Members::roles(false);
        $steps=[self::migrate_options(),self::migrate_meta('user'),self::migrate_meta('post'),self::migrate_roles(),self::migrate_post_types(),self::migrate_tables(),self::migrate_cron()];
        foreach($steps as $result)if(is_wp_error($result)){self::$error=$result;return false;}
        update_option('atshme_prefix_migration',self::VERSION,false);
        return true;
    }
    private static function migrate_options() {
        global $wpdb;$old=self::old_prefix();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time prefix migration must discover every prior Free/Pro option, including extension-defined settings.
        $rows=$wpdb->get_results($wpdb->prepare("SELECT option_name,option_value,autoload FROM {$wpdb->options} WHERE option_name LIKE %s",$wpdb->esc_like($old).'%'));
        if($rows===null)return new \WP_Error('migration_options','Could not migrate the existing Members settings.');
        foreach($rows as $row){
            $new=self::map_identifier($row->option_name);$missing=new \stdClass();
            if(get_option($new,$missing)!==$missing)continue;
            $autoload=in_array($row->autoload,['yes','on','auto-on','auto'],true);
            if(!add_option($new,self::map_value(maybe_unserialize($row->option_value)),'',$autoload))return new \WP_Error('migration_options','Could not migrate the existing Members settings.');
        }
        return true;
    }
    private static function migrate_meta($type) {
        global $wpdb;$user=$type==='user';
        if($user){
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time migration of plugin-owned user metadata; values are written through the metadata API below.
            $rows=$wpdb->get_results($wpdb->prepare("SELECT umeta_id AS mid,user_id AS object_id,meta_key,meta_value FROM {$wpdb->usermeta} WHERE meta_key LIKE %s OR meta_key LIKE %s",$wpdb->esc_like(self::old_private_prefix()).'%',$wpdb->esc_like(self::old_prefix()).'%'));
        }else{
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One-time migration of plugin-owned post metadata; values are written through the metadata API below.
            $rows=$wpdb->get_results($wpdb->prepare("SELECT meta_id AS mid,post_id AS object_id,meta_key,meta_value FROM {$wpdb->postmeta} WHERE meta_key LIKE %s OR meta_key LIKE %s",$wpdb->esc_like(self::old_private_prefix()).'%',$wpdb->esc_like(self::old_prefix()).'%'));
        }
        if($rows===null)return new \WP_Error('migration_meta','Could not migrate the existing member information.');
        foreach($rows as $row){
            $new=self::map_identifier($row->meta_key);
            if(!metadata_exists($type,(int)$row->object_id,$new)&&add_metadata($type,(int)$row->object_id,$new,self::map_value(maybe_unserialize($row->meta_value)),false)===false)return new \WP_Error('migration_meta','Could not migrate the existing member information.');
            if(!delete_metadata_by_mid($type,(int)$row->mid))return new \WP_Error('migration_meta','Could not remove an obsolete member-data key after migration.');
        }
        return true;
    }
    private static function migrate_roles() {
        global $wpdb;$roles=wp_roles();
        if(get_role(self::name('custodian'))&&!get_role('atshme_custodian'))add_role('atshme_custodian','Site Operator (Content Archive Only)',[]);
        // Preserve capabilities that Pro or another integration added to the
        // three built-in legacy roles before users are assigned their new role.
        foreach(['member','operator','custodian'] as $suffix){
            $legacy=get_role(self::name($suffix));$current=get_role('atshme_'.$suffix);
            if(!$legacy||!$current)continue;
            foreach($legacy->capabilities as $cap=>$grant)$current->add_cap(self::map_identifier($cap),$grant);
        }
        foreach($roles->role_objects as $role){
            foreach($role->capabilities as $cap=>$grant){
                $new=self::map_identifier($cap);if($new===$cap)continue;
                $role->add_cap($new,$grant);$role->remove_cap($cap);
            }
        }
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Locate only users whose serialized capability map contains the retired plugin prefix.
        $ids=$wpdb->get_col($wpdb->prepare("SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key=%s AND meta_value LIKE %s",$wpdb->get_blog_prefix().'capabilities','%'.$wpdb->esc_like(self::old_prefix()).'%'));
        if($ids===null)return new \WP_Error('migration_roles','Could not migrate the existing member roles.');
        foreach(array_map('intval',$ids) as $id){
            $account=new \WP_User($id);
            foreach(['member','operator','custodian'] as $suffix)if(in_array(self::name($suffix),$account->roles,true)){$account->add_role('atshme_'.$suffix);$account->remove_role(self::name($suffix));}
            foreach($account->caps as $cap=>$grant){$new=self::map_identifier($cap);if($new!==$cap){$account->add_cap($new,$grant);$account->remove_cap($cap);}}
        }
        foreach(['member','operator','custodian'] as $suffix)remove_role(self::name($suffix));
        return true;
    }
    private static function migrate_post_types() {
        global $wpdb;$old=self::old_prefix();
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Collect the affected IDs so WordPress object caches can be invalidated after the atomic rename.
        $ids=$wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type LIKE %s",$wpdb->esc_like($old).'%'));
        if($ids===null)return new \WP_Error('migration_posts','Could not locate the existing member content.');
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Atomic one-time rename of plugin-owned post types before they are registered under the new prefix.
        $result=$wpdb->query($wpdb->prepare("UPDATE {$wpdb->posts} SET post_type=CONCAT(%s,SUBSTRING(post_type,%d)) WHERE post_type LIKE %s",'atshme_',strlen($old)+1,$wpdb->esc_like($old).'%'));
        if($result===false)return new \WP_Error('migration_posts','Could not migrate the existing member content.');
        foreach(array_map('intval',$ids) as $id)clean_post_cache($id);
        return true;
    }
    public static function migrate_tables() {
        global $wpdb;
        foreach(['requests','limits','audit','deliveries'] as $suffix){
            $old=$wpdb->prefix.self::name($suffix);$new=$wpdb->prefix.'atshme_'.$suffix;
            if(!preg_match('/^[A-Za-z0-9_]+$/D',$old.$new))return new \WP_Error('migration_tables','Could not verify the Members data tables.');
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table identifiers are generated from the trusted WordPress prefix and a fixed allowlist.
            $old_exists=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$old));
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Table identifiers are generated from the trusted WordPress prefix and a fixed allowlist.
            $new_exists=$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s',$new));
            if($old_exists!==$old||$new_exists!==$new)continue;
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Both table identifiers passed a strict allowlist; schemas are identical across this prefix-only migration.
            if($wpdb->query("INSERT IGNORE INTO `{$new}` SELECT * FROM `{$old}`")===false)return new \WP_Error('migration_tables','Could not migrate the existing Members data tables.');
        }
        return true;
    }
    private static function migrate_cron() {
        $cron=get_option('cron',[]);if(!is_array($cron))return true;$changed=false;
        foreach($cron as $timestamp=>&$hooks){
            if($timestamp==='version'||!is_array($hooks))continue;
            foreach(array_keys($hooks) as $hook){$new=self::map_identifier($hook);if($new===$hook)continue;$hooks[$new]=($hooks[$new]??[])+$hooks[$hook];unset($hooks[$hook]);$changed=true;}
        }
        unset($hooks);
        return !$changed||update_option('cron',$cron)?true:new \WP_Error('migration_cron','Could not migrate the scheduled Members tasks.');
    }
    /** Keep existing pages working while administrators replace the old shortcode names. */
    public static function shortcode_aliases() {
        global $shortcode_tags;
        foreach((array)$shortcode_tags as $tag=>$callback)if(str_starts_with($tag,'atshme_'))add_shortcode(self::name(substr($tag,strlen('atshme_'))),$callback);
    }
}
