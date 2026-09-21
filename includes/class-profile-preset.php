<?php
namespace Atshift\Membership;
defined('ABSPATH') || exit;

/** Members owns these definitions; UPF owns preview, validation and opt-in application. */
final class ProfilePreset {
    const ID = 'atshift-members.basic-profile';
    const VERSION = '1.1.0';

    /** Called after all plugins load, so activation order cannot affect registration. */
    public static function hooks() {
        if(!function_exists('atshift_upf_preset_api'))return;
        $api=atshift_upf_preset_api();
        if(!is_array($api)||!is_numeric($api['version']??null)||(float)$api['version']<1)return;
        add_filter('atshift_upf_preset_providers',[self::class,'register'],10,2);
    }

    /** Return definitions only; UPF performs explicit, validated application. */
    public static function register($providers,$context=[]) {
        $providers[self::ID]=self::descriptor();
        return $providers;
    }
    public static function descriptor() {
        return [
            'id'=>self::ID,'version'=>self::VERSION,
            'label'=>__('Members Basic Profile','atshift-members'),
            'description'=>__('Add the missing basic profile fields without replacing existing settings or profile values.','atshift-members'),
            'provider'=>['plugin'=>'atshift-members','version'=>self::plugin_version()],
            'resolve'=>[self::class,'resolve'],
        ];
    }
    private static function plugin_version() {
        // Read our own header; do not couple the preset revision to the plugin revision.
        $header=file_get_contents(dirname(__DIR__).'/atshift-members.php',false,null,0,2048);
        return preg_match('/^ \* Version: (.+)$/m',$header,$match)?trim($match[1]):'';
    }
    private static function field($key,$type,$label) {
        return ['id'=>'atshme_basic_'.$key,'key'=>$key,'type'=>$type,'label'=>$label,'required'=>false];
    }
    public static function resolve($context=[]) {
        $first=self::field('first_name','core_first_name',__('First Name','atshift-members'));
        $last=self::field('last_name','core_last_name',__('Last Name','atshift-members'));
        $locale=is_string($context['locale']??null)?$context['locale']:determine_locale();
        $fields=strpos($locale,'ja')===0?[$last,$first]:[$first,$last];
        $fields[]=self::field('display_name','core_display_name',__('Display Name','atshift-members'));
        $fields[]=self::field('description','core_bio',__('Biography','atshift-members'));
        $fields[]=self::field('atshme_phone','text',__('Phone Number','atshift-members'));
        $fields[]=self::field('atshme_postal_code','text',__('Postal Code','atshift-members'));
        $fields[]=self::field('atshme_prefecture','text',__('Prefecture / State','atshift-members'));
        $fields[]=self::field('atshme_city','text',__('City / Municipality','atshift-members'));
        $fields[]=self::field('atshme_street','text',__('Street Address','atshift-members'));
        $fields[]=self::field('atshme_building','text',__('Building / Unit','atshift-members'));
        $optional=[];
        foreach($fields as $field)$optional[]=[
            'id'=>$field['key'],'label'=>$field['label'],
            'description'=>__('Uncheck to omit this field. Existing fields and values are kept.','atshift-members'),
            'default_selected'=>true,'fields'=>[$field],
        ];
        $optional[]=[
            'id'=>'profile-picture','label'=>__('Profile Picture','atshift-members'),
            'description'=>__('Add the profile picture field provided by UPF.','atshift-members'),
            'default_selected'=>true,
            'fields'=>[self::field('profile_picture','core_profile_picture',__('Profile Picture','atshift-members'))],
        ];
        // This is a definition only. Never write Pro options or call its internal classes.
        if(defined('ATSHIFT_UPF_PRO_VERSION')){
            foreach([
                'branch'=>[__('Branch','atshift-members'),true],
                'department'=>[__('Department','atshift-members'),true],
                'membership-type'=>[__('Membership Type','atshift-members'),false],
            ] as $id=>[$label,$hierarchical]){
                $optional[]=[
                    'id'=>$id,'label'=>$label,
                    'description'=>__('Optional member classification. Requires the UPF Pro preset application API.','atshift-members'),
                    'default_selected'=>false,
                    'requires'=>['atshift-user-profile-fields-pro'],
                    'extensions'=>['atshift-user-profile-fields-pro'=>[
                        'classification_group_candidates'=>[['id'=>'atshme_basic_'.str_replace('-','_',$id),'label'=>$label,'hierarchical'=>$hierarchical]],
                    ]],
                ];
            }
        }
        return ['fields'=>[],'optional_components'=>$optional];
    }
}
