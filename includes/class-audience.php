<?php
namespace Atshift\Membership;
defined('ABSPATH') || exit;

/** Restrictions live in core so removing an add-on never publishes protected data. */
final class Audience {
    const META = '_asm_audience';
    public static function api() {
        $api=apply_filters('atshift_upf_membership_groups_api',null);
        return is_array($api) && ($api['version']??0)===1 && is_callable($api['catalog']??null) && is_callable($api['memberships']??null) ? $api : null;
    }
    public static function catalog() { $api=self::api();return $api?(array)call_user_func($api['catalog']):[]; }
    public static function keys($user) { $api=self::api();$callback=$api['effective_memberships']??($api['memberships']??null);$keys=is_callable($callback)?(array)call_user_func($callback,(int)$user):[];return array_values(array_unique(array_merge($keys,Scope::limited($user)?Scope::groups($user):[]))); }
    public static function restricted($id) { return metadata_exists('post',$id,self::META); }
    public static function allows($id,$user) {
        $post=get_post($id);if(!$post)return false;
        if($post->post_type==='revision')return self::allows($post->post_parent,$user);
        if(metadata_exists('post',$id,'_asm_publication_lock')&&!user_can($user,'manage_options'))return false;
        if(!self::restricted($id))return true;
        if(user_can($user,'manage_options'))return true;
        if(!Members::active($user))return false;
        return in_array(get_post_meta($id,self::META,true),self::keys($user),true);
    }
    public static function sql($column) {
        global $wpdb;
        if(current_user_can('manage_options'))return '1=1';
        $keys=Members::active(get_current_user_id())?self::keys(get_current_user_id()):[];
        $allowed=$keys?' AND meta_value NOT IN ('.implode(',',array_map(function($k)use($wpdb){return $wpdb->prepare('%s',$k);},$keys)).')':'';
        return "$column NOT IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_asm_publication_lock') AND $column NOT IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_asm_audience'$allowed)";
    }
    public static function hooks() {
        // Comment query cache keys are calculated before comments_clauses in WordPress.
        // Partition by viewer and memberships; invalidate on changes to protected objects.
        add_action('pre_get_comments',function($q){$q->query_vars['cache_domain']='asm_'.Store::digest(wp_json_encode([get_current_user_id(),Members::reader(),self::keys(get_current_user_id()),current_user_can('asm_manage_members')]));});
        foreach(['added_user_meta','updated_user_meta','deleted_user_meta'] as $hook)add_action($hook,function($mid,$uid,$key){if($key==='_asm_state'){wp_cache_set_comments_last_changed();wp_cache_set_posts_last_changed();}},10,3);
        foreach(['added_post_meta','updated_post_meta','deleted_post_meta'] as $hook)add_action($hook,function($mid,$pid,$key){if(in_array($key,[self::META,'_asm_members_only','_asm_publication_lock'],true)){wp_cache_set_comments_last_changed();wp_cache_set_posts_last_changed();}},10,3);
        add_filter('posts_where',function($where){global $wpdb;return $where.' AND '.self::sql("{$wpdb->posts}.ID");},PHP_INT_MAX);
        add_filter('comments_clauses',function($clauses){global $wpdb;$clauses['where'].=' AND '.self::sql("{$wpdb->comments}.comment_post_ID");return $clauses;},PHP_INT_MAX);
        add_filter('map_meta_cap',function($caps,$cap,$user,$args){
            if(in_array($cap,['read_post','edit_post','delete_post'],true) && isset($args[0]) && !self::allows((int)$args[0],$user))return ['do_not_allow'];
            return $caps;
        },PHP_INT_MAX,4);
        add_filter('wp_sitemaps_posts_query_args',function($args){$args['meta_query']=['relation'=>'AND',$args['meta_query']??[],['key'=>self::META,'compare'=>'NOT EXISTS'],['key'=>'_asm_publication_lock','compare'=>'NOT EXISTS']];return $args;},20);
        add_filter('pre_get_posts',function($q){$q->set('suppress_filters',false);});
    }
}
