<?php

declare(strict_types=1);

/**
 * API key mail copy (de). Not an admin-editable template: it names a key, not the instance.
 */
return [

    'expiring' => [
        'subject' => 'Ihr API-Schlüssel :name läuft bald ab',
        'intro' => 'Der API-Schlüssel :name (:prefix) auf :app läuft am :date ab.',
        'advice' => 'Rotieren Sie ihn oder erstellen Sie vorher einen neuen Schlüssel, sonst funktionieren die Integrationen, die ihn verwenden, nicht mehr.',
        'action' => 'API-Schlüssel verwalten',
    ],

];
