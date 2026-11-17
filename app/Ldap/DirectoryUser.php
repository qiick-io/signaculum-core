<?php

declare(strict_types=1);

namespace App\Ldap;

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
}
