<?php
namespace Atshift\Membership;
defined('ABSPATH') || exit;

/** Editor/library bridge. Private bytes never pass through the public uploader. */
final class Media {
    public static function enabled() {return Files::upload_available();}
    public static function member($actor=null) {
        $actor=$actor??get_current_user_id();$user=get_userdata($actor);
        return $user&&Members::managed($user)&&empty($user->allcaps['manage_options']);
    }
    public static function can_upload() {
        if(!Members::reader()||(Scope::limited()&&!Scope::ready()))return false;
        if(current_user_can('manage_options'))return true;
        foreach(Posting::types() as $type)if(current_user_can($type->cap->create_posts))return true;
        return false;
    }
    public static function target($id) {
        if(!$id)return self::can_upload();
        $post=get_post($id);
        return $post&&Posting::supported(get_post_type_object($post->post_type))&&Members::reader()&&current_user_can('edit_post',$id)&&!Content::denied($id);
    }
    public static function can_select($id) {
        $p=get_post($id);if(!$p||$p->post_type!=='attachment'||!Members::reader()||Members::blocked($p->post_author))return false;
        if(current_user_can('manage_options'))return true;
        if((int)$p->post_author===get_current_user_id())return !$p->post_parent||self::target($p->post_parent);
        $parent=get_post($p->post_parent);$type=$parent?get_post_type_object($parent->post_type):null;
        return $type&&current_user_can($type->cap->edit_others_posts)&&self::target($p->post_parent);
    }
    /** SQL for management lists, never for front-end reading. */
    public static function posts_sql($alias,$include_own=true) {
        global $wpdb;$actor=get_current_user_id();$others=[];
        foreach(Posting::types() as $name=>$type){
            if(!current_user_can($type->cap->edit_others_posts))continue;
            $clause="$alias.post_type=".$wpdb->prepare('%s',$name);
            if(Scope::limited()){
                if(!in_array($name,['asm_post','asm_page','asm_notice'],true))continue;
                $groups=Scope::groups();$targets=Scope::target_ids();if(!$groups||!$targets)continue;
                $clause.=" AND $alias.post_author IN (".implode(',',array_map('absint',$targets)).") AND $alias.ID IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_asm_audience' AND meta_value IN (".implode(',',array_map(fn($s)=>$wpdb->prepare('%s',$s),$groups)).'))';
            }
            $others[]='('.$clause.')';
        }
        if($include_own)array_unshift($others,"$alias.post_author=".(int)$actor);
        return $others?'('.implode(' OR ',$others).')':'(0=1)';
    }
    public static function attachments_sql($alias) {
        global $wpdb;
        return "($alias.post_author=".(int)get_current_user_id()." OR $alias.post_parent IN (SELECT asm_parent.ID FROM {$wpdb->posts} asm_parent WHERE ".self::posts_sql('asm_parent',false).'))';
    }
    public static function item($id) {
        $p=get_post($id);$private=get_post_meta($id,Files::META,true);$mime=$private['type']??$p->post_mime_type;
        $image=in_array($mime,Files::image_types(),true);$video=in_array($mime,Files::video_types(),true);$url=$private?Files::url($id,$image||$video):wp_get_attachment_url($id);
        $dimensions=$private?:wp_get_attachment_metadata($id);$width=(int)($dimensions['width']??0);$height=(int)($dimensions['height']??0);
        return ['id'=>(int)$id,'title'=>$p->post_title,'filename'=>$private['name']??basename(get_attached_file($id)?:$url),'url'=>$url,'source_url'=>$url,
            'mime'=>$mime,'mime_type'=>$mime,'type'=>$image?'image':($video?'video':'file'),'subtype'=>explode('/',$mime)[1]??'',
            'alt'=>get_post_meta($id,'_wp_attachment_image_alt',true),'caption'=>'','visibility'=>$private?'members':'public',
            'width'=>$width,'height'=>$height,'sizes'=>$image?['full'=>['url'=>$url,'width'=>$width,'height'=>$height]]:[],'media_details'=>['width'=>$width,'height'=>$height,'sizes'=>[]], 'link'=>$private?Files::url($id):$url];
    }
    public static function permission() {
        return self::enabled()&&Members::reader()&&(!Scope::limited()||Scope::ready())?true:new \WP_Error('asm_media_unavailable',__('This media action is unavailable.', 'atshift-members'),['status'=>403]);
    }
    public static function private_rest($id,$r) {
        if(!in_array($r->get_method(),['GET','HEAD'],true)||!self::can_select($id))return new \WP_Error('private',__('You cannot manage this media item.', 'atshift-members'),['status'=>403]);
        $item=self::item($id);$p=get_post($id);
        return new \WP_REST_Response(array_merge($item,['title'=>['raw'=>$item['title'],'rendered'=>esc_html($item['title'])],
            'caption'=>['raw'=>'','rendered'=>''],'description'=>['raw'=>'','rendered'=>''],'alt_text'=>$item['alt'],
            'media_type'=>$item['type']==='image'?'image':'file','type'=>'attachment','status'=>'inherit','author'=>(int)$p->post_author,'post'=>(int)$p->post_parent]),200,['Cache-Control'=>'private, no-store, max-age=0']);
    }
    public static function visibility($request) {return in_array($request['visibility'],['public','members'],true)?$request['visibility']:null;}
    public static function listing($r) {
        global $wpdb;$mode=self::visibility($r);if(!$mode)return new \WP_Error('scope',__('Choose a visibility setting.', 'atshift-members'),['status'=>400]);
        $page=max(1,absint($r['page']??1));$search=is_string($r['search']??null)?sanitize_text_field($r['search']):'';
        $private="EXISTS (SELECT 1 FROM {$wpdb->postmeta} pm WHERE pm.post_id=p.ID AND pm.meta_key='_asm_private_file')";
        $where="p.post_type='attachment' AND p.post_status NOT IN ('trash','auto-draft') AND ".($mode==='members'?$private:'NOT '.$private);
        if(!current_user_can('manage_options'))$where.=' AND '.self::attachments_sql('p');
        if($search!=='')$where.=$wpdb->prepare(' AND p.post_title LIKE %s','%'.$wpdb->esc_like($search).'%');
        // Filter by the same object authorization before pagination; no foreign titles or totals leave the server.
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Interpolated SQL consists only of WordPress-owned table names and internal prepared/allowlisted predicates; caller values are bound before composition. Security-sensitive current state or atomic transaction/lock operation; WordPress object caching cannot provide these fresh predicates or synchronization semantics.
        $ids=$wpdb->get_col("SELECT p.ID FROM {$wpdb->posts} p WHERE $where ORDER BY p.ID DESC");
        $ids=array_values(array_filter($ids,[self::class,'can_select']));$limit=24;
        return ['items'=>array_map([self::class,'item'],array_slice($ids,($page-1)*$limit,$limit)),'more'=>count($ids)>$page*$limit];
    }
    public static function upload($r) {
        $mode=self::visibility($r);$parent=absint($r['post_id']??0);
        if(!$mode||!self::target($parent))return new \WP_Error('forbidden',__('You cannot attach files to this post.', 'atshift-members'),['status'=>403]);
        $file=$r->get_file_params()['file']??[];
        if($mode==='members')$id=Files::save_upload($parent,$file);
        else {
            require_once ABSPATH.'wp-admin/includes/file.php';require_once ABSPATH.'wp-admin/includes/media.php';require_once ABSPATH.'wp-admin/includes/image.php';
            if(!is_array($file)||!is_string($file['tmp_name']??null)||!is_string($file['name']??null)||($file['error']??1)!==UPLOAD_ERR_OK||!is_uploaded_file($file['tmp_name'])||filesize($file['tmp_name'])>Files::max_upload_size())return new \WP_Error('upload',__('Choose a file within the server\'s upload size limit.', 'atshift-members'),['status'=>400]);
            $checked=Files::validate($file['tmp_name'],sanitize_file_name($file['name']));if(is_wp_error($checked))return $checked;
            // media_handle_upload reads PHP's verified multipart file, not a caller-provided path.
            $_FILES['asm_media_upload']=$file;
            try{$id=media_handle_upload('asm_media_upload',$parent,[],['test_form'=>false]);}finally{unset($_FILES['asm_media_upload']);}
        }
        if(is_wp_error($id))return $id;
        return self::item($id);
    }
    public static function select($r) {
        $id=absint($r['id']);$target=absint($r['post_id']??0);$mode=self::visibility($r);
        if(!$mode||!self::can_select($id)||!self::target($target))return new \WP_Error('forbidden',__('You cannot select this file.', 'atshift-members'),['status'=>403]);
        $meta=get_post_meta($id,Files::META,true);if((bool)$meta!==($mode==='members'))return new \WP_Error('scope',__('The visibility settings do not match.', 'atshift-members'),['status'=>400]);
        if(!$meta||!$target||(int)get_post($id)->post_parent===$target)return self::item($id);
        // Give each target post its own private bytes and authorization boundary.
        $store=atshift_members()->store;$lock='media-copy-'.$id.'-'.$target.'-'.get_current_user_id();
        if(!$store->lock($lock))return new \WP_Error('busy',__('An operation is in progress. Please try again.', 'atshift-members'),['status'=>409]);
        try{
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Security-sensitive current state or atomic transaction/lock operation; WordPress object caching cannot provide these fresh predicates or synchronization semantics.
            global $wpdb;$copy=$wpdb->get_var($wpdb->prepare("SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id=p.ID AND m.meta_key='_asm_media_source' WHERE p.post_type='attachment' AND p.post_parent=%d AND p.post_author=%d AND m.meta_value=%s AND p.post_status<>'trash' LIMIT 1",$target,get_current_user_id(),(string)$id));
            if($copy&&self::can_select($copy))return self::item($copy);
            $source=Files::provider($meta['store']);$stream=$source?call_user_func($source['open'],$meta['key']):null;
            if(!is_resource($stream))return new \WP_Error('storage',__('Cannot read the source file.', 'atshift-members'),['status'=>503]);
            require_once ABSPATH.'wp-admin/includes/file.php';
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Copy an authorized private stream through a WordPress temporary file; bounded streams are closed in finally.
            $temp=wp_tempnam('asm-copy');$out=$temp?fopen($temp,'wb'):false;
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Copy an authorized private stream through a WordPress temporary file; bounded streams are closed in finally.
            if(!$out){fclose($stream);return new \WP_Error('storage',__('Cannot create temporary storage.', 'atshift-members'),['status'=>503]);}
            try{
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Copy an authorized private stream through a WordPress temporary file; bounded streams are closed in finally.
                $size=stream_copy_to_stream($stream,$out);fclose($out);$out=null;
                if($size===false)return new \WP_Error('size',__('Cannot copy the file.', 'atshift-members'),['status'=>400]);
                $checked=Files::validate($temp,$meta['name'],false);if(is_wp_error($checked))return $checked;
                $new=Files::save_private($target,$temp,$meta['name'],$checked);if(is_wp_error($new))return $new;
                update_post_meta($new,'_asm_media_source',$id);return self::item($new);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Copy an authorized private stream through a WordPress temporary file; bounded streams are closed in finally.
            }finally{fclose($stream);if(is_resource($out))fclose($out);if($temp)wp_delete_file($temp);}
        }finally{$store->unlock($lock);}
    }
    public static function assets($post=0,$library=false) {
        wp_enqueue_media($post?['post'=>$post]:[]);
        $deps=['media-editor','wp-element','wp-components','wp-api-fetch','wp-hooks','wp-dom-ready','wp-i18n'];
        if(!$library&&function_exists('get_current_screen')&&get_current_screen()->is_block_editor())$deps=array_merge($deps,['wp-editor','wp-block-editor','wp-data','wp-core-data']);
        $base=dirname(__DIR__).'/atshift-members.php';
        wp_enqueue_script('asm-media',plugins_url('assets/media.js',$base),$deps,filemtime(dirname(__DIR__).'/assets/media.js'),true);
        wp_set_script_translations('asm-media', 'atshift-members', dirname(__DIR__) . '/languages');
        wp_enqueue_style('wp-components');wp_enqueue_style('asm-media',plugins_url('assets/media.css',$base),[],filemtime(dirname(__DIR__).'/assets/media.css'));
        wp_add_inline_script('asm-media','window.asmMediaConfig='.wp_json_encode(['postId'=>(int)$post,'library'=>$library,'startUpload'=>($GLOBALS['pagenow']??'')==='media-new.php','maxSize'=>Files::max_upload_size(),'maxSizeLabel'=>size_format(Files::max_upload_size()),'accept'=>'.'.implode(',.',array_keys(Files::available_formats())),'rest'=>esc_url_raw(rest_url('atshift-members/v1/media'))]).';','before');
    }
    public static function library_page() {
        if(!self::enabled()||!Members::reader())return;
        if(!current_user_can('upload_files')||!self::can_upload())wp_die(esc_html__('This media screen is unavailable.', 'atshift-members'),'',['response'=>403]);
        $GLOBALS['title']=__('Attach Files', 'atshift-members');self::assets(0,true);require ABSPATH.'wp-admin/admin-header.php';
        echo ('<div class="wrap">' . '<h1>' . esc_html__('Attach Files', 'atshift-members') . '</h1>' . '<div id="asm-media-library">' . '</div>' . '</div>');
        require ABSPATH.'wp-admin/admin-footer.php';exit;
    }
    public static function hooks() {
        add_action('rest_api_init',function(){
            foreach([''=>['GET','listing'],'/upload'=>['POST','upload'],'/select'=>['POST','select']] as $route=>$spec)register_rest_route('atshift-members/v1','/media'.$route,['methods'=>$spec[0],'callback'=>[self::class,$spec[1]],'permission_callback'=>[self::class,'permission']]);
            // Read-only editor metadata for selected private attachments; private files remain absent from the native library.
        });
        add_action('admin_enqueue_scripts',function(){
            $screen=get_current_screen();global $post;
            if($screen&&$screen->base==='post'&&$post&&self::enabled()&&self::target($post->ID))self::assets($post->ID);
        });
        add_action('load-upload.php',[self::class,'library_page']);add_action('load-media-new.php',[self::class,'library_page']);
        add_filter('user_has_cap',function($all,$caps,$args,$user){
            if(in_array('upload_files',$caps,true)&&self::enabled()&&Members::active($user->ID)&&(!Scope::limited($user->ID)||Scope::ready())){
                foreach(Posting::types() as $type)if(user_can($user,$type->cap->create_posts)){$all['upload_files']=true;break;}
            }
            return $all;
        },10,4);
        add_filter('posts_where',function($where,$q){
            if(!self::member()||(!is_admin()&&!$q->get('asm_media_manage')))return $where;
            global $wpdb;$type=$q->get('post_type');
            if($type==='attachment')return $where.' AND '.self::attachments_sql($wpdb->posts);
            if($q->is_main_query()||$q->get('asm_media_manage'))return $where.' AND '.self::posts_sql($wpdb->posts);
            return $where;
        },PHP_INT_MAX,2);
        add_action('init',function(){foreach(array_keys(Posting::types()) as $type)add_filter('rest_'.$type.'_query',function($args,$r){if($r['context']==='edit')$args['asm_media_manage']=true;return $args;},10,2);},PHP_INT_MAX);
        add_filter('rest_attachment_query',function($args){$args['asm_media_manage']=true;return $args;});
        add_filter('wp_prepare_attachment_for_js',function($response,$attachment){return self::member()&&!self::can_select($attachment->ID)?null:$response;},PHP_INT_MAX,2);
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin display/filter parameters; mutation handlers separately require nonce and object capability checks.
        add_action('wp_ajax_get-attachment',function(){if(self::member()&&!self::can_select(absint($_REQUEST['id']??0)))wp_send_json_error(['message'=>__('You cannot manage this media item.', 'atshift-members')],403);},0);
        add_filter('rest_pre_dispatch',function($result,$server,$r){
            if(!self::member()||!preg_match('#^/wp/v2/media/(\d+)$#',$r->get_route(),$m))return $result;
            return self::can_select((int)$m[1])?$result:new \WP_Error('forbidden',__('You cannot manage this media item.', 'atshift-members'),['status'=>403]);
        },PHP_INT_MAX,3);
        add_filter('map_meta_cap',function($caps,$cap,$actor,$args){
            if(!self::member($actor)||!in_array($cap,['edit_post','delete_post'],true)||empty($args[0]))return $caps;
            $p=get_post((int)$args[0]);if(!$p||$p->post_type!=='attachment')return $caps;
            if((int)$p->post_author!==$actor){$parent=get_post($p->post_parent);$type=$parent?get_post_type_object($parent->post_type):null;if(!$type||!user_can($actor,$type->cap->edit_others_posts)||!user_can($actor,'edit_post',$p->post_parent))return ['do_not_allow'];}
            return $caps;
        },PHP_INT_MAX,4);
    }
}
