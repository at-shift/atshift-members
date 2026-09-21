<?php
namespace Atshift\Membership;
defined('ABSPATH') || exit;

/** Per-type grants without granting shared WordPress capabilities to member roles. */
final class Posting {
    const OPTION='atshme_posting_rules';
    private static $aliases=[];
    private static $wrapped=[];
    private const OWN=['create_posts','edit_posts','edit_published_posts','edit_private_posts','publish_posts','delete_posts','delete_published_posts','delete_private_posts'];
    public static function supported($type) {
        return $type && $type->show_ui && $type->map_meta_cap && post_type_supports($type->name,'editor')
            && (!$type->_builtin || in_array($type->name,['post','page'],true));
    }
    public static function types() {
        return array_filter(get_post_types([],'objects'),[self::class,'supported']);
    }
    public static function wrap($name,$type) {
        if(!self::supported($type)||isset(self::$wrapped[$name])&&self::$wrapped[$name]===$type)return;
        self::$wrapped[$name]=$type;
        foreach(array_merge(self::OWN,['edit_others_posts','delete_others_posts','read_private_posts']) as $operation){
            if(!isset($type->cap->$operation))continue;
            $alias='atshme_posting_'.$name.'_'.$operation;
            self::$aliases[$alias]=['type'=>$name,'operation'=>$operation,'original'=>$type->cap->$operation];
            $type->cap->$operation=$alias;
        }
    }
    public static function rules() { $rules=get_option(self::OPTION,[]);return is_array($rules)?$rules:[]; }
    public static function stamp() {return hash('sha256',serialize(get_option(self::OPTION,[])));}
    public static function rule($type) {
        $rules=self::rules();
        return $rules[$type]??['mode'=>$type==='post'?'all':'none','groups'=>[],'children'=>false];
    }
    public static function categories() {
        $api=Audience::api();if(!$api)return [];
        $catalog=Audience::catalog();$parents=is_callable($api['parents']??null)?(array)call_user_func($api['parents']):[];
        $result=[];foreach($catalog as $key=>$label)$result[$key]=['label'=>$label,'parent'=>isset($catalog[$parents[$key]??''])?$parents[$key]:''];
        return $result;
    }
    public static function hierarchy_available() {$api=Audience::api();return $api&&is_callable($api['parents']??null);}
    public static function allowed($type,$user) {
        if(!isset(self::types()[$type])||!Members::active($user))return false;
        if(Scope::limited($user)&&!Scope::ready())return false;
        $rule=self::rule($type);if(!is_array($rule))return false;
        if(($rule['mode']??'')==='all')return true;
        if(($rule['mode']??'')!=='groups'||!is_array($rule['groups']??null)||!Audience::api())return false;
        $categories=self::categories();$selected=array_intersect(array_filter($rule['groups'],'is_string'),array_keys($categories));
        if(!empty($rule['children'])&&!self::hierarchy_available())return false;
        // Only actual memberships confer authoring permission, never a delegated management scope.
        foreach(Scope::own_keys($user) as $key){
            $visited=[];
            while(isset($categories[$key])&&!isset($visited[$key])){
                if(in_array($key,$selected,true))return true;
                if(empty($rule['children']))break;
                $visited[$key]=true;$key=$categories[$key]['parent'];
            }
        }
        return false;
    }
    public static function labels($user) {
        $labels=[];foreach(self::types() as $type)if(self::allowed($type->name,$user))$labels[]=$type->label;
        return $labels;
    }
    public static function save($input,$stamp) {
        if(!current_user_can('manage_options'))return new \WP_Error('forbidden',__('Site administrator permissions are required.', 'atshift-members'));
        $store=atshift_members()->store;
        if(!$store->lock('posting-settings'))return new \WP_Error('busy',__('Posting settings are being saved. Please wait a moment and try again.', 'atshift-members'));
        try {return self::save_locked($input,$stamp);}
        finally {$store->unlock('posting-settings');}
    }
    private static function save_locked($input,$stamp) {
        wp_cache_delete(self::OPTION,'options');
        wp_cache_delete('notoptions','options');
        wp_cache_delete('alloptions','options');
        if(!is_string($stamp)||!hash_equals(self::stamp(),$stamp))return new \WP_Error('changed',__('Settings have changed. Reload this screen.', 'atshift-members'));
        $types=self::types();
        if(!is_array($input)||array_diff(array_keys($input),array_keys($types))||array_diff(array_keys($types),array_keys($input)))return new \WP_Error('posting',__('The list of post types has changed. Reload this screen.', 'atshift-members'));
        $rules=self::rules();$categories=self::categories();
        foreach($input as $type=>$row){
            if(!is_array($row)||!is_string($row['mode']??null))return new \WP_Error('posting',__('Choose which members are allowed to post.', 'atshift-members'));
            $mode=$row['mode'];
            if($mode==='keep'&&(self::rule($type)['mode']??'')==='groups'&&!Audience::api())continue;
            if(!in_array($mode,['all','none','groups'],true))return new \WP_Error('posting',__('Choose which members are allowed to post.', 'atshift-members'));
            $groups=[];$children=false;
            if($mode==='groups'){
                $groups=$row['groups']??[];
                if(!Audience::api()||!is_array($groups)||!$groups||array_filter($groups,fn($key)=>!is_string($key)||!isset($categories[$key])))return new \WP_Error('groups',__('Select the allowed user categories.', 'atshift-members'));
                $groups=array_values(array_unique($groups));$children=!empty($row['children']);
                if($children&&!self::hierarchy_available())return new \WP_Error('groups',__('The latest atshift User Profile Fields Pro is required to include child categories.', 'atshift-members'));
            }
            $rules[$type]=['mode'=>$mode,'groups'=>$groups,'children'=>$children];
        }
        if($rules!==self::rules()&&!update_option(self::OPTION,$rules,false))return new \WP_Error('save',__('Could not save posting settings.', 'atshift-members'));
        return true;
    }
    public static function caps($caps,$cap,$id,$args) {
        $user=get_userdata($id);$managed=$user&&Members::managed($user)&&empty($user->allcaps['manage_options']);
        // Respect denies from membership status, audience, delegation and other plugins.
        if(in_array('do_not_allow',$caps,true))return $caps;
        if($managed&&in_array($cap,['edit_post','delete_post'],true)&&isset($args[0])){
            $post=get_post((int)$args[0]);if($post&&$post->post_type==='revision')$post=get_post($post->post_parent);
            if($post&&isset(self::types()[$post->post_type])){
                if(!self::allowed($post->post_type,$id)||in_array((int)$post->ID,array_map('intval',(array)get_option('atshme_pages',[])),true))return ['do_not_allow'];
            }
        }
        foreach($caps as &$required){
            if(!isset(self::$aliases[$required]))continue;
            $alias=self::$aliases[$required];
            if($alias['original']==='do_not_allow'){$required='do_not_allow';continue;}
            if(!$managed){$required=$alias['original'];continue;}
            if(!self::allowed($alias['type'],$id)){$required='do_not_allow';continue;}
            // No new grants to edit others or read their private posts. Existing operator caps remain scoped.
            $required=in_array($alias['operation'],self::OWN,true)?'read':$alias['original'];
        }
        unset($required);return array_values(array_unique($caps));
    }
    public static function hooks() {
        add_action('registered_post_type',[self::class,'wrap'],PHP_INT_MAX,2);
        foreach(get_post_types([],'objects') as $type)self::wrap($type->name,$type);
        add_action('init',function(){foreach(get_post_types([],'objects') as $type)self::wrap($type->name,$type);},PHP_INT_MAX);
        add_filter('map_meta_cap',[self::class,'caps'],PHP_INT_MAX,4);
    }
}
