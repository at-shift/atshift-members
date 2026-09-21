<?php
namespace Atshift\Membership;
defined('ABSPATH') || exit;

/** Persisted delegation restrictions survive removal of either paid integration. */
final class Scope {
    const META='_atshme_operator_scope';
    public static function limited($actor=null) {
        $actor=$actor??get_current_user_id();
        $scope=get_user_meta($actor,self::META,true);
        return metadata_exists('user',$actor,self::META) && (!is_array($scope)||($scope['mode']??'')!=='global');
    }
    public static function ready() {
        $api=Audience::api();
        return $api && ($api['assignment_version']??0)===1 && is_callable($api['users']??null) && is_callable($api['set_memberships']??null)
            && apply_filters('atshift_members_delegation_available',false)===true;
    }
    public static function groups($actor=null) {
        $actor=$actor??get_current_user_id();$scope=get_user_meta($actor,self::META,true);
        if(!Members::active($actor)||!user_can($actor,'atshme_manage_members')||!self::ready() || !is_array($scope) || ($scope['mode']??'')!=='groups' || !is_array($scope['groups']??null))return [];
        return array_values(array_intersect(array_filter($scope['groups'],'is_string'),array_keys(Audience::catalog())));
    }
    public static function own_keys($user) {$api=Audience::api();return $api?(array)call_user_func($api['memberships'],(int)$user):[];}
    public static function can_manage($target,$operation='view',$actor=null) {
        $actor=$actor??get_current_user_id();
        if(!user_can($actor,'atshme_manage_members')||Members::blocked($actor))return false;
        if(user_can($actor,'manage_options') || !self::limited($actor))return true;
        $user=get_userdata($target);
        if(!$user || $user->roles!==['atshme_member'] || (int)$target===$actor)return false;
        $owned=self::groups($actor);$current=self::own_keys($target);
        if(!$owned || !array_intersect($owned,$current))return false;
        // Whole-account changes affect every branch, so partial managers cannot perform them.
        if($operation==='state')return !array_diff($current,$owned);
        if($operation==='privilege')return false;
        return true;
    }
    public static function target_ids($actor=null) {
        $actor=$actor??get_current_user_id();$groups=self::groups($actor);$api=Audience::api();
        if(!$groups || !$api)return [];
        $ids=(array)call_user_func($api['users'],$groups);
        return array_values(array_filter(array_map('absint',$ids),function($id)use($actor){return self::can_manage($id,'view',$actor);}));
    }
    public static function can_assign($target,$before,$after) {
        if(!self::ready()||!self::can_manage($target,'assign'))return false;
        $u=get_userdata($target);
        if(!$u || $u->roles!==['atshme_member'] || in_array(get_user_meta($target,'_atshme_state',true),['withdrawing','withdrawn','provisioning'],true))return false;
        if(!self::limited())return true;
        $changed=array_merge(array_diff($before,$after),array_diff($after,$before));
        return !array_diff($changed,self::groups());
    }
    public static function content($id,$actor=null,$proposed=null) {
        $actor=$actor??get_current_user_id();if(!self::limited($actor)||user_can($actor,'manage_options'))return true;
        $p=get_post($id);if(!$p)return false;
        if($p->post_type==='revision'||$p->post_type==='attachment')return self::content($p->post_parent,$actor,$proposed);
        // Generic post types currently allow scoped operators to manage only their own posts.
        if(isset(Posting::types()[$p->post_type])&&!in_array($p->post_type,['atshme_post','atshme_page','atshme_notice'],true))return self::ready()&&(int)$p->post_author===$actor;
        if(!in_array($p->post_type,['atshme_post','atshme_page','atshme_notice'],true))return false;
        if((int)$p->post_author===$actor && $proposed===null)return true;
        $key=$proposed??get_post_meta($id,Audience::META,true);
        $current=get_post_meta($id,Audience::META,true);
        if($key==='' || !in_array($key,self::groups($actor),true))return false;
        if($p->post_status==='publish' && !in_array($current,self::groups($actor),true))return false;
        return (int)$p->post_author===$actor || self::can_manage((int)$p->post_author,'view',$actor);
    }
    public static function delivery($group,$actor=null) {
        $actor=$actor??get_current_user_id();return !self::limited($actor)||($group!==''&&in_array($group,self::groups($actor),true));
    }
    public static function campaign($id,$actor=null) {
        $actor=$actor??get_current_user_id();$p=get_post($id);$plan=get_post_meta($id,'_atshme_campaign',true);
        return $p&&$p->post_type==='atshme_campaign'&&is_array($plan)&&self::delivery($plan['group']??'',$actor)
            && (!self::limited($actor)||(int)$p->post_author===$actor);
    }
    public static function hooks() {
        add_action('admin_menu',function(){if(self::limited()&&!self::ready())remove_menu_page('atshift-members');},100);
        add_filter('map_meta_cap',function($caps,$cap,$actor,$args){
            if($cap==='manage_options'||!self::limited($actor)||user_can($actor,'manage_options'))return $caps;
            if(in_array($cap,['edit_user','delete_user','promote_user','remove_user'],true)&&isset($args[0])&&(int)$args[0]!==$actor)return ['do_not_allow'];
            if(in_array($cap,['edit_post','delete_post'],true)&&isset($args[0])&&!self::content((int)$args[0],$actor))return ['do_not_allow'];
            if(in_array($cap,['list_users','edit_users','create_users','delete_users','promote_users','publish_atshme_notices','atshme_import_members'],true))return ['do_not_allow'];
            return $caps;
        },PHP_INT_MAX,4);
        foreach(['atshme_post','atshme_page','atshme_notice'] as $type)add_filter('rest_'.$type.'_query',function($args,$request){if($request->get_param('context')==='edit')$args['atshme_scope_manage']=true;return $args;},10,2);
        add_filter('posts_where',function($where,$query){
            if((!is_admin()&&!$query->get('atshme_scope_manage'))||!self::limited()||current_user_can('manage_options'))return $where;
            global $wpdb;$actor=get_current_user_id();$targets=self::target_ids();$groups=self::groups();
            $managed='0=1';if($targets&&$groups)$managed="{$wpdb->posts}.post_author IN (".implode(',',array_map('absint',$targets)).") AND {$wpdb->posts}.ID IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_atshme_audience' AND meta_value IN (".implode(',',array_map(function($k)use($wpdb){return $wpdb->prepare('%s',$k);},$groups)).'))';
            return $where." AND ({$wpdb->posts}.post_type NOT IN ('atshme_post','atshme_page','atshme_notice') OR {$wpdb->posts}.post_author=".(int)$actor.' OR ('.$managed.'))';
        },PHP_INT_MAX,2);
        add_action('atshift_upf_memberships_changed',function(){wp_cache_set_comments_last_changed();wp_cache_set_posts_last_changed();});
        add_action('pre_user_query',function($query){
            if(!self::limited()||current_user_can('manage_options'))return;
            global $wpdb;$ids=array_merge([get_current_user_id()],self::target_ids());
            $query->query_where.=' AND '.$wpdb->users.'.ID IN ('.implode(',',array_map('absint',$ids)).')';
        },PHP_INT_MAX);
        add_filter('atshift_upf_can_assign_memberships',function($allowed,$target,$before,$after){return $allowed||self::can_assign($target,$before,$after);},10,4);
    }
}
