<?php
namespace Atshift\Membership;
defined('ABSPATH') || exit;

/** Explicit consent, resumable erasure, and selective site-owned contribution handoff. */
final class Withdrawal {
    const PLAN = '_asm_withdrawal_plan';
    const JOB = '_asm_withdrawal_job';
    const TYPES = ['post','page','asm_post','asm_page','asm_notice'];
    public static function hooks() {
        add_action('asm_finish_withdrawal',[self::class,'run']);
        add_action('asm_cleanup',[self::class,'cleanup_plans']);
    }
    public static function cleanup_plans() {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Security-sensitive current state or atomic transaction/lock operation; WordPress object caching cannot provide these fresh predicates or synchronization semantics.
        foreach($wpdb->get_results($wpdb->prepare("SELECT umeta_id,meta_value FROM {$wpdb->usermeta} WHERE meta_key=%s",self::PLAN)) as $row) {
            $plan=maybe_unserialize($row->meta_value);
            if(!is_array($plan) || (int)($plan['expires']??0)<time())delete_metadata_by_mid('user',(int)$row->umeta_id);
        }
    }
    private static function eligible($id) {
        return $id === get_current_user_id() && Members::active($id)
            && !current_user_can('manage_options') && Members::recent_login($id);
    }
    public static function candidates($id) {
        global $wpdb;
        // No query filter: the authenticated caller may inspect their own drafts and published posts.
        $types=self::transfer_types();$placeholders=implode(',',array_fill(0,count($types),'%s'));
        // phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One generated %s placeholder per transfer type, supplied by variadic types after the author ID; scanner cannot count generated placeholders. Interpolated SQL consists only of WordPress-owned table names and internal prepared/allowlisted predicates; caller values are bound before composition. Security-sensitive current state or atomic transaction/lock operation; WordPress object caching cannot provide these fresh predicates or synchronization semantics.
        return $wpdb->get_results($wpdb->prepare("SELECT ID,post_title,post_type,post_status FROM {$wpdb->posts} WHERE post_author=%d AND post_type IN ($placeholders) AND post_status NOT IN ('trash','auto-draft') ORDER BY ID DESC",$id,...$types));
    }
    public static function transfer_types() {return array_values(array_unique(array_merge(self::TYPES,array_keys(Posting::types()))));}
    private static function fingerprint($post) {
        $meta=get_post_meta($post->ID);unset($meta['_edit_lock'],$meta['_edit_last']);
        return Store::digest(wp_json_encode([$post->ID,$post->post_author,$post->post_type,$post->post_status,$post->post_title,$post->post_content,$post->post_excerpt,$meta]));
    }
    /** Select only owned attachments referenced by a retained post, never another user's media. */
    private static function media($post,$id) {
        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Security-sensitive current state or atomic transaction/lock operation; WordPress object caching cannot provide these fresh predicates or synchronization semantics.
        $ids=$wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_parent=%d AND post_author=%d AND post_type='attachment'",$post->ID,$id));
        $ids[]=get_post_thumbnail_id($post->ID);
        preg_match_all('/(?:wp-image-|attachment_)([0-9]+)/',$post->post_content,$matches);
        $ids=array_merge($ids,$matches[1]??[]);
        preg_match_all('/\[gallery[^\]]*ids=["\']([0-9,\s]+)["\']/',$post->post_content,$galleries);
        foreach($galleries[1]??[] as $gallery) $ids=array_merge($ids,explode(',',$gallery));
        $scan=function($blocks)use(&$scan,&$ids){foreach($blocks as $block){
            if(in_array($block['blockName']??'', ['core/image','core/audio','core/video','core/file','core/cover'],true)) $ids[]=$block['attrs']['id']??0;
            if(($block['blockName']??'')==='core/gallery')$ids=array_merge($ids,(array)($block['attrs']['ids']??[]));
            $scan($block['innerBlocks']??[]);
        }};
        $scan(parse_blocks($post->post_content));
        $owned=[];
        foreach(array_unique(array_map('absint',$ids)) as $media_id){$media=get_post($media_id);if($media && $media->post_type==='attachment' && (int)$media->post_author===$id)$owned[$media_id]=self::fingerprint($media);}
        return $owned;
    }
    public static function review($id,$mode,$selected,$consent) {
        if(!self::eligible($id))return new \WP_Error('reauth',__('Log in again and complete this within 10 minutes.', 'atshift-members'));
        if(!in_array($mode,['delete','transfer'],true) || !is_array($selected))return new \WP_Error('selection',__('Choose how to close your account.', 'atshift-members'));
        if($mode==='delete' && $selected)return new \WP_Error('selection',__('Deselect posts for transfer if you want to delete everything.', 'atshift-members'));
        if($mode==='transfer' && (!$selected || !$consent))return new \WP_Error('consent',__('Select the posts to transfer and confirm that you have reviewed their content and attachments.', 'atshift-members'));
        $posts=[];$media=[];
        foreach($selected as $value) {
            if(!is_scalar($value) || !ctype_digit((string)$value))return new \WP_Error('selection',__('Invalid post selection.', 'atshift-members'));
            $post=get_post((int)$value);
            if(!$post || (int)$post->post_author!==$id || !in_array($post->post_type,self::transfer_types(),true) || in_array($post->post_status,['trash','auto-draft'],true))return new \WP_Error('selection',__('Select only your own posts that are eligible for transfer.', 'atshift-members'));
            $posts[$post->ID]=self::fingerprint($post);
            $media+=self::media($post,$id);
        }
        $token=Store::token();
        $plan=['hash'=>Store::digest($token),'expires'=>time()+600,'posts'=>$posts,'media'=>$media,'mode'=>$mode,'session'=>Store::digest(wp_get_session_token())];
        if(!update_user_meta($id,self::PLAN,$plan))return new \WP_Error('save',__('Could not save the confirmation details.', 'atshift-members'));
        return ['token'=>$token,'plan'=>$plan];
    }
    public static function commit($id,$token,$confirmed) {
        if(!self::eligible($id) || !$confirmed)return new \WP_Error('reauth',__('Log in again and review the final confirmation.', 'atshift-members'));
        $store=atshift_members()->store;
        if(!$store->lock('withdraw|'.$id))return new \WP_Error('busy',__('Account closure is in progress. Please wait a moment.', 'atshift-members'));
        try {
            $plan=get_user_meta($id,self::PLAN,true);
            if(!is_array($plan) || $plan['expires']<time() || !hash_equals($plan['hash'],Store::digest($token)) || !hash_equals($plan['session'],Store::digest(wp_get_session_token())))return new \WP_Error('proof',__('Restart the process from the confirmation screen.', 'atshift-members'));
            foreach($plan['posts']+$plan['media'] as $post_id=>$hash) {
                $post=get_post($post_id);
                if(!$post || (int)$post->post_author!==$id || !hash_equals($hash,self::fingerprint($post)))return new \WP_Error('changed',__('Posts or attachment information changed after confirmation. Review the details again.', 'atshift-members'));
            }
            $owner=0;
            if($plan['posts']) {$owner=self::custodian();if(is_wp_error($owner))return $owner;}
            $job=['posts'=>array_keys($plan['posts']),'media'=>array_keys($plan['media']),'owner'=>$owner,'started'=>time()];
            // Persist work before consuming consent. Never erase first and then try to remember the plan.
            if(!update_user_meta($id,self::JOB,$job))return new \WP_Error('save',__('Could not save the account closure request.', 'atshift-members'));
            if(!update_user_meta($id,'_asm_state','withdrawing')) {delete_user_meta($id,self::JOB);return new \WP_Error('save',__('Could not save the account closure status.', 'atshift-members'));}
            delete_user_meta($id,self::PLAN);
            \WP_Session_Tokens::get_instance($id)->destroy_all();
            if(!wp_next_scheduled('asm_finish_withdrawal',[$id]))wp_schedule_single_event(time()+60,'asm_finish_withdrawal',[$id]);
        } finally {$store->unlock('withdraw|'.$id);}
        return self::run($id);
    }
    public static function custodian() {
        $store=atshift_members()->store;
        if(!$store->lock('custodian'))return new \WP_Error('busy',__('Please wait a moment and try again.', 'atshift-members'));
        try {
            $id=(int)get_option('asm_custodian_id',0);$user=get_userdata($id);
            if($user && get_user_meta($id,'_asm_custodian',true)==='1' && $user->roles===['asm_custodian'])return $id;
            if($id)return new \WP_Error('owner',__('An administrator needs to verify the recipient account for transferred content.', 'atshift-members'));
            add_role('asm_custodian',__('Site Operator (Content Archive Only)', 'atshift-members'),[]);
            $id=wp_insert_user(['user_login'=>'asm_custodian_'.bin2hex(random_bytes(12)),'user_pass'=>Store::token(),'user_email'=>'','display_name'=>__('Site Operator Archive', 'atshift-members'),'role'=>'asm_custodian','meta_input'=>['_asm_custodian'=>'1']]);
            if(is_wp_error($id))return $id;
            update_option('asm_custodian_id',$id,false);return $id;
        } finally {$store->unlock('custodian');}
    }
    /** Return pending on interruption; state already blocks all member access. */
    public static function run($id) {
        $id=(int)$id;$store=atshift_members()->store;
        if(!$store->lock('withdraw|'.$id))return ['status'=>'pending'];
        try {
            $user=get_userdata($id);if(!$user)return ['status'=>'complete'];
            $job=get_user_meta($id,self::JOB,true);
            if(get_user_meta($id,'_asm_state',true)!=='withdrawing' || !is_array($job))return new \WP_Error('job',__('Could not verify the account closure request.', 'atshift-members'));
            // Schedule the retry before mutating so a fatal error does not strand the job.
            if(!wp_next_scheduled('asm_finish_withdrawal',[$id]))wp_schedule_single_event(time()+60,'asm_finish_withdrawal',[$id]);
            global $wpdb;
            foreach(array_merge($job['posts'],$job['media']) as $post_id) {
                $post=get_post($post_id);if(!$post)continue;
                if((int)$post->post_author===(int)$job['owner'] && get_post_meta($post_id,'_asm_transferred',true)==='1')continue;
                if((int)$post->post_author!==$id && (int)$post->post_author!==(int)$job['owner'])return ['status'=>'pending'];
                $result=wp_update_post(['ID'=>$post_id,'post_author'=>$job['owner'],'post_status'=>$post->post_type==='attachment'?'inherit':'draft'],true);
                if(is_wp_error($result))return ['status'=>'pending'];
                delete_post_meta($post_id,'_edit_last');delete_post_meta($post_id,'_edit_lock');
                foreach(wp_get_post_revisions($post_id,['check_enabled'=>false]) as $revision)if(!wp_delete_post_revision($revision->ID))return ['status'=>'pending'];
                update_post_meta($post_id,'_asm_transferred','1');
            }
            // Force deletion includes trash, custom types with delete_with_user=false and detached uploads.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Security-sensitive current state or atomic transaction/lock operation; WordPress object caching cannot provide these fresh predicates or synchronization semantics.
            $remaining=$wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_author=%d ORDER BY (post_type='attachment') DESC,ID LIMIT 50",$id));
            foreach($remaining as $post_id) {
                $post=get_post($post_id);if(!$post)continue;
                $deleted=$post->post_type==='attachment'?self::delete_attachment($post_id):wp_delete_post($post_id,true);
                if(!$deleted)return ['status'=>'pending'];
            }
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Security-sensitive current state or atomic transaction/lock operation; WordPress object caching cannot provide these fresh predicates or synchronization semantics.
            if($wpdb->get_var($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_author=%d LIMIT 1",$id)))return ['status'=>'pending'];
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Security-sensitive current state or atomic transaction/lock operation; WordPress object caching cannot provide these fresh predicates or synchronization semantics.
            $comments=$wpdb->get_col($wpdb->prepare("SELECT comment_ID FROM {$wpdb->comments} WHERE user_id=%d ORDER BY comment_ID LIMIT 100",$id));
            foreach($comments as $comment_id)if(!wp_delete_comment($comment_id,true))return ['status'=>'pending'];
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Security-sensitive current state or atomic transaction/lock operation; WordPress object caching cannot provide these fresh predicates or synchronization semantics.
            if($wpdb->get_var($wpdb->prepare("SELECT comment_ID FROM {$wpdb->comments} WHERE user_id=%d LIMIT 1",$id)))return ['status'=>'pending'];
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Security-sensitive current state or atomic transaction/lock operation; WordPress object caching cannot provide these fresh predicates or synchronization semantics.
            $edit_meta=$wpdb->get_col($wpdb->prepare("SELECT meta_id FROM {$wpdb->postmeta} WHERE (meta_key='_edit_last' AND meta_value=%s) OR (meta_key='_edit_lock' AND meta_value LIKE %s) LIMIT 100",(string)$id,'%:'.$id));
            foreach($edit_meta as $meta_id)if(!delete_metadata_by_mid('post',(int)$meta_id))return ['status'=>'pending'];
            if(count($edit_meta)===100)return ['status'=>'pending'];
            // Integration owners erase external data before user/meta deletion. No internal credential coupling.
            $errors=apply_filters('atshift_members_erase_user_data',new \WP_Error(),$id);
            if(!($errors instanceof \WP_Error) || $errors->has_errors())return ['status'=>'pending'];
            $email_key=Store::key(strtolower($user->user_email));
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,PluginCheck.Security.DirectDB.UnescapedDBParameter -- Interpolated SQL consists only of WordPress-owned table names and internal prepared/allowlisted predicates; caller values are bound before composition. Security-sensitive current state or atomic transaction/lock operation; WordPress object caching cannot provide these fresh predicates or synchronization semantics.
            if(false===$wpdb->query($wpdb->prepare("DELETE FROM {$store->requests} WHERE user_id=%d OR target_id=%d OR email_key=%s",$id,$id,$email_key)))return ['status'=>'pending'];
            // Remove linkable audit subjects; rate buckets retain only rotating pseudonyms until expiration.
            $subjects=[Store::key((string)$id),$email_key];
            foreach(array_keys(Mail::defaults()) as $type)$subjects[]=Store::key($type.'|'.$user->user_email);
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Security-sensitive current state or atomic transaction/lock operation; WordPress object caching cannot provide these fresh predicates or synchronization semantics.
            foreach($subjects as $subject)if(false===$wpdb->delete($store->audit,['subject'=>$subject]))return ['status'=>'pending'];
            require_once ABSPATH.'wp-admin/includes/user.php';
            if(!wp_delete_user($id) || get_userdata($id))return ['status'=>'pending'];
            wp_clear_scheduled_hook('asm_finish_withdrawal',[$id]);
            // No departing ID/email/token stored in the completion audit event.
            $store->log('withdrawal_complete');
            Mail::send('withdrawn',$user->user_email,'',false);
            return ['status'=>'complete'];
        } finally {$store->unlock('withdraw|'.$id);}
    }
    private static function delete_attachment($id) {
        global $wpdb;
        $relative=get_post_meta($id,'_wp_attached_file',true);
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Security-sensitive current state or atomic transaction/lock operation; WordPress object caching cannot provide these fresh predicates or synchronization semantics.
        $shared=$relative && $wpdb->get_var($wpdb->prepare("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key='_wp_attached_file' AND meta_value=%s AND post_id<>%d LIMIT 1",$relative,$id));
        // Distinct attachment records can share physical bytes. Do not unlink another owner's copy.
        $preserve=static function($path){return '';};
        if($shared)add_filter('wp_delete_file',$preserve,PHP_INT_MAX);
        try{return wp_delete_attachment($id,true);}
        finally{if($shared)remove_filter('wp_delete_file',$preserve,PHP_INT_MAX);}
    }
}
