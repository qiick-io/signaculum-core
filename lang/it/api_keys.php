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

];
