<?php

declare(strict_types=1);

/**
 * API key mail copy (en). Not an admin-editable template: it names a key, not the instance.
 */
return [

    'expiring' => [
        'subject' => 'Your API key :name expires soon',
        'intro' => 'The API key :name (:prefix) on :app expires on :date.',
        'advice' => 'Rotate it or create a new key before then, or the integrations that use it will stop working.',
        'action' => 'Manage API keys',
    ],

    'creator_demoted' => [
        'subject' => 'API key :name: its creator is no longer an instance admin',
        'intro' => 'The API key :name (:prefix) on :app was created by :creator, who is no longer an instance admin.',
        'advice' => 'The key still works. Revoke it in the console if nobody should use it any more.',
        'action' => 'Review API keys',
    ],

    'creator_deleted' => [
        'subject' => 'API key :name was revoked',
        'intro' => 'The API key :name (:prefix) on :app was revoked because its creator :creator was deleted.',
        'advice' => 'Integrations that used it no longer work. Create a new key if they still need access.',
        'action' => 'Review API keys',
    ],

];
