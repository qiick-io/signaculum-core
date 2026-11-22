<?php

declare(strict_types=1);

/**
 * Second-factor change alert (it). Not an admin-editable template: the account owner must be
 * able to recognise it as a security alert whatever the instance customised (#168).
 */
return [

    'changed' => [
        'subject' => 'Un fattore di accesso del tuo account :app è cambiato',
        'intro' => 'Questa modifica è stata appena apportata ai secondi fattori del tuo account :app (:email):',
        'advice' => 'Se la modifica l\'hai fatta tu, non devi fare nulla. Altrimenti qualcuno potrebbe conoscere la tua password: cambiala subito e controlla i tuoi secondi fattori.',
        'action' => 'Controlla le impostazioni di sicurezza',
    ],

    'changes' => [
        'two_factor_enabled' => 'È stata avviata la configurazione dell\'autenticazione a due fattori: è stato creato un nuovo segreto per l\'app di autenticazione.',
        'two_factor_confirmed' => 'Un\'app di autenticazione è stata confermata come secondo fattore.',
        'two_factor_disabled' => 'L\'autenticazione a due fattori con un\'app di autenticazione è stata disattivata.',
        'recovery_codes_regenerated' => 'Sono stati generati nuovi codici di recupero. I codici precedenti non funzionano più.',
        'passkey_registered' => 'È stata aggiunta una passkey.',
        'passkey_deleted' => 'È stata rimossa una passkey.',
    ],

];
