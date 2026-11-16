<?php

declare(strict_types=1);

/**
 * Default Signaculum transactional email copy (it).
 */
return [

    'verification' => [
        'subject' => 'Verifica il tuo indirizzo email',
        'body' => "Fai clic sul pulsante qui sotto per verificare il tuo indirizzo email su {{app_name}}.\n\nSe non hai creato un account su Signaculum, puoi ignorare questa email.",
        'action_label' => 'Verifica indirizzo email',
    ],

    'reset' => [
        'subject' => 'Reimposta la password',
        'body' => "Ricevi questa email perché abbiamo ricevuto una richiesta di reimpostazione della password per il tuo account {{app_name}}.\n\nQuesto collegamento scade tra {{expire_minutes}} minuti.\n\nSe non hai richiesto la reimpostazione su Signaculum, non è necessaria alcuna azione.",
        'action_label' => 'Reimposta password',
    ],

    'invitation' => [
        'subject' => 'Sei invitato a {{organization_name}}',
        'body' => "Sei stato invitato a unirti all'organizzazione {{organization_name}} su {{app_name}}.\n\nQuesto collegamento scade. Se non ti aspettavi questa email, puoi ignorarla.",
        'action_label' => 'Accetta invito',
    ],

    'email_login_code' => [
        'subject' => 'Il tuo codice di accesso per {{app_name}}',
        'body' => "Il tuo codice monouso per {{app_name}} è {{code}}.\n\nScade tra {{expire_minutes}} minuti. Se non hai richiesto questo codice, puoi ignorare l'email.",
        'action_label' => '',
    ],

];
