<?php
namespace Atshift\Membership;
defined('ABSPATH') || exit;
final class Mail {
    public static function defaults() {
        return apply_filters('atshift_members_mail_defaults', [
            'verify'=>['label'=>__('Registration Email Verification', 'atshift-members'),'subject'=>__('[{site}] Confirm your email address', 'atshift-members'),'body'=>__('To continue registration, open the following link within 30 minutes and click the confirmation button.
{link}

If you did not request this, please ignore this email.', 'atshift-members')],
            'invite'=>['label'=>__('Site Operator Invitation', 'atshift-members'),'subject'=>__('[{site}] Invitation to become a site operator', 'atshift-members'),'body'=>__('You have been invited to become a site operator. Open the link within 30 minutes to complete registration.
{link}

If you were not expecting this invitation, do not proceed. Please contact the site administrator.', 'atshift-members')],
            'welcome'=>['label'=>__('Registration Complete', 'atshift-members'),'subject'=>__('[{site}] Your registration is complete', 'atshift-members'),'body'=>__('Your registration is complete.
Account information: {link}', 'atshift-members')],
            'pending'=>['label'=>__('Pending Approval', 'atshift-members'),'subject'=>__('[{site}] Your account is awaiting review', 'atshift-members'),'body'=>__('Your account is awaiting administrator review. We will let you know when it is ready to use.', 'atshift-members')],
            'activated'=>['label'=>__('Approval or Access Restored', 'atshift-members'),'subject'=>__('[{site}] Your account is ready to use', 'atshift-members'),'body'=>__('Your account is now ready to use.
{link}', 'atshift-members')],
            'withdrawn'=>['label'=>__('Account Closed', 'atshift-members'),'subject'=>__('[{site}] Your account has been closed', 'atshift-members'),'body'=>__('Your account has been closed, and your account data and content selected for deletion have been removed. Any posts and attachments you chose to transfer are retained under the site operator\'s ownership.

For files shared with other users, only your own records have been deleted. Data stored by other services, unsupported plugins, or in backups is not deleted as part of this account closure. Please contact the site operator for details about how that data is handled.', 'atshift-members')],
            'banned'=>['label'=>__('Account Banned', 'atshift-members'),'subject'=>__('[{site}] Your account access has been revoked', 'atshift-members'),'body'=>__('Your account is no longer permitted to access this site. Your account and records have been retained. Please contact the site administrator for details.', 'atshift-members')],
            'suspended'=>['label'=>__('Account Suspended', 'atshift-members'),'subject'=>__('[{site}] Your account has been suspended', 'atshift-members'),'body'=>__('Your account has been suspended. Please contact the site administrator for details.', 'atshift-members')],
            'reset'=>['label'=>__('Reset Password', 'atshift-members'),'subject'=>__('[{site}] Reset your password', 'atshift-members'),'body'=>__('A password reset was requested for your account. Follow this link to reset it.
{link}

If you did not request this, please ignore this email.', 'atshift-members')],
            'password_changed'=>['label'=>__('Password Change Notification', 'atshift-members'),'subject'=>__('[{site}] Your password has changed', 'atshift-members'),'body'=>__('Your password has changed. If you did not make this change, contact the site administrator immediately.', 'atshift-members')],
            'email_verify'=>['label'=>__('New Email Address Verification', 'atshift-members'),'subject'=>__('[{site}] Confirm your new email address', 'atshift-members'),'body'=>__('Open the following link within 30 minutes to confirm your email address change. You will need to log in.
{link}', 'atshift-members')],
            'email_changed'=>['label'=>__('Email Change Notification (Old and New Addresses)', 'atshift-members'),'subject'=>__('[{site}] Your email address has changed', 'atshift-members'),'body'=>__('Your account email address has changed. If you did not make this change, please contact the site administrator.', 'atshift-members')],
        ]);
    }
    public static function with_signature($body) {
        $signature=get_option('asm_mail_signature','');
        $signature=is_string($signature)?trim($signature):'';
        $signature=strtr($signature,['{site}'=>wp_specialchars_decode(get_bloginfo('name'),ENT_QUOTES)]);
        return $signature===''?$body:rtrim($body)."\n\n".$signature;
    }
    public static function send($type,$to,$link='',$identify=true,$context=[]) {
        $defaults=self::defaults();
        if (!isset($defaults[$type])) return false;
        $saved=get_option('asm_mail_templates',[]);
        $template=$saved[$type]??$defaults[$type];
        $replace=['{site}'=>wp_specialchars_decode(get_bloginfo('name'),ENT_QUOTES),'{link}'=>$link?:Screens::url('account')];
        foreach($context as $key=>$value)if(is_string($key)&&is_scalar($value)&&!isset($replace[$key]))$replace[$key]=(string)$value;
        $subject=strtr($template['subject'],$replace);
        $body=self::with_signature(strtr($template['body'],$replace));
        $sender=get_option('asm_sender_name','');
        $filter=static function($name) use($sender) { return $sender?:$name; };
        add_filter('wp_mail_from_name',$filter);
        try { $ok=wp_mail($to,$subject,$body); }
        finally { remove_filter('wp_mail_from_name',$filter); }
        atshift_members()->store->log($ok?'mail_sent':'mail_failed',$identify?$type.'|'.$to:'');
        return $ok;
    }
}
