<?php
namespace Atshift\Membership;
defined('ABSPATH') || exit;

/** Private attachments. No public upload URL or derived thumbnail is created. */
final class Files {
    const META='_atshme_private_file';
    public static function storage_config() {
        $saved=(array)get_option('atshme_file_storage',[]);
        return ['private'=>defined('ATSHME_PRIVATE_DIR')?ATSHME_PRIVATE_DIR:($saved['private']??''),'public'=>defined('ATSHME_PUBLIC_ROOT')?ATSHME_PUBLIC_ROOT:($saved['public']??''),'kind'=>$saved['kind']??'local','marker'=>$saved['marker']??''];
    }
    public static function validate_directory($private,$public) {
        if(!is_string($private)||!is_string($public)||$private===''||$public===''||str_contains($private,"://")||str_contains($public,"://")||str_contains($private,"\0")||str_contains($public,"\0"))return new \WP_Error('storage',__('Specify an absolute path on the server.', 'atshift-members'));
        if(!preg_match('#^(?:/|[A-Za-z]:[\\\\/])#',$private)||!preg_match('#^(?:/|[A-Za-z]:[\\\\/])#',$public))return new \WP_Error('storage',__('Specify an absolute path on the server.', 'atshift-members'));
        $root=realpath($private);$public=realpath($public);$wp=realpath(ABSPATH);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- Validated private/local streams require exclusive create, byte ranges, exact permissions and deletion failure detection; WP_Filesystem does not provide this stream contract.
        if(!$root || !$public || !$wp || !is_dir($root) || !is_readable($root) || !is_writable($root) || !($wp===$public || str_starts_with($wp,$public.DIRECTORY_SEPARATOR)) || $root===$public || str_starts_with($root,$public.DIRECTORY_SEPARATOR) || str_starts_with($public,$root.DIRECTORY_SEPARATOR))return new \WP_Error('storage',__('Specify the public directory containing WordPress and a separate readable and writable directory outside it.', 'atshift-members'));
        return $root;
    }
    public static function root() {
        $config=self::storage_config();$root=self::validate_directory($config['private'],$config['public']);
        if(is_wp_error($root))return $root;
        if(!defined('ATSHME_PRIVATE_DIR')&&$config['kind']==='network'){
            $marker=$config['marker'];
            if(!is_string($marker)||!preg_match('/^[a-f0-9]{64}$/D',$marker)||!is_file($root.'/.atshme-storage-'.$marker)||is_link($root.'/.atshme-storage-'.$marker))return new \WP_Error('storage',__('Check the shared directory connection. The storage location could not be verified.', 'atshift-members'));
        }
        return $root;
    }
    public static function save_storage($input) {
        if(!current_user_can('manage_options'))return new \WP_Error('forbidden',__('Only site administrators can change the storage location.', 'atshift-members'));
        if(defined('ATSHME_PRIVATE_DIR')||defined('ATSHME_PUBLIC_ROOT')||defined('ATSHME_FILE_STORE'))return new \WP_Error('configured',__('Storage is configured in wp-config.php.', 'atshift-members'));
        if(!is_array($input)||!in_array($input['kind']??'', ['local','network'],true))return new \WP_Error('storage',__('Choose a storage method.', 'atshift-members'));
        $root=self::validate_directory($input['private']??null,$input['public']??null);if(is_wp_error($root))return $root;
        $old=self::storage_config();
        if(realpath($old['private'])!==$root){
            global $wpdb;
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Security-sensitive current state or atomic transaction/lock operation; WordPress object caching cannot provide these fresh predicates or synchronization semantics.
            if($wpdb->get_var($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key=%s LIMIT 1",self::META)))return new \WP_Error('migration',__('Members-only files already exist. Changing storage locations requires migrating them first.', 'atshift-members'));
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Validated private/local streams require exclusive create, byte ranges, exact permissions and deletion failure detection; WP_Filesystem does not provide this stream contract.
        $token=Store::token();$probe=$root.'/.atshme-check-'.$token;$handle=@fopen($probe,'x+b');
        if(!$handle)return new \WP_Error('storage',__('Cannot create a file in the storage directory.', 'atshift-members'));
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite,WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Validated private/local streams require exclusive create, byte ranges, exact permissions and deletion failure detection; WP_Filesystem does not provide this stream contract.
        $ok=fwrite($handle,$token)===strlen($token);rewind($handle);$ok=$ok&&stream_get_contents($handle)===$token;fclose($handle);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Validated private/local streams require exclusive create, byte ranges, exact permissions and deletion failure detection; WP_Filesystem does not provide this stream contract.
        $deleted=@unlink($probe);
        if(!$ok||!$deleted)return new \WP_Error('storage',__('Could not verify writing, reading, and deleting in the storage directory.', 'atshift-members'));
        $marker='';
        if($input['kind']==='network'){
            $marker=realpath($old['private'])===$root&&preg_match('/^[a-f0-9]{64}$/D',$old['marker'])?$old['marker']:$token;
            $path=$root.'/.atshme-storage-'.$marker;
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen,WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Validated private/local streams require exclusive create, byte ranges, exact permissions and deletion failure detection; WP_Filesystem does not provide this stream contract.
            if(!is_file($path)){ $h=@fopen($path,'x');if(!$h)return new \WP_Error('storage',__('Cannot create the shared directory marker file.', 'atshift-members'));fclose($h); }
            if(is_link($path))return new \WP_Error('storage',__('The shared directory marker file is invalid.', 'atshift-members'));
        }
        $config=['kind'=>$input['kind'],'private'=>$root,'public'=>realpath($input['public']),'marker'=>$marker];
        if(!update_option('atshme_file_storage',$config,false)&&get_option('atshme_file_storage')!==$config)return new \WP_Error('storage',__('Could not save the storage settings.', 'atshift-members'));
        return true;
    }
    public static function providers() {
        return apply_filters('atshift_members_file_stores',['local'=>[
            'put'=>[self::class,'put'],'open'=>[self::class,'open'],'delete'=>[self::class,'remove'],
        ]]);
    }
    public static function provider($key) {
        $p=self::providers()[$key]??null;
        foreach(['put','open','delete'] as $method)if(!is_array($p)||!is_callable($p[$method]??null))return null;
        return $p;
    }
    public static function upload_available() {
        $store=defined('ATSHME_FILE_STORE')?ATSHME_FILE_STORE:'local';
        return self::provider($store)&&($store!=='local'||!is_wp_error(self::root()))&&class_exists('finfo');
    }
    public static function put($tmp) {
        $root=self::root();if(is_wp_error($root))return $root;
        $key=Store::token().'.bin';$path=$root.'/'.$key;
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Validated private/local streams require exclusive create, byte ranges, exact permissions and deletion failure detection; WP_Filesystem does not provide this stream contract.
        $out=fopen($path,'x+b');$in=fopen($tmp,'rb');
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Validated private/local streams require exclusive create, byte ranges, exact permissions and deletion failure detection; WP_Filesystem does not provide this stream contract.
        if(!$out||!$in){if(is_resource($out))fclose($out);if(is_resource($in))fclose($in);return new \WP_Error('storage',__('Cannot save.', 'atshift-members'));}
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod,WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Validated private/local streams require exclusive create, byte ranges, exact permissions and deletion failure detection; WP_Filesystem does not provide this stream contract.
        chmod($path,0600);$bytes=stream_copy_to_stream($in,$out);fclose($in);fclose($out);
        // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Validated private/local streams require exclusive create, byte ranges, exact permissions and deletion failure detection; WP_Filesystem does not provide this stream contract.
        if($bytes!==filesize($tmp)){@unlink($path);return new \WP_Error('storage',__('Saving failed.', 'atshift-members'));}
        return $key;
    }
    public static function path($key) {
        $root=self::root();if(is_wp_error($root))return $root;
        if(!is_string($key)||!preg_match('/^[a-f0-9]{64}\.bin$/D',$key))return new \WP_Error('storage',__('Invalid file identifier.', 'atshift-members'));
        $path=$root.'/'.$key;
        if(is_link($path))return new \WP_Error('storage',__('This link is unavailable.', 'atshift-members'));
        return $path;
    }
    // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Validated private/local streams require exclusive create, byte ranges, exact permissions and deletion failure detection; WP_Filesystem does not provide this stream contract.
    public static function open($key) {$path=self::path($key);return is_wp_error($path)?$path:(is_file($path)?fopen($path,'rb'):new \WP_Error('missing',__('File not found.', 'atshift-members')));}
    // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- Validated private/local streams require exclusive create, byte ranges, exact permissions and deletion failure detection; WP_Filesystem does not provide this stream contract.
    public static function remove($key) {$path=self::path($key);return !is_wp_error($path) && (!file_exists($path)||unlink($path));}
    /** Accepted extensions and their canonical download metadata. */
    public static function formats() {
        return ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif','webp'=>'image/webp',
            'mp4'=>'video/mp4','webm'=>'video/webm','pdf'=>'application/pdf','txt'=>'text/plain','zip'=>'application/zip',
            'doc'=>'application/msword','xls'=>'application/vnd.ms-excel','ppt'=>'application/vnd.ms-powerpoint',
            'docx'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'xlsx'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'pptx'=>'application/vnd.openxmlformats-officedocument.presentationml.presentation'];
    }
    public static function max_upload_size() {return wp_max_upload_size();}
    public static function video_types() {return ['video/mp4','video/webm'];}
    public static function image_types() {return ['image/jpeg','image/png','image/gif','image/webp'];}
    public static function available_formats() {
        $formats=self::formats();
        if(!class_exists('ZipArchive'))unset($formats['zip'],$formats['docx'],$formats['xlsx'],$formats['pptx']);
        if(!class_exists('DOMDocument'))unset($formats['docx'],$formats['xlsx'],$formats['pptx']);
        return $formats;
    }
    public static function validate($path,$name,$check_size=true) {
        $ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));$type=self::formats()[$ext]??null;
        if(!$type||!is_file($path)||($check_size&&filesize($path)>self::max_upload_size()))return new \WP_Error('type',__('Check the supported formats and the server\'s upload size limit.', 'atshift-members'));
        if(!class_exists('finfo'))return new \WP_Error('configuration',__('Enable the fileinfo extension on the server.', 'atshift-members'));
        $actual=(new \finfo(FILEINFO_MIME_TYPE))->file($path);$valid=false;
        if(in_array($type,self::image_types(),true)){
            $info=@getimagesize($path);$valid=$actual===$type&&($info['mime']??'')===$type;
        }elseif(in_array($ext,['zip','docx','xlsx','pptx'],true)){
            if(!class_exists('ZipArchive'))return new \WP_Error('configuration',__('The server\'s ZIP extension is required for this file format.', 'atshift-members'));
            if($ext!=='zip'&&!class_exists('DOMDocument'))return new \WP_Error('configuration',__('The server\'s DOM extension is required for this file format.', 'atshift-members'));
            $zip=new \ZipArchive();
            if($zip->open($path,\ZipArchive::CHECKCONS)===true){
                try{
                    $valid=in_array($actual,[$type,'application/zip','application/x-zip','application/x-zip-compressed','application/octet-stream'],true);
                    if($ext!=='zip'){
                        $parts=['docx'=>['word/document.xml','application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml'],
                            'xlsx'=>['xl/workbook.xml','application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml'],
                            'pptx'=>['ppt/presentation.xml','application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml']];
                        [$part,$contentType]=$parts[$ext];$stat=$zip->statName('[Content_Types].xml');
                        $xml=$stat&&$stat['size']<=1024*1024?$zip->getFromName('[Content_Types].xml',1024*1024+1):false;
                        $valid=$valid&&$zip->locateName($part)!==false&&is_string($xml)&&!str_contains(strtoupper($xml),'<!DOCTYPE')&&!str_contains(strtoupper($xml),'<!ENTITY');
                        if($valid){
                            $doc=new \DOMDocument();$valid=@$doc->loadXML($xml,LIBXML_NONET);$found=false;
                            if($valid)foreach($doc->getElementsByTagName('Override') as $node){
                                if($node->getAttribute('PartName')==='/'.$part&&$node->getAttribute('ContentType')===$contentType)$found=true;
                                if(stripos($node->getAttribute('ContentType'),'macroEnabled')!==false||stripos($node->getAttribute('ContentType'),'vbaProject')!==false)$valid=false;
                            }
                            $valid=$valid&&$found;
                        }
                    }
                }finally{$zip->close();}
            }
        }elseif(in_array($ext,['doc','xls','ppt'],true)){
            // Legacy Office uses a compound binary container; do not accept a renamed ZIP or text file.
            $stream=['doc'=>'WordDocument','xls'=>'Workbook','ppt'=>'PowerPoint Document'][$ext];
            $marker=implode("\0",str_split($stream))."\0\0\0";
            $valid=in_array($actual,[$type,'application/x-ole-storage','application/CDFV2','application/vnd.ms-office'],true)&&self::compound_marker($path,$ext==='xls'?[$marker,"B\0o\0o\0k\0\0\0"]:[$marker]);
        }else $valid=$actual===$type||($ext==='txt'&&filesize($path)===0);
        return $valid?$type:new \WP_Error('type',__('Check the file extension and contents. This file cannot be attached in the selected format.', 'atshift-members'));
    }
    private static function compound_marker($path,$markers) {
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Validated private/local streams require exclusive create, byte ranges, exact permissions and deletion failure detection; WP_Filesystem does not provide this stream contract.
        $stream=fopen($path,'rb');if(!$stream)return false;
        try{
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Validated private/local streams require exclusive create, byte ranges, exact permissions and deletion failure detection; WP_Filesystem does not provide this stream contract.
            if(fread($stream,8)!=="\xd0\xcf\x11\xe0\xa1\xb1\x1a\xe1")return false;
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Validated private/local streams require exclusive create, byte ranges, exact permissions and deletion failure detection; WP_Filesystem does not provide this stream contract.
            $tail='';while(!feof($stream)){$chunk=fread($stream,65536);if($chunk===false)break;$chunk=$tail.$chunk;foreach($markers as $marker)if(str_contains($chunk,$marker))return true;$tail=substr($chunk,-64);}
            return false;
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Validated private/local streams require exclusive create, byte ranges, exact permissions and deletion failure detection; WP_Filesystem does not provide this stream contract.
        }finally{fclose($stream);}
    }
    public static function url($id,$inline=false) {
        $args=['action'=>'atshme_file','file'=>(int)$id];if($inline)$args['inline']='1';
        return add_query_arg($args,admin_url('admin-post.php'));
    }
    public static function image($attrs) {
        $id=absint($attrs['id']??0);if(!self::readable($id))return '';
        $meta=get_post_meta($id,self::META,true);if(!in_array($meta['type']??'',self::image_types(),true))return '';
        $alt=is_string($attrs['alt']??null)?$attrs['alt']:get_the_title($id);
        return '<img src="'.esc_url(self::url($id,true)).'" alt="'.esc_attr($alt).'" loading="lazy" style="max-width:100%;height:auto">';
    }
    public static function upload($parent,$file) {
        $post=get_post($parent);
        if(!$post || !Posting::supported(get_post_type_object($post->post_type)) || !Members::reader() || !current_user_can('edit_post',$parent) || Content::denied($parent))return new \WP_Error('forbidden',__('Cannot attach this file.', 'atshift-members'));
        return self::save_upload($parent,$file);
    }
    public static function save_upload($parent,$file) {
        if(!is_array($file)||($file['error']??1)!==UPLOAD_ERR_OK || !is_string($file['tmp_name']??null) || !is_string($file['name']??null) || !is_uploaded_file($file['tmp_name']) || filesize($file['tmp_name'])>self::max_upload_size())return new \WP_Error('upload',__('Choose a file within the server\'s upload size limit.', 'atshift-members'));
        $name=sanitize_file_name($file['name']);$actual=self::validate($file['tmp_name'],$name);
        if(is_wp_error($actual))return $actual;
        return self::save_private($parent,$file['tmp_name'],$name,$actual);
    }
    public static function save_private($parent,$path,$name,$actual) {
        $store=defined('ATSHME_FILE_STORE')?ATSHME_FILE_STORE:'local';$provider=self::provider($store);
        if(!$provider)return new \WP_Error('storage',__('The storage location is unavailable.', 'atshift-members'));
        $key=call_user_func($provider['put'],$path);if(is_wp_error($key))return $key;
        $dimensions=in_array($actual,self::image_types(),true)?@getimagesize($path):false;
        $id=wp_insert_post(['post_type'=>'attachment','post_status'=>'inherit','post_parent'=>$parent,'post_author'=>get_current_user_id(),'post_title'=>$name,'post_mime_type'=>$actual,'meta_input'=>[self::META=>['store'=>$store,'key'=>$key,'name'=>$name,'type'=>$actual,'width'=>$dimensions[0]??0,'height'=>$dimensions[1]??0]]],true);
        if(is_wp_error($id)||!get_post_meta($id,self::META,true)){call_user_func($provider['delete'],$key);return new \WP_Error('storage',__('Could not save the attachment information.', 'atshift-members'));}
        return $id;
    }
    public static function readable($id) {
        $p=get_post($id);if(!$p || $p->post_type!=='attachment' || !get_post_meta($id,self::META,true) || !Members::reader() || (Members::blocked($p->post_author) && get_user_meta($p->post_author,'_atshme_custodian',true)!=='1'))return false;
        $parent=get_post($p->post_parent);
        if(!$p->post_parent)return (int)$p->post_author===get_current_user_id()||current_user_can('manage_options');
        $readable=$parent && !Content::denied($parent->ID) && (($parent->post_status==='publish'&&!post_password_required($parent)) || current_user_can('edit_post',$parent->ID));
        return (bool)apply_filters('atshift_members_can_read_attachment',$readable,$id,get_current_user_id());
    }
    public static function hooks() {
        add_action('admin_post_atshme_file_delete',function(){check_admin_referer('atshme_file_delete');$result=self::erase(absint(wp_unslash($_POST['file_id']??0)));if(is_wp_error($result))wp_die(esc_html($result->get_error_message()),'',['response'=>400]);wp_safe_redirect(wp_get_referer()?:home_url('/'));exit;});
        add_action('admin_post_atshme_file',[self::class,'download']);
        add_action('admin_post_nopriv_atshme_file',[self::class,'download']);
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Multipart upload is validated by save_upload; range header is parsed by byte_range; method and inline flags use exact literal comparisons, not output or SQL.
        add_action('admin_post_atshme_file_upload',function(){check_admin_referer('atshme_file_upload');$id=self::upload(absint(wp_unslash($_POST['post_id']??0)),$_FILES['attachment']??[]);if(is_wp_error($id))wp_die(esc_html($id->get_error_message()),'',['response'=>400]);wp_safe_redirect(wp_get_referer()?:home_url('/'));exit;});
        add_filter('wp_get_attachment_url',function($url,$id){return get_post_meta($id,self::META,true)?self::url($id):$url;},10,2);
        add_filter('image_downsize',function($result,$id){$meta=get_post_meta($id,self::META,true);if(!$meta)return $result;return self::readable($id)&&in_array($meta['type']??'',self::image_types(),true)?[self::url($id,true),(int)($meta['width']??0),(int)($meta['height']??0),false]:false;},10,2);
        add_filter('wp_get_attachment_image_src',function($image,$id){return get_post_meta($id,self::META,true)&&!self::readable($id)?false:$image;},10,2);
        add_filter('pre_delete_attachment',function($delete,$post){if($delete!==null)return $delete;$meta=get_post_meta($post->ID,self::META,true);if(!$meta)return $delete;$p=self::provider($meta['store']);return $p && call_user_func($p['delete'],$meta['key'])?$delete:false;},PHP_INT_MAX,2);
        // WordPress passes the shortcode tag as argument 3, not our editor flag.
        add_shortcode('atshme_attachments',fn($attrs,$content=null)=>self::screen($attrs,$content));
        add_shortcode('atshme_image',[self::class,'image']);
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- screen() constructs escaped HTML and integer IDs after per-object access checks.
        add_action('add_meta_boxes',function(){foreach(array_keys(Posting::types()) as $type)add_meta_box('atshme-files',__('Members-Only Attachments', 'atshift-members'),function($post){echo self::screen(['post_id'=>$post->ID],null,true);},$type,'side');});
        add_filter('rest_pre_dispatch',function($result,$server,$request){if(preg_match('#^/wp/v2/media/(\d+)#',$request->get_route(),$m) && get_post_meta($m[1],self::META,true))return Media::private_rest((int)$m[1],$request);return $result;},PHP_INT_MAX,3);
        add_filter('comments_clauses',function($c){global $wpdb;$c['where'].=" AND {$wpdb->comments}.comment_post_ID NOT IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_atshme_private_file')";return $c;},PHP_INT_MAX);
        add_filter('posts_where',function($where){global $wpdb;return $where." AND {$wpdb->posts}.ID NOT IN (SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_atshme_private_file')";},PHP_INT_MAX);
    }
    public static function download() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin display/filter parameters; mutation handlers separately require nonce and object capability checks.
        $id=absint(wp_unslash($_GET['file']??0));Screens::private_headers();
        if(!self::readable($id))wp_die(esc_html__('You cannot view this content.', 'atshift-members'),'',['response'=>404]);
        $meta=get_post_meta($id,self::META,true);$p=self::provider($meta['store']);$stream=$p?call_user_func($p['open'],$meta['key']):null;
        if(!is_resource($stream))wp_die(esc_html__('Cannot access the storage location.', 'atshift-members'),'',['response'=>503]);
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only admin display/filter parameters; mutation handlers separately require nonce and object capability checks. Multipart upload is validated by save_upload; range header is parsed by byte_range; method and inline flags use exact literal comparisons, not output or SQL.
        $inline=is_string($_GET['inline']??null)?sanitize_text_field(wp_unslash($_GET['inline'])):'';
        if($inline==='1'){
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Validated private/local streams require exclusive create, byte ranges, exact permissions and deletion failure detection; WP_Filesystem does not provide this stream contract.
            $probe=fread($stream,65536);$type=(new \finfo(FILEINFO_MIME_TYPE))->buffer($probe);
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Validated private/local streams require exclusive create, byte ranges, exact permissions and deletion failure detection; WP_Filesystem does not provide this stream contract.
            if(!in_array($type,array_merge(self::image_types(),self::video_types()),true)||$type!==($meta['type']??'')){fclose($stream);wp_die(esc_html__('You cannot view this content.', 'atshift-members'),'',['response'=>404]);}
            header('Content-Type: '.$type);header('X-Content-Type-Options: nosniff');header('Content-Disposition: inline');
            $stat=fstat($stream);$size=(int)($stat['size']??0);$seekable=stream_get_meta_data($stream)['seekable'];
            if($seekable&&$size>0){
                $range_header=isset($_SERVER['HTTP_RANGE'])&&is_string($_SERVER['HTTP_RANGE'])?sanitize_text_field(wp_unslash($_SERVER['HTTP_RANGE'])):'';
                header('Accept-Ranges: bytes');$range=self::byte_range($range_header,$size);
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Validated private/local streams require exclusive create, byte ranges, exact permissions and deletion failure detection; WP_Filesystem does not provide this stream contract.
                if($range===false){fclose($stream);status_header(416);header('Content-Range: bytes */'.$size);exit;}
                [$start,$end]=$range;$length=$end-$start+1;
                if($range_header!==''){status_header(206);header('Content-Range: bytes '.$start.'-'.$end.'/'.$size);}
                header('Content-Length: '.$length);
                // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Multipart upload is validated by save_upload; range header is parsed by byte_range; method and inline flags use exact literal comparisons, not output or SQL.
                $method=is_string($_SERVER['REQUEST_METHOD']??null)?strtoupper(sanitize_key(wp_unslash($_SERVER['REQUEST_METHOD']))):'GET';
                if($method!=='HEAD'){
                    fseek($stream,$start);
                    // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped,WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Authorized binary image/video stream with verified MIME and nosniff; HTML escaping would corrupt bytes. Validated private/local streams require exclusive create, byte ranges, exact permissions and deletion failure detection; WP_Filesystem does not provide this stream contract.
                    while($length>0&&!feof($stream)){$chunk=fread($stream,min(65536,$length));if($chunk===false||$chunk==='')break;echo $chunk;$length-=strlen($chunk);}
                }
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Multipart upload is validated by save_upload; range header is parsed by byte_range; method and inline flags use exact literal comparisons, not output or SQL.
            }elseif((is_string($_SERVER['REQUEST_METHOD']??null)?strtoupper(sanitize_key(wp_unslash($_SERVER['REQUEST_METHOD']))):'GET')!=='HEAD'){
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Authorized binary stream probe with verified MIME and nosniff; HTML escaping would corrupt bytes.
                if($seekable)rewind($stream);else echo $probe;
                fpassthru($stream);
            }
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Validated private/local streams require exclusive create, byte ranges, exact permissions and deletion failure detection; WP_Filesystem does not provide this stream contract.
            fclose($stream);exit;
        }
        header('Content-Type: application/octet-stream');header('X-Content-Type-Options: nosniff');
        header("Content-Disposition: attachment; filename=attachment; filename*=UTF-8''".rawurlencode($meta['name']));
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Validated private/local streams require exclusive create, byte ranges, exact permissions and deletion failure detection; WP_Filesystem does not provide this stream contract.
        fpassthru($stream);fclose($stream);exit;
    }
    /** A single byte range supports native video seeking without loading the file into memory. */
    public static function byte_range($header,$size) {
        if($header==='')return [0,$size-1];
        if(!is_string($header)||!preg_match('/^bytes=([0-9]*)-([0-9]*)$/D',$header,$m)||($m[1]===''&&$m[2]===''))return false;
        if($m[1]===''){$suffix=(int)$m[2];return $suffix>0?[max(0,$size-$suffix),$size-1]:false;}
        $start=(int)$m[1];$end=$m[2]===''?$size-1:min((int)$m[2],$size-1);
        return $start<$size&&$end>=$start?[$start,$end]:false;
    }
    public static function erase($id) {
        $p=get_post($id);
        if(!$p || !self::readable($id) || !current_user_can('edit_post',$p->post_parent) || ((int)$p->post_author!==get_current_user_id()&&!current_user_can('atshme_manage_members')))return new \WP_Error('forbidden',__('This attachment cannot be deleted.', 'atshift-members'));
        return wp_delete_attachment($id,true)?true:new \WP_Error('storage',__('Could not delete the file from storage.', 'atshift-members'));
    }
    public static function screen($attrs,$content=null,$editor=false) {
        $parent=absint($attrs['post_id']??get_the_ID());$post=get_post($parent);
        if(!$post || !Members::reader() || Content::denied($parent) || ($post->post_status!=='publish' && !current_user_can('edit_post',$parent)))return '';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Security-sensitive current state or atomic transaction/lock operation; WordPress object caching cannot provide these fresh predicates or synchronization semantics.
        global $wpdb;$ids=$wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_parent=%d AND post_type='attachment'",$parent));
        $html='<ul>';foreach($ids as $id)if(self::readable($id)){ $html.='<li><a href="'.esc_url(wp_get_attachment_url($id)).'">'.esc_html(get_the_title($id)).'</a>'; if(!$editor && current_user_can('edit_post',$parent) && ((int)get_post($id)->post_author===get_current_user_id()||current_user_can('atshme_manage_members')))$html.='<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('atshme_file_delete','_wpnonce',true,false).'<input type="hidden" name="action" value="atshme_file_delete"><input type="hidden" name="file_id" value="'.(int)$id.('">' . '<label>' . '<input type="checkbox" required>' . esc_html__('Permanently delete this attachment', 'atshift-members') . '</label>' . '<button>' . esc_html__('Delete Attachment', 'atshift-members') . '</button>' . '</form>'); if(!$editor&&current_user_can('edit_post',$parent)&&in_array(get_post_meta($id,self::META,true)['type']??'',self::image_types(),true))$html.=('<p>' . esc_html__('To display an image in your post, paste this into a Shortcode block: ', 'atshift-members') . '<code>' . '[atshme_image id="').(int)$id.'"]</code></p>'; $html.='</li>';}$html.='</ul>';
        // Forms cannot be nested in the native editor; link to a separate authenticated upload screen.
        if($editor)return $html.(self::upload_available()?('<p>' . esc_html__('Choose Members Only when using Add Media or a block\'s Upload control.', 'atshift-members') . '</p>'):'');
        if(self::upload_available()&&current_user_can('edit_post',$parent))$html.='<form method="post" enctype="multipart/form-data" action="'.esc_url(admin_url('admin-post.php')).'">'.wp_nonce_field('atshme_file_upload','_wpnonce',true,false).'<input type="hidden" name="action" value="atshme_file_upload"><input type="hidden" name="post_id" value="'.$parent.('">' . '<label>' . esc_html__('Members-Only Attachment ', 'atshift-members') . '<input type="file" name="attachment" required accept="').esc_attr('.'.implode(',.',array_keys(self::available_formats()))).('">' . '</label>' . '<button>' . esc_html__('Attach File', 'atshift-members') . '</button>' . '</form>');
        return $html;
    }
}
