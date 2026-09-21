<?php
namespace Atshift\Membership;
defined('ABSPATH') || exit;

/** Read-only, composable member home blocks. Providers return data, never markup. */
final class Dashboard {
    public static function hooks() {
        foreach(['atshme_dashboard'=>'all','atshme_account_links'=>'account','atshme_post_links'=>'posts','atshme_approval_status'=>'approvals'] as $tag=>$section)
            add_shortcode($tag,static function($attrs)use($section){return self::render($attrs,$section);});
        add_action('admin_post_atshme_create_home',[self::class,'create_home']);
        // Protect personalized output even when a block/template expands shortcodes after headers.
        add_action('template_redirect',static function(){if(is_user_logged_in())Screens::private_headers();},0);
    }
    public static function home_settings(){
        if(!current_user_can('manage_options'))return;
        $id=(int)get_option('atshme_home_page',0);
        echo '<section class="atshme-settings-card"><h2>'.esc_html__('Member Home', 'atshift-members').'</h2><p>'.esc_html__('Create a page with account and posting links. Approval status is also shown when the Pro add-on is active. You can edit the generated page in the block editor.', 'atshift-members').'</p>';
        if($id&&get_post($id)){
            echo '<p>'.esc_html__('The member home page has been created.', 'atshift-members').'</p><p><a href="'.esc_url(get_edit_post_link($id)).'">'.esc_html__('Edit Member Home', 'atshift-members').'</a></p>';
        }else{
            echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="atshme_create_home">';
            wp_nonce_field('atshme_create_home');echo '<button class="button button-primary">'.esc_html__('Create Member Home', 'atshift-members').'</button></form>';
        }
        echo '<p>'.esc_html__('The page is created as a draft. Existing pages, the site front page, and the login redirect are not changed. The member directory is not inserted automatically.', 'atshift-members').'</p></section>';
    }
    public static function create_home(){
        if(!current_user_can('manage_options')||!current_user_can('edit_pages'))wp_die(esc_html__('You cannot create pages.', 'atshift-members'),'',['response'=>403]);
        check_admin_referer('atshme_create_home');
        $id=(int)get_option('atshme_home_page',0);
        if(!$id||!get_post($id)){
            $content='<!-- wp:heading --><h2 class="wp-block-heading">'.esc_html__('Member resources', 'atshift-members').'</h2><!-- /wp:heading -->'."\n".'<!-- wp:shortcode -->[atshme_dashboard heading_tag="h3"]<!-- /wp:shortcode -->';
            $id=wp_insert_post(['post_type'=>'page','post_status'=>'draft','post_title'=>__('Member Home', 'atshift-members'),'post_content'=>$content],true);
            if(is_wp_error($id))wp_die(esc_html($id->get_error_message()));
            update_option('atshme_home_page',$id,false);
        }
        wp_safe_redirect(get_edit_post_link($id,'raw'));exit;
    }
    public static function label($ja,$en){return strpos(determine_locale(),'ja')===0?$ja:$en;}
    public static function render($attrs=[],$section='all') {
        if(!is_user_logged_in()||!Members::reader())return '';
        Screens::private_headers();
        $attrs=shortcode_atts(['class'=>'','heading_tag'=>'h2','title'=>'','limit'=>'5'],$attrs);
        $tag=in_array($attrs['heading_tag'],['h2','h3','h4','h5','h6','p'],true)?$attrs['heading_tag']:'h2';
        $limit=max(1,min(20,(int)$attrs['limit']));
        $blocks=[];
        if($section==='all'||$section==='account'){
            $links=[];$pages=get_option('atshme_pages',[]);
            foreach(['account'=>self::label('アカウント情報','Account information'),'edit'=>self::label('登録情報を変更','Edit account information')] as $key=>$label){
                if(!empty($pages[$key])&&get_post_status((int)$pages[$key])==='publish')$links[]=['label'=>$label,'url'=>Screens::url($key)];
            }
            if($links)$blocks['account']=['title'=>self::label('アカウント','Account'),'items'=>$links];
        }
        if($section==='all'||$section==='posts'){
            $links=[];
            foreach(Posting::types() as $name=>$type){
                if(!current_user_can($type->cap->create_posts))continue;
                $links[]=['label'=>$type->labels->add_new_item,'url'=>admin_url('post-new.php?post_type='.$name)];
                $links[]=['label'=>sprintf(self::label('自分の%s','My %s'),$type->labels->name),'url'=>add_query_arg(['post_type'=>$name,'author'=>get_current_user_id()],admin_url('edit.php'))];
            }
            if($links)$blocks['posts']=['title'=>self::label('投稿','Posts'),'items'=>$links];
        }
        $blocks=apply_filters('atshift_members_dashboard_sections',$blocks,$section,$limit);
        $classes=implode(' ',array_filter(array_map('sanitize_html_class',preg_split('/\s+/',(string)$attrs['class']))));
        $html='<div class="atshme-dashboard'.($classes?' '.esc_attr($classes):'').'">';
        foreach((array)$blocks as $key=>$block){
            if($section!=='all'&&$key!==$section)continue;
            if(empty($block['items']))continue;
            $title=$section!=='all'&&$attrs['title']!==''?$attrs['title']:($block['title']??'');
            $html.='<section class="atshme-dashboard__section atshme-dashboard__'.esc_attr(sanitize_html_class($key)).'"><'.$tag.' class="atshme-dashboard__heading">'.esc_html($title).'</'.$tag.'><ul class="atshme-dashboard__list">';
            foreach($block['items'] as $item){
                $html.='<li class="atshme-dashboard__item"><a class="atshme-dashboard__link" href="'.esc_url($item['url']).'">'.esc_html($item['label']).'</a>';
                if(!empty($item['status']))$html.=' <span class="atshme-dashboard__status">'.esc_html($item['status']).'</span>';
                $html.='</li>';
            }
            $html.='</ul></section>';
        }
        return $html.'</div>';
    }
}
