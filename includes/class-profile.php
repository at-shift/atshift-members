<?php
namespace Atshift\Membership;
defined('ABSPATH') || exit;

/** Built-in profile fields. The caller authorizes registration or editing the exact user. */
final class Profile {
    public static function linked_api() {
        $api=['version'=>1];
        foreach(['fields','render','validate','save','values'] as $method)$api[$method]=[self::class,'linked_'.$method];
        return $api;
    }
    /** Separate namespaces prevent custom identifiers from shadowing native storage. */
    private static function linked_plan() {
        $schema=Registration::automatic_schema();$api=Registration::api();$plan=[];
        if(!$schema||!$api)return [];
        $native=self::fields(self::default_fields());
        foreach($schema['standard'] as $key=>$options){
            if(!isset($native[$key]))continue;
            $field=$native[$key];if(!empty($options['label']))$field['label']=(string)$options['label'];
            $plan['wp_'.$key]=['source'=>'wp','key'=>$key,'field'=>$field];
        }
        foreach(call_user_func($api['fields'],$schema['custom']) as $key=>$field)$plan['upf_'.$key]=['source'=>'upf','key'=>$key,'field'=>$field];
        if(!empty($schema['order'])&&is_array($schema['order'])){
            $ordered=[];foreach($schema['order'] as $key)if(isset($plan[$key]))$ordered[$key]=$plan[$key];
            $plan=$ordered+$plan;
        }
        return $plan;
    }
    public static function linked_fields($allowed=null) {
        $fields=[];foreach(self::linked_plan() as $key=>$item)if($allowed===null||in_array($key,(array)$allowed,true))$fields[$key]=$item['field'];
        return $fields;
    }
    public static function linked_render($allowed,$values=[]) {
        $api=Registration::api();
        foreach(self::linked_plan() as $key=>$item){
            if(!in_array($key,(array)$allowed,true))continue;
            $name=$item['key'];ob_start();
            if($item['source']==='wp')self::render([$name],[$name=>$values[$key]??''],[$name=>$item['field']['label']]);
            else call_user_func($api['render'],[$name],[$name=>$values[$key]??'']);
            $html=ob_get_clean();
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Trusted profile renderer HTML; only escaped input-name identifiers are substituted.
            echo str_replace('name="asm_fields['.esc_attr($name).']"','name="asm_fields['.esc_attr($key).']"',$html);
        }
    }
    private static function linked_parts($allowed,$input) {
        $plan=self::linked_plan();$parts=['wp'=>['keys'=>[],'values'=>[]],'upf'=>['keys'=>[],'values'=>[]]];
        foreach($plan as $key=>$item)if(in_array($key,(array)$allowed,true)){
            $parts[$item['source']]['keys'][]=$item['key'];
            if(array_key_exists($key,$input))$parts[$item['source']]['values'][$item['key']]=$input[$key];
        }
        return $parts;
    }
    public static function linked_validate($allowed,$input) {
        if(!is_array($input)||array_diff(array_keys($input),array_keys(self::linked_fields($allowed))))return new \WP_Error('profile_fields',__('Some profile fields are not allowed.', 'atshift-members'));
        $parts=self::linked_parts($allowed,$input);$result=[];$api=Registration::api();
        foreach($parts as $source=>$part){
            $values=$source==='wp'?self::validate($part['keys'],$part['values']):call_user_func($api['validate'],$part['keys'],$part['values']);
            if(is_wp_error($values))return $values;
            foreach($values as $key=>$value)$result[$source.'_'.$key]=$value;
        }
        return $result;
    }
    public static function linked_save($id,$allowed,$input) {
        $values=self::linked_validate($allowed,$input);if(is_wp_error($values))return $values;
        $parts=self::linked_parts($allowed,$values);$api=Registration::api();
        foreach($parts as $source=>$part){
            $saved=$source==='wp'?self::save($id,$part['keys'],$part['values']):call_user_func($api['save'],$id,$part['keys'],$part['values']);
            if(is_wp_error($saved))return $saved;
        }
        return true;
    }
    public static function linked_values($id,$allowed) {
        $parts=self::linked_parts($allowed,[]);$result=[];$api=Registration::api();
        foreach($parts as $source=>$part){
            $values=$source==='wp'?self::values($id,$part['keys']):call_user_func($api['values'],$id,$part['keys']);
            foreach($values as $key=>$value)$result[$source.'_'.$key]=$value;
        }
        return $result;
    }
    public static function default_fields() {return ['first_name','last_name','nickname','display_name','user_url','bio'];}
    public static function api() {
        $api=['version'=>1];
        foreach(['fields','render','validate','save','values'] as $method)$api[$method]=[self::class,$method];
        return $api;
    }
    public static function fields($allowed) {
        $fields=[
            'first_name'=>['label'=>__('First Name', 'atshift-members'),'type'=>'text','required'=>false,'maxlength'=>100],
            'last_name'=>['label'=>__('Last Name', 'atshift-members'),'type'=>'text','required'=>false,'maxlength'=>100],
            'nickname'=>['label'=>__('Nickname', 'atshift-members'),'type'=>'text','required'=>true,'maxlength'=>100],
            'display_name'=>['label'=>__('Display Name', 'atshift-members'),'type'=>'text','required'=>false,'maxlength'=>250],
            'user_url'=>['label'=>__('Website URL', 'atshift-members'),'type'=>'url','required'=>false,'maxlength'=>100],
            'bio'=>['label'=>__('Biography', 'atshift-members'),'type'=>'textarea','required'=>false,'maxlength'=>2000],
        ];
        return array_intersect_key($fields,array_flip(array_intersect((array)$allowed,array_keys($fields))));
    }
    public static function render($allowed,$values=[],$labels=[]) {
        foreach(self::fields($allowed) as $key=>$field) {
            if(isset($labels[$key]))$field['label']=$labels[$key];
            $value=is_string($values[$key]??null)?$values[$key]:'';
            echo '<p><label for="asm-basic-'.esc_attr($key).'">'.esc_html($field['label']).($field['required']?esc_html__(' (Required)', 'atshift-members'):esc_html__(' (Optional)', 'atshift-members')).'</label><br>';
            $attributes=' id="asm-basic-'.esc_attr($key).'" name="asm_fields['.esc_attr($key).']" maxlength="'.(int)$field['maxlength'].'"'.($field['required']?' required':'');
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Attribute fragment built above solely from esc_attr values, integer maxlength and literal required.
            if($field['type']==='textarea')echo '<textarea'.$attributes.' rows="5">'.esc_textarea($value).'</textarea>';
            else {
                $autocomplete=['first_name'=>'given-name','last_name'=>'family-name','nickname'=>'nickname','user_url'=>'url'];
                // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Attribute fragment built above solely from esc_attr values, integer maxlength and literal required.
                echo '<input type="'.esc_attr($field['type']).'"'.$attributes.' value="'.esc_attr($value).'"'.(isset($autocomplete[$key])?' autocomplete="'.esc_attr($autocomplete[$key]).'"':'').'>';
            }
            if($key==='display_name')echo ('<br>' . '<small class="description">' . esc_html__('The name shown on the site. Leave blank to use your nickname.', 'atshift-members') . '</small>');
            echo '</p>';
        }
    }
    public static function validate($allowed,$input) {
        $fields=self::fields($allowed);$values=[];
        if(!is_array($input)||array_diff(array_keys($input),array_keys($fields)))return new \WP_Error('profile_fields',__('Some profile fields are not allowed.', 'atshift-members'));
        foreach($fields as $key=>$field) {
            $raw=$input[$key]??'';
            if(!is_string($raw)||strlen($raw)>$field['maxlength']*4||preg_match('//u',$raw)!==1||preg_match_all('/./us',$raw)>$field['maxlength'])return new \WP_Error('profile_value',__('Check the format and length of your entries.', 'atshift-members'));
            $value=$field['type']==='textarea'?sanitize_textarea_field($raw):sanitize_text_field($raw);
            if($field['type']==='url'&&$value!==''){
                $url=esc_url_raw($value,['http','https']);$parts=wp_parse_url($url);
                if(!preg_match('#^https?://#i',$value)||!$url||strlen($url)>$field['maxlength']||!is_array($parts)||empty($parts['host'])||!in_array($parts['scheme']??'', ['http','https'],true))return new \WP_Error('profile_url',__('Enter a website URL starting with http:// or https://, up to 100 characters long.', 'atshift-members'));
                $value=$url;
            }
            if($field['required']&&trim($value)==='')return new \WP_Error('profile_required',__('Enter a nickname.', 'atshift-members'));
            $values[$key]=$value;
        }
        return $values;
    }
    public static function save($user_id,$allowed,$input) {
        if(!get_userdata($user_id))return new \WP_Error('profile_user',__('User not found.', 'atshift-members'));
        $values=self::validate($allowed,$input);if(is_wp_error($values))return $values;
        $data=['ID'=>(int)$user_id];
        foreach(['first_name','last_name','nickname','user_url'] as $key)if(isset($values[$key]))$data[$key]=$values[$key];
        if(!empty($values['display_name']))$data['display_name']=$values['display_name'];
        elseif(isset($values['nickname']))$data['display_name']=$values['nickname'];
        if(isset($values['bio']))$data['description']=$values['bio'];
        if(count($data)===1)return true;
        $saved=wp_update_user(wp_slash($data));
        return is_wp_error($saved)?$saved:true;
    }
    public static function values($user_id,$allowed) {
        $user=get_userdata($user_id);if(!$user)return [];
        $values=['first_name'=>(string)$user->first_name,'last_name'=>(string)$user->last_name,'nickname'=>(string)$user->nickname,'display_name'=>(string)$user->display_name,'user_url'=>(string)$user->user_url,'bio'=>(string)$user->description];
        return array_intersect_key($values,self::fields($allowed));
    }
}
