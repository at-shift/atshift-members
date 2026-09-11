<?php
namespace Atshift\Membership;
defined('ABSPATH') || exit;
final class Content {
    public static function hooks() {
        add_action('init',[self::class,'types']);
        add_filter('posts_where',[self::class,'where'],PHP_INT_MAX,2);
        add_action('pre_get_posts',function($query){if (!Members::reader()) $query->set('suppress_filters',false);});
        add_filter('comments_clauses',[self::class,'comments'],PHP_INT_MAX);
        add_filter('map_meta_cap',[self::class,'caps'],PHP_INT_MAX,4);
        add_action('template_redirect',[self::class,'guard'],0);
        add_filter('rest_pre_dispatch',[self::class,'rest'],PHP_INT_MAX,3);
        add_filter('wp_sitemaps_post_types',function($types){unset($types['asm_post'],$types['asm_notice']);return $types;});
        add_filter('wp_sitemaps_add_provider',function($provider,$name){return $name==='users'?false:$provider;},10,2);
        add_filter('wp_sitemaps_posts_query_args',[self::class,'sitemap'],10,2);
        add_filter('the_content',function($content){return self::denied(get_the_ID()) ? '' : $content;},PHP_INT_MAX);
        add_filter('the_excerpt',function($content){return self::denied(get_the_ID()) ? '' : $content;},PHP_INT_MAX);
        add_filter('oembed_response_data',function($data,$post){return self::protected($post->ID)||self::withdrawn($post)?false:$data;},PHP_INT_MAX,2);
        add_filter('rest_post_dispatch',function($response){if (is_user_logged_in() && $response instanceof \WP_REST_Response) $response->header('Cache-Control','private, no-store, max-age=0'); return $response;},PHP_INT_MAX);
        add_action('save_post',function($id){clean_post_cache($id);do_action('atshift_members_content_changed',$id);});
    }
    public static function types() {
        global $wpdb;
        foreach (['asm_post'=>[__('Member Posts', 'atshift-members'),'asm_posts',__('Member Post', 'atshift-members')], 'asm_page'=>[__('Public Pages', 'atshift-members'),'asm_pages',__('Public Page', 'atshift-members')], 'asm_notice'=>[__('Member Announcements', 'atshift-members'),'asm_notices',__('Member Announcement', 'atshift-members')]] as $type=>$spec) {
            // Retain existing content, but do not create dedicated post types on new sites.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Security-sensitive current state or atomic transaction/lock operation; WordPress object caching cannot provide these fresh predicates or synchronization semantics.
            if(!isset(Posting::rules()[$type])&&!$wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type=%s LIMIT 1",$type)))continue;
            register_post_type($type,[
                'label'=>$spec[0],
                'labels'=>[
                    'name'=>$spec[0],'singular_name'=>$spec[2],'menu_name'=>$spec[0],
                    'add_new'=>__('Add New', 'atshift-members'),
                    /* translators: %s: Post type label. */
                    'add_new_item'=>sprintf(__('Add New %s', 'atshift-members'),$spec[2]),
                    /* translators: %s: Post type label. */
                    'edit_item'=>sprintf(__('Edit %s', 'atshift-members'),$spec[2]),
                    /* translators: %s: Post type label. */
                    'new_item'=>sprintf(__('New %s', 'atshift-members'),$spec[2]),
                    /* translators: %s: Post type label. */
                    'view_item'=>sprintf(__('View %s', 'atshift-members'),$spec[2]),
                    /* translators: %s: Post type label. */
                    'view_items'=>sprintf(__('View %s', 'atshift-members'),$spec[0]),
                    /* translators: %s: Post type label. */
                    'search_items'=>sprintf(__('Search %s', 'atshift-members'),$spec[0]),
                    /* translators: %s: Post type label. */
                    'not_found'=>sprintf(__('No %s found.', 'atshift-members'),$spec[0]),
                    /* translators: %s: Post type label. */
                    'not_found_in_trash'=>sprintf(__('No %s found in Trash.', 'atshift-members'),$spec[0]),
                    /* translators: %s: Post type label. */
                    'all_items'=>sprintf(__('All %s', 'atshift-members'),$spec[0]),
                    /* translators: %s: Post type label. */
                    'archives'=>sprintf(__('%s Archives', 'atshift-members'),$spec[2]),
                    /* translators: %s: Post type label. */
                    'attributes'=>sprintf(__('%s Attributes', 'atshift-members'),$spec[2]),
                    /* translators: %s: Post type label. */
                    'insert_into_item'=>sprintf(__('Insert into %s', 'atshift-members'),$spec[2]),
                    /* translators: %s: Post type label. */
                    'uploaded_to_this_item'=>sprintf(__('Uploaded to This %s', 'atshift-members'),$spec[2]),
                    /* translators: %s: Post type label. */
                    'filter_items_list'=>sprintf(__('Filter %s', 'atshift-members'),$spec[0]),
                    /* translators: %s: Post type label. */
                    'items_list_navigation'=>sprintf(__('%s List Navigation', 'atshift-members'),$spec[0]),
                    /* translators: %s: Post type label. */
                    'items_list'=>sprintf(__('%s List', 'atshift-members'),$spec[0]),
                    /* translators: %s: Post type label. */
                    'item_published'=>sprintf(__('%s published.', 'atshift-members'),$spec[2]),
                    /* translators: %s: Post type label. */
                    'item_published_privately'=>sprintf(__('%s published privately.', 'atshift-members'),$spec[2]),
                    /* translators: %s: Post type label. */
                    'item_reverted_to_draft'=>sprintf(__('%s reverted to draft.', 'atshift-members'),$spec[2]),
                    /* translators: %s: Post type label. */
                    'item_scheduled'=>sprintf(__('%s scheduled.', 'atshift-members'),$spec[2]),
                    /* translators: %s: Post type label. */
                    'item_updated'=>sprintf(__('%s updated.', 'atshift-members'),$spec[2]),
                    /* translators: %s: Post type label. */
                    'item_link'=>sprintf(__('%s Link', 'atshift-members'),$spec[2]),
                    /* translators: %s: Post type label. */
                    'item_link_description'=>sprintf(__('A link to a %s.', 'atshift-members'),$spec[2]),
                ], 'public'=>true, 'publicly_queryable'=>true,
                'show_ui'=>true,'show_in_rest'=>true,'rest_base'=>$spec[1],
                'exclude_from_search'=>$type!=='asm_page','has_archive'=>false,
                'rewrite'=>['slug'=>$spec[1]],'capability_type'=>[$type,$spec[1]],'map_meta_cap'=>true,
                'supports'=>['title','editor','revisions'],'delete_with_user'=>false,
            ]);
        }
    }
    public static function protected($id) {
        $post=get_post($id);if (!$post) return false;
        if ($post->post_type==='revision') return self::protected($post->post_parent);
        return metadata_exists('post',$id,'_asm_publication_lock') || metadata_exists('post',$id,Files::META) || Audience::restricted($id) || in_array($post->post_type,['asm_post','asm_notice'],true) || get_post_meta($id,'_asm_members_only',true)==='1';
    }
    public static function withdrawn($post) {return $post && in_array(get_user_meta($post->post_author,'_asm_state',true),['withdrawn','withdrawing'],true);}
    public static function denied($id) {
        $post=get_post($id);
        if($post && $post->post_type==='revision')return self::denied($post->post_parent);
        if($post && metadata_exists('post',$id,Files::META))return !Files::readable($id);
        return !Audience::allows($id,get_current_user_id()) || (self::withdrawn($post) && !current_user_can('asm_manage_members')) || (self::protected($id) && !Members::reader());
    }
    private static function sql($column) {
        global $wpdb;
        return "$column NOT IN (SELECT asm_hidden.ID FROM {$wpdb->posts} asm_hidden WHERE asm_hidden.post_type IN ('asm_post','asm_notice')) AND $column NOT IN (SELECT asm_meta.post_id FROM {$wpdb->postmeta} asm_meta WHERE asm_meta.meta_key='_asm_members_only' AND asm_meta.meta_value='1')";
    }
    public static function where($where,$query) {
        if (!Members::reader()) {global $wpdb;$where.=' AND '.self::sql("{$wpdb->posts}.ID");}
        if(!current_user_can('asm_manage_members')) {global $wpdb;$where.=" AND {$wpdb->posts}.post_author NOT IN (SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key='_asm_state' AND meta_value IN ('withdrawn','withdrawing'))";}
        return $where;
    }
    public static function comments($clauses) {
        if (!Members::reader()) {global $wpdb;$clauses['where'].=' AND '.self::sql("{$wpdb->comments}.comment_post_ID");}
        if(!current_user_can('asm_manage_members')) {global $wpdb;$clauses['where'].=" AND {$wpdb->comments}.comment_post_ID NOT IN (SELECT ID FROM {$wpdb->posts} WHERE post_author IN (SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key='_asm_state' AND meta_value IN ('withdrawn','withdrawing')))";}
        return $clauses;
    }
    public static function caps($caps,$cap,$id,$args) {
        if (in_array($cap,['read_post','edit_post','delete_post'],true) && isset($args[0])) {
            $post=get_post((int)$args[0]);
            if ($post && self::protected($post->ID) && !user_can($id,'manage_options') && !Members::active($id)) return ['do_not_allow'];
        }
        return $caps;
    }
    public static function guard() {
        if (is_user_logged_in()) Screens::private_headers();
        if (is_author() && Members::managed(get_userdata(get_queried_object_id()))) {
            Screens::private_headers();wp_die(esc_html__('Page not found.', 'atshift-members'),'',['response'=>404]);
        }
        if (is_singular() && (self::protected(get_queried_object_id()) || self::withdrawn(get_post(get_queried_object_id())))) {
            Screens::private_headers();
            if (self::denied(get_queried_object_id())) {status_header(404);wp_die(esc_html__('Page not found.', 'atshift-members'),'',['response'=>404]);}
        }
    }
    public static function rest($result,$server,$request) {
        $route=$request->get_route();
        if (preg_match('#^/wp/v2/users(?:/(\d+))?(?:/|$)#',$route,$m) && !current_user_can('list_users') && !(is_user_logged_in() && preg_match('#^/wp/v2/users/me(?:/|$)#',$route)) && (empty($m[1]) || (int)$m[1]!==get_current_user_id())) return new \WP_Error('rest_forbidden',__('You cannot view this content.', 'atshift-members'),['status'=>403]);
        if (strpos($route,'/oembed/1.0/embed')===0 && self::protected(url_to_postid((string)$request->get_param('url')))) return new \WP_Error('rest_not_found',__('Not found.', 'atshift-members'),['status'=>404]);
        {
            foreach (get_post_types(['show_in_rest'=>true],'objects') as $type) {
                $base=$type->rest_base?:$type->name;$namespace=$type->rest_namespace?:'wp/v2';
                if (preg_match('#^/'.preg_quote($namespace,'#').'/'.preg_quote($base,'#').'/(\d+)(?:/|$)#',$route,$m) && self::denied((int)$m[1])) return new \WP_Error('rest_not_found',__('Not found.', 'atshift-members'),['status'=>404]);
            }
            if (preg_match('#^/wp/v2/comments/(\d+)#',$route,$m)) {$c=get_comment((int)$m[1]);if ($c && self::denied($c->comment_post_ID)) return new \WP_Error('rest_not_found',__('Not found.', 'atshift-members'),['status'=>404]);}
        }
        return $result;
    }
    public static function sitemap($args,$type) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Security-sensitive current state or atomic transaction/lock operation; WordPress object caching cannot provide these fresh predicates or synchronization semantics.
        $args['author__not_in']=array_unique(array_merge($args['author__not_in']??[],array_map('intval',$wpdb->get_col("SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key='_asm_state' AND meta_value IN ('withdrawn','withdrawing')"))));
        $args['meta_query']=['relation'=>'AND',$args['meta_query']??[],['relation'=>'OR',['key'=>'_asm_members_only','compare'=>'NOT EXISTS'],['key'=>'_asm_members_only','value'=>'1','compare'=>'!=']]];
        $args['post__not_in']=array_unique(array_merge($args['post__not_in']??[],array_map('intval',array_values(get_option('asm_pages',[])))));
        if (in_array($type,['asm_post','asm_notice'],true)) $args['post__in']=[0];
        return $args;
    }
}
