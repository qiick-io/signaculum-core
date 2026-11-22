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

];
