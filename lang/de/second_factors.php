<?php

declare(strict_types=1);

/**
 * Second-factor change alert (de). Not an admin-editable template: the account owner must be
 * able to recognise it as a security alert whatever the instance customised (#168).
 */
return [

    'changed' => [
        'subject' => 'Ein Anmeldefaktor Ihres :app-Kontos wurde geändert',
        'intro' => 'Diese Änderung wurde gerade an den zweiten Faktoren Ihres :app-Kontos (:email) vorgenommen:',
        'advice' => 'Wenn Sie die Änderung selbst vorgenommen haben, müssen Sie nichts tun. Andernfalls kennt möglicherweise jemand Ihr Passwort: Ändern Sie es sofort und prüfen Sie Ihre zweiten Faktoren.',
        'action' => 'Sicherheitseinstellungen prüfen',
    ],

    'changes' => [
        'two_factor_enabled' => 'Die Einrichtung der Zwei-Faktor-Authentifizierung wurde gestartet: Ein neues Authenticator-Geheimnis wurde erstellt.',
        'two_factor_confirmed' => 'Eine Authenticator-App wurde als zweiter Faktor bestätigt.',
        'two_factor_disabled' => 'Die Zwei-Faktor-Authentifizierung per Authenticator-App wurde deaktiviert.',
        'recovery_codes_regenerated' => 'Neue Wiederherstellungscodes wurden erstellt. Die bisherigen Codes funktionieren nicht mehr.',
        'passkey_registered' => 'Ein Passkey wurde hinzugefügt.',
        'passkey_deleted' => 'Ein Passkey wurde entfernt.',
    ],

];
