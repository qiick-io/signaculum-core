<?php

declare(strict_types=1);

/**
 * API key mail copy (it). Not an admin-editable template: it names a key, not the instance.
 */
return [

    'expiring' => [
        'subject' => 'La tua chiave API :name sta per scadere',
        'intro' => 'La chiave API :name (:prefix) su :app scade il :date.',
        'advice' => 'Ruotala o crea una nuova chiave prima di allora, altrimenti le integrazioni che la usano smetteranno di funzionare.',
        'action' => 'Gestisci chiavi API',
    ],

    'creator_demoted' => [
        'subject' => 'Chiave API :name: il suo creatore non è più admin di istanza',
        'intro' => 'La chiave API :name (:prefix) su :app è stata creata da :creator, che non è più admin di istanza.',
        'advice' => 'La chiave funziona ancora. Revocala dalla console se nessuno deve più usarla.',
        'action' => 'Rivedi chiavi API',
    ],

    'creator_deleted' => [
        'subject' => 'La chiave API :name è stata revocata',
        'intro' => 'La chiave API :name (:prefix) su :app è stata revocata perché il suo creatore :creator è stato cancellato.',
        'advice' => 'Le integrazioni che la usavano non funzionano più. Crea una nuova chiave se serve ancora l’accesso.',
        'action' => 'Rivedi chiavi API',
    ],

];
