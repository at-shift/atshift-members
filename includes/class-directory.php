<?php
namespace Atshift\Membership;
defined('ABSPATH') || exit;

final class Directory {
    const OPTION='atshme_directory';
    public static function options(){return array_merge(['enabled'=>false,'view'=>'members','view_groups'=>[],'list_groups'=>[],'fields'=>['name'],'excluded_users'=>[],'hide_author'=>true],(array)get_option(self::OPTION,[]));}
    public static function hooks(){
        add_shortcode('atshme_member_directory',[self::class,'render']);
        add_filter('atshift_members_admin_sections',function($s){$s['atshift-members-directory']=['title'=>__('Member Directory', 'atshift-members'),'description'=>__('Choose who can view the member directory and which profile details it shows.', 'atshift-members'),'capability'=>'manage_options','render'=>[self::class,'settings']];return $s;});
        add_action('admin_post_atshme_directory',[self::class,'save']);
        add_filter('author_link',fn($url)=>self::options()['hide_author']?home_url('/'):$url);
        add_filter('the_author_posts_link',fn($html)=>self::options()['hide_author']?esc_html(get_the_author()):$html);
        add_action('template_redirect',function(){
            if(self::options()['hide_author']&&is_author()){
                remove_action('template_redirect','redirect_canonical');
                global $wp_query;$wp_query->set_404();status_header(404);nocache_headers();
            }
        },0);
    }
    public static function settings(){
        if(!current_user_can('manage_options'))return;
        $o=self::options();$catalog=Audience::catalog();
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Read-only success flag set by this class after the nonce-protected save; only the exact literal 1 is accepted.
        if(is_string($_GET['saved']??null)&&wp_unslash($_GET['saved'])==='1')echo '<div class="notice notice-success"><p>'.esc_html__('Settings saved.', 'atshift-members').'</p></div>';
        echo '<section class="atshme-settings-card"><form method="post" action="'.esc_url(admin_url('admin-post.php')).'"><input type="hidden" name="action" value="atshme_directory">';
        wp_nonce_field('atshme_directory');
        echo '<h2>'.esc_html__('Member Directory', 'atshift-members').'</h2><p><label><input type="checkbox" name="enabled" value="1" '.checked($o['enabled'],true,false).'>'.esc_html__('Enable the member directory', 'atshift-members').'</label></p><p><label>'.esc_html__('Who can view it', 'atshift-members').' <select name="view">';
        foreach(['members'=>__('Active members', 'atshift-members'),'operators'=>__('Site operators', 'atshift-members'),'groups'=>__('Members in selected classifications', 'atshift-members'),'public'=>__('Everyone', 'atshift-members')] as $key=>$label)echo '<option value="'.esc_attr($key).'" '.selected($o['view'],$key,false).'>'.esc_html($label).'</option>';
        echo '</select></label></p>';
        foreach(['view_groups'=>__('Classifications allowed to view the directory (when selected classifications are used)', 'atshift-members'),'list_groups'=>__('Member classifications to list (leave empty to list all)', 'atshift-members')] as $name=>$title){
            echo '<fieldset><legend>'.esc_html($title).'</legend>';
            if(!$catalog)echo '<p>'.esc_html__('User Profile Fields integration is required to select member classifications.', 'atshift-members').'</p>';
            foreach($catalog as $key=>$label)echo '<p><label><input type="checkbox" name="'.esc_attr($name).'[]" value="'.esc_attr($key).'" '.checked(in_array($key,$o[$name],true),true,false).'>'.esc_html($label).'</label></p>';
            echo '</fieldset>';
        }
        echo '<details class="atshme-disclosure"><summary>'.esc_html__('Exclude Users from the Directory', 'atshift-members').'</summary><p>'.esc_html__('Selected users remain hidden even when they match the listing scope. Their account access and permissions are not changed.', 'atshift-members').'</p>';
        foreach(get_users(['orderby'=>'display_name','order'=>'ASC']) as $user){
            echo '<p><label><input type="checkbox" name="excluded_users[]" value="'.(int)$user->ID.'" '.checked(in_array((int)$user->ID,array_map('intval',$o['excluded_users']),true),true,false).'>'.esc_html($user->display_name).' (ID '.(int)$user->ID.')</label></p>';
        }
        echo '</details>';
        echo '<fieldset><legend>'.esc_html__('Profile Details to Show', 'atshift-members').'</legend>';
        foreach(['name'=>__('Display name', 'atshift-members'),'affiliation'=>__('Affiliation', 'atshift-members'),'bio'=>__('Biography', 'atshift-members')] as $key=>$label)echo '<p><label><input type="checkbox" name="fields[]" value="'.esc_attr($key).'" '.checked(in_array($key,$o['fields'],true),true,false).'>'.esc_html($label).'</label></p>';
        echo '</fieldset><p>'.esc_html__('Only active members are listed. Login names, email addresses, and administrative permissions are never shown. When Everyone is selected, the chosen profile details are available without logging in.', 'atshift-members').'</p><button class="button button-primary">'.esc_html__('Save Member Directory Settings', 'atshift-members').'</button></form></section>';
    }
    public static function save(){
        if(!current_user_can('manage_options'))wp_die(esc_html__('You cannot change these settings.', 'atshift-members'),'',['response'=>403]);
        check_admin_referer('atshme_directory');$catalog=Audience::catalog();
        $view=is_string($_POST['view']??null)?sanitize_key(wp_unslash($_POST['view'])):'';
        if(!in_array($view,['members','operators','groups','public'],true))wp_die(esc_html__('Check who can view the directory.', 'atshift-members'));
        $input=[];
        foreach(['view_groups','list_groups','fields'] as $key){
            // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The unslashed structured field is type-checked, sanitized and matched to the current server-side allowlist below.
            $values=wp_unslash($_POST[$key]??[]);
            $allowed=$key==='fields'?['name','affiliation','bio']:array_keys($catalog);
            if(!is_array($values))wp_die(esc_html__('Check the selected items.', 'atshift-members'));
            $values=array_map(static fn($value)=>is_string($value)?sanitize_text_field($value):'',$values);
            if(array_filter($values,fn($value)=>$value===''||!in_array($value,$allowed,true)))wp_die(esc_html__('Check the selected items.', 'atshift-members'));
            $input[$key]=array_values(array_unique($values));
        }
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- The unslashed structured field is type-checked, sanitized as decimal IDs and resolved to current users below.
        $excluded=wp_unslash($_POST['excluded_users']??[]);
        if(!is_array($excluded))wp_die(esc_html__('Check the users excluded from the directory.', 'atshift-members'));
        $excluded=array_map(static fn($id)=>is_string($id)?sanitize_text_field($id):'',$excluded);
        if(array_filter($excluded,fn($id)=>!ctype_digit($id)||(int)$id<1||!get_userdata((int)$id)))wp_die(esc_html__('Check the users excluded from the directory.', 'atshift-members'));
        $excluded=array_values(array_unique(array_map('intval',$excluded)));
        if($view==='groups'&&!$input['view_groups'])wp_die(esc_html__('Select at least one classification that can view the directory.', 'atshift-members'));
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- An enabled checkbox must be the exact literal 1.
        $enabled=is_string($_POST['enabled']??null)&&wp_unslash($_POST['enabled'])==='1';
        update_option(self::OPTION,['enabled'=>$enabled,'hide_author'=>self::options()['hide_author'],'view'=>$view,'view_groups'=>$input['view_groups'],'list_groups'=>$input['list_groups'],'fields'=>$input['fields'],'excluded_users'=>$excluded],false);
        wp_safe_redirect(admin_url('admin.php?page=atshift-members-directory&saved=1'));exit;
    }
    private static function own($id){return Scope::own_keys($id);}
    public static function allowed($o){
        if(!$o['enabled'])return false;
        if(current_user_can('manage_options'))return true;
        if($o['view']==='public')return true;
        if(!is_user_logged_in()||!Members::active(get_current_user_id()))return false;
        if($o['view']==='members')return true;
        if($o['view']==='operators')return in_array('atshme_operator',wp_get_current_user()->roles,true);
        return $o['view']==='groups'&&(bool)array_intersect($o['view_groups'],self::own(get_current_user_id()));
    }
    public static function render($attrs=[]){
        $o=self::options();Screens::private_headers();
        if(!self::allowed($o))return '';
        $attrs=shortcode_atts(['class'=>'','heading_tag'=>'h3','per_page'=>'20'],$attrs);
        $tag=in_array($attrs['heading_tag'],['h2','h3','h4','h5','h6','p'],true)?$attrs['heading_tag']:'h3';
        $classes=implode(' ',array_filter(array_map('sanitize_html_class',preg_split('/\s+/',(string)$attrs['class']))));
        $per=max(1,min(100,(int)$attrs['per_page']));
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only public pagination parameter.
        $page=is_string($_GET['atshme_directory_page']??null)?max(1,absint(wp_unslash($_GET['atshme_directory_page']))):1;
        $matches=[];$catalog=Audience::catalog();
        foreach(get_users(['meta_key'=>'_atshme_state','meta_value'=>'active','orderby'=>'display_name','order'=>'ASC']) as $u){
            if(!Members::active($u->ID)||in_array((int)$u->ID,array_map('intval',$o['excluded_users']),true))continue;
            $own=self::own($u->ID);
            if($o['list_groups']&&!array_intersect($o['list_groups'],$own))continue;
            $matches[]=[$u,$own];
        }
        $html='<div class="atshme-directory '.esc_attr($classes).'"><ul class="atshme-directory__list">';
        foreach(array_slice($matches,($page-1)*$per,$per) as [$u,$own]){
            $html.='<li class="atshme-directory__member">';
            if(in_array('name',$o['fields'],true)){
                $name=trim($u->display_name);
                if($name===''||strcasecmp($name,$u->user_login)===0||strcasecmp($name,$u->user_email)===0)$name=Dashboard::label('会員','Member');
                $html.='<'.$tag.' class="atshme-directory__name">'.esc_html($name).'</'.$tag.'>';
            }
            if(in_array('affiliation',$o['fields'],true))$html.='<p class="atshme-directory__affiliation">'.esc_html(implode(Dashboard::label('、',', '),array_intersect_key($catalog,array_flip($own)))).'</p>';
            if(in_array('bio',$o['fields'],true))$html.='<p class="atshme-directory__bio">'.nl2br(esc_html($u->description)).'</p>';
            $html.='</li>';
        }
        $html.='</ul><nav class="atshme-directory__pagination" aria-label="'.esc_attr(Dashboard::label('会員一覧のページ','Directory pages')).'">';
        foreach([$page-1=>Dashboard::label('前へ','Previous'),$page+1=>Dashboard::label('次へ','Next')] as $n=>$label)
            if($n>=1&&($n-1)*$per<count($matches))$html.='<a href="'.esc_url(add_query_arg('atshme_directory_page',$n)).'">'.esc_html($label).'</a> ';
        return $html.'</nav></div>';
    }
}
