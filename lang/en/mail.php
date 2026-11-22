<?php

declare(strict_types=1);

/**
 * Default Signaculum transactional email copy (en). Instance admins may override
 * these per locale in Settings → Email templates; empty overrides fall back here.
 */
return [

    'verification' => [
        'subject' => 'Verify your email address',
        'body' => "Please click the button below to verify your email address on {{app_name}}.\n\nIf you did not create an account on Signaculum, you can ignore this email.",
        'action_label' => 'Verify email address',
    ],

    'reset' => [
        'subject' => 'Reset your password',
        'body' => "You are receiving this email because we received a password reset request for your {{app_name}} account.\n\nThis password reset link will expire in {{expire_minutes}} minutes.\n\nIf you did not request a password reset on Signaculum, no further action is required.",
        'action_label' => 'Reset password',
    ],

    'invitation' => [
        'subject' => 'You are invited to {{organization_name}}',
        'body' => "You have been invited to join the organisation {{organization_name}} on {{app_name}}.\n\nThis link expires. If you did not expect this email, you can ignore it.",
        'action_label' => 'Accept invitation',
    ],

    'email_login_code' => [
        'subject' => 'Your sign-in code for {{app_name}}',
        'body' => "Your one-time sign-in code for {{app_name}} is {{code}}.\n\nIt expires in {{expire_minutes}} minutes. If you did not request this code, you can ignore this email.",
        'action_label' => '',
    ],

];
