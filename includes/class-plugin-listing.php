<?php
namespace Atshift\Membership;
defined('ABSPATH') || exit;

final class PluginListing {
    private static function slug($file) {
        return [
            'atshift-members.php'=>'atshift-members',
            'atshift-members-pro.php'=>'atshift-members-pro',
        ][basename($file)]??'';
    }
    public static function hooks() {
        add_filter('plugin_action_links',[self::class,'actions'],20,2);
        add_filter('plugin_row_meta',[self::class,'meta'],20,3);
        add_filter('plugins_api',[self::class,'information'],20,3);
    }
    public static function actions($links,$file) {
        $slug=self::slug($file);
        if(!$slug||!is_plugin_active($file))return $links;
        // Preserve WordPress's capability/dependency checks on the actual action.
        $actions=[];
        if($slug==='atshift-members') {
            if(Admin::can_access_settings())$actions['settings']='<a href="'.esc_url(Admin::url(current_user_can('manage_options')?'atshift-members-pages':'atshift-members')).('">' . esc_html__('Settings', 'atshift-members') . '</a>');
            if(!self::pro_installed())$actions['purchase']=('<span>' . esc_html__('Buy the Pro Add-on', 'atshift-members') . '</span>');
        }
        if(isset($links['deactivate']))$actions['deactivate']=$links['deactivate'];
        return $actions;
    }
    private static function pro_installed() {
        foreach(array_keys(get_plugins()) as $file)if(self::slug($file)==='atshift-members-pro')return true;
        return false;
    }
    public static function meta($links,$file,$data) {
        $slug=self::slug($file);
        if(!$slug)return $links;
        $url=add_query_arg(['tab'=>'plugin-information','plugin'=>$slug,'TB_iframe'=>'true','width'=>600,'height'=>550],self_admin_url('plugin-install.php'));
        /* translators: %s: Installed plugin version. */
        $version=sprintf(esc_html__('Version %s','atshift-members'),esc_html($data['Version']));
        /* translators: %s: Plugin author link. */
        $author=sprintf(esc_html__('By %s','atshift-members'),'<a href="https://plugins.at-shift.net/">@shift</a>');
        $meta=[$version,$author];
        /* translators: %s: Plugin name. */
        $meta[]='<a href="'.esc_url($url).'" class="thickbox open-plugin-details-modal" aria-label="'.esc_attr(sprintf(__('View details for %s', 'atshift-members'),$data['Name'])).('">' . esc_html__('View Details', 'atshift-members') . '</a>');
        if($slug==='atshift-members')$meta[]=('<span>' . esc_html__('Buy the Pro Add-on', 'atshift-members') . '</span>');
        return $meta;
    }
    public static function information($result,$action,$args) {
        if($action!=='plugin_information'||!in_array($args->slug??'',['atshift-members','atshift-members-pro'],true))return $result;
        // Show information from the installed package; no unreleased marketplace URL is required.
        foreach(get_plugins() as $file=>$data) {
            if(self::slug($file)!==$args->slug)continue;
            return (object)[
                'name'=>$data['Name'],'slug'=>$args->slug,'version'=>$data['Version'],'author'=>'<a href="https://plugins.at-shift.net/">@shift</a>',
                'requires'=>$data['RequiresWP']??'','requires_php'=>$data['RequiresPHP']??'',
                'download_link'=>'','external'=>true,
                'sections'=>['description'=>'<p>'.esc_html(wp_strip_all_tags($data['Description'])).'</p>'],
            ];
        }
        return $result;
    }
}
