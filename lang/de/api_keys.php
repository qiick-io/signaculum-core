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

    'creator_demoted' => [
        'subject' => 'API-Schlüssel :name: Ersteller ist kein Instanz-Admin mehr',
        'intro' => 'Der API-Schlüssel :name (:prefix) auf :app wurde von :creator erstellt, der kein Instanz-Admin mehr ist.',
        'advice' => 'Der Schlüssel funktioniert weiterhin. Widerrufen Sie ihn in der Konsole, wenn ihn niemand mehr verwenden soll.',
        'action' => 'API-Schlüssel prüfen',
    ],

    'creator_deleted' => [
        'subject' => 'API-Schlüssel :name wurde widerrufen',
        'intro' => 'Der API-Schlüssel :name (:prefix) auf :app wurde widerrufen, weil sein Ersteller :creator gelöscht wurde.',
        'advice' => 'Integrationen, die ihn verwendet haben, funktionieren nicht mehr. Erstellen Sie einen neuen Schlüssel, wenn sie weiterhin Zugriff brauchen.',
        'action' => 'API-Schlüssel prüfen',
    ],

];
