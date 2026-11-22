<?php

declare(strict_types=1);

/**
 * Default Signaculum transactional email copy (de).
 */
return [

    'verification' => [
        'subject' => 'E-Mail-Adresse bestätigen',
        'body' => "Bitte klicken Sie auf die Schaltfläche unten, um Ihre E-Mail-Adresse bei {{app_name}} zu bestätigen.\n\nWenn Sie kein Konto bei Signaculum erstellt haben, können Sie diese E-Mail ignorieren.",
        'action_label' => 'E-Mail-Adresse bestätigen',
    ],

    'reset' => [
        'subject' => 'Passwort zurücksetzen',
        'body' => "Sie erhalten diese E-Mail, weil wir eine Anfrage zum Zurücksetzen des Passworts für Ihr {{app_name}}-Konto erhalten haben.\n\nDieser Link läuft in {{expire_minutes}} Minuten ab.\n\nWenn Sie kein Zurücksetzen bei Signaculum angefordert haben, ist keine weitere Aktion erforderlich.",
        'action_label' => 'Passwort zurücksetzen',
    ],

    'invitation' => [
        'subject' => 'Sie sind zu {{organization_name}} eingeladen',
        'body' => "Sie wurden eingeladen, der Organisation {{organization_name}} auf {{app_name}} beizutreten.\n\nDieser Link läuft ab. Wenn Sie diese E-Mail nicht erwartet haben, können Sie sie ignorieren.",
        'action_label' => 'Einladung annehmen',
    ],

    'email_login_code' => [
        'subject' => 'Ihr Anmeldecode für {{app_name}}',
        'body' => "Ihr einmaliger Anmeldecode für {{app_name}} lautet {{code}}.\n\nEr läuft in {{expire_minutes}} Minuten ab. Wenn Sie diesen Code nicht angefordert haben, können Sie diese E-Mail ignorieren.",
        'action_label' => '',
    ],

];
