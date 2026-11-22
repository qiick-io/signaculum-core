<?php

declare(strict_types=1);

namespace App\Ldap;

use LdapRecord\Models\Attributes\AccountControl;
use LdapRecord\Models\Model;

/**
 * Generic directory person for read-only LDAP sync (#77).
 *
 * Object classes stay broad so Active Directory and OpenLDAP both match; the
 * instance user filter and attribute map narrow the query. Never written back.
 */
class DirectoryUser extends Model
{
    /**
     * Minimal shared classes for AD and OpenLDAP. Narrower filters live in settings.
     *
     * @var list<string>
     */
    public static array $objectClasses = [
        'top',
        'person',
    ];

    /**
     * Whether Active Directory marks this entry disabled (userAccountControl ACCOUNTDISABLE 0x2).
     *
     * OpenLDAP entries without `userAccountControl` are treated as enabled; their
     * presence in the sync filter is the only enablement signal (#187).
     */
    public function isDirectoryAccountDisabled(): bool
    {
        $control = $this->getFirstAttribute('userAccountControl')
            ?? $this->getFirstAttribute('useraccountcontrol');

        if ($control === null || $control === '') {
            return false;
        }

        return (new AccountControl((int) $control))->hasFlag(AccountControl::ACCOUNTDISABLE);
    }
}
