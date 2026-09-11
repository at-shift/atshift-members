<?php
namespace Atshift\Membership;
defined('ABSPATH') || exit;
if(!class_exists('WP_List_Table'))require_once ABSPATH.'wp-admin/includes/class-wp-list-table.php';

final class Member_List_Table extends \WP_List_Table {
    private $query_args=[];
    public function __construct(){parent::__construct(['singular'=>'member','plural'=>'members','ajax'=>false]);}
    public function get_columns(){return ['cb'=>'<input type="checkbox">','name'=>__('Name', 'atshift-members'),'email'=>__('Email Address', 'atshift-members'),'role'=>__('Role', 'atshift-members'),'state'=>__('Member Status', 'atshift-members'),'posting'=>__('Available Post Types', 'atshift-members')];}
    protected function get_sortable_columns(){return ['name'=>['display_name',false],'email'=>['user_email',false]];}
    protected function get_primary_column_name(){return 'name';}
    public function no_items(){echo esc_html__('No members match your criteria.', 'atshift-members');}
    public function prepare_items(){
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin display/filter parameters; mutation handlers separately require nonce and object capability checks.
        $search=is_string($_GET['s']??null)?sanitize_text_field(wp_unslash($_GET['s'])):'';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin display/filter parameters; mutation handlers separately require nonce and object capability checks.
        $state=is_string($_GET['state']??null)?sanitize_key($_GET['state']):'';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin display/filter parameters; mutation handlers separately require nonce and object capability checks.
        $orderby=is_string($_GET['orderby']??null)?sanitize_key(wp_unslash($_GET['orderby'])):'';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin display/filter parameters; mutation handlers separately require nonce and object capability checks.
        $order=is_string($_GET['order']??null)&&sanitize_key(wp_unslash($_GET['order']))==='asc'?'ASC':'DESC';
        $args=['role__in'=>['asm_member','asm_operator'],'number'=>20,'paged'=>$this->get_pagenum(),'orderby'=>in_array($orderby,['display_name','user_email'],true)?$orderby:'ID','order'=>$order,'count_total'=>true];
        if($search!==''){$args['search']='*'.$search.'*';$args['search_columns']=['user_login','user_email','display_name'];}
        if(Scope::limited()&&!current_user_can('manage_options'))$args['include']=Scope::target_ids()?:[0];
        $this->query_args=$args;
        if(in_array($state,['active','suspended','pending'],true))$args['meta_query']=[['key'=>'_asm_state','value'=>$state]];
        $query=new \WP_User_Query($args);$this->items=$query->get_results();
        $this->_column_headers=[$this->get_columns(),[], $this->get_sortable_columns(),'name'];
        $this->set_pagination_args(['total_items'=>$query->get_total(),'per_page'=>20]);
    }
    protected function get_views(){
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin display/filter parameters; mutation handlers separately require nonce and object capability checks.
        $views=[];$current=is_string($_GET['state']??null)?sanitize_key($_GET['state']):'';
        foreach([''=>__('All', 'atshift-members'),'active'=>__('Active', 'atshift-members'),'suspended'=>__('Suspended', 'atshift-members'),'pending'=>__('Pending Approval', 'atshift-members')] as $state=>$label){
            $args=$this->query_args;$args['number']=1;$args['paged']=1;$args['fields']='ID';
            if($state!=='')$args['meta_query']=[['key'=>'_asm_state','value'=>$state]];
            $query=new \WP_User_Query($args);
            $url=remove_query_arg(['paged','state','member_id'],Admin::url());if($state!=='')$url=add_query_arg('state',$state,$url);
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin display/filter parameters; mutation handlers separately require nonce and object capability checks.
            if(!empty($_GET['s'])&&is_string($_GET['s']))$url=add_query_arg('s',sanitize_text_field(wp_unslash($_GET['s'])),$url);
            $views[$state?:'all']='<a href="'.esc_url($url).'"'.($current===$state?' class="current" aria-current="page"':'').'>'.esc_html($label).' <span class="count">('.number_format_i18n($query->get_total()).')</span></a>';
        }
        return $views;
    }
    protected function bulk_actions($which=''){
        if($which!=='top')return;
        echo ('<div class="alignleft actions bulkactions">' . '<label class="screen-reader-text" for="asm-bulk-state">' . esc_html__('Bulk Actions', 'atshift-members') . '</label>' . '<select id="asm-bulk-state" name="bulk_state" required>' . '<option value="">' . esc_html__('Bulk Actions', 'atshift-members') . '</option>' . '<option value="active">' . esc_html__('Activate', 'atshift-members') . '</option>' . '<option value="suspended">' . esc_html__('Suspend', 'atshift-members') . '</option>' . '<option value="pending">' . esc_html__('Set to Pending Approval', 'atshift-members') . '</option>' . '</select>' . '<button type="submit" class="button action" id="asm-bulk-apply">' . esc_html__('Apply', 'atshift-members') . '</button>' . '</div>');
    }
    protected function column_cb($user){
        if(is_wp_error(Members::state_error($user->ID)))return '';
        /* translators: %s: Member display name. */
        return '<label class="screen-reader-text" for="asm-member-'.$user->ID.'">'.sprintf(esc_html__('Select %s', 'atshift-members'),esc_html($user->display_name)).('</label>' . '<input type="checkbox" id="asm-member-').$user->ID.'" name="users[]" value="'.(int)$user->ID.'">';
    }
    protected function column_name($user){
        $url=add_query_arg('member_id',$user->ID,Admin::url());
        return '<strong><a href="'.esc_url($url).'">'.esc_html($user->display_name?:$user->user_login).'</a></strong>'.$this->row_actions(['settings'=>'<a href="'.esc_url($url).('">' . esc_html__('Settings', 'atshift-members') . '</a>')]);
    }
    protected function column_default($user,$column){
        if($column==='email')return esc_html($user->user_email);
        if($column==='role')return in_array('asm_operator',$user->roles,true)?__('Site Operator', 'atshift-members'):__('Member', 'atshift-members');
        if($column==='state'){$state=get_user_meta($user->ID,'_asm_state',true);return '<span class="asm-member-state asm-state-'.esc_attr($state).'">'.esc_html(Members::state_label($state)).'</span>';}
        if($column==='posting')return esc_html(implode('、',Posting::labels($user->ID))?:__('None Allowed', 'atshift-members'));
        return '';
    }
}
