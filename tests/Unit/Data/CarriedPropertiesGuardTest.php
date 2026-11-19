<?php

declare(strict_types=1);

use App\Data\ApplicationData;
use App\Data\Concerns\GuardsCarriedPropertiesTrait;
use App\Data\OrganizationData;

/**
 * #186: the guard that makes a forgotten payload property loud, exercised failing.
 *
 * The named constructors cannot be made to drop a property without editing the Data
 * class itself, so the throw is proved here on a stand-in that has one property the
 * list forgets — the exact mistake `is_device_authorization_enabled` (#110) was.
 */
function carriedPropertiesProbe(): object
{
    return new class('carried', 'forgotten')
    {
        use GuardsCarriedPropertiesTrait;

        public function __construct(
            public string $carried,
            public string $forgotten,
        ) {}

        /**
         * @param  list<string>  $carriedProperties
         */
        public static function guard(array $carriedProperties): void
        {
            self::guardEveryPropertyIsCarried($carriedProperties, 'both test doors');
        }
    };
}

it('throws when a payload property is not carried, naming the property and the doors', function (): void {
    $probe = carriedPropertiesProbe();

    expect(static fn () => $probe::guard(['carried']))->toThrow(LogicException::class);

    $message = null;

    try {
        $probe::guard(['carried']);
    } catch (LogicException $exception) {
        $message = $exception->getMessage();
    }

    // The message has to name what was forgotten and where to carry it, or the author
    // reads a stack trace instead of an instruction.
    expect($message)
        ->toBeString()
        ->toContain('$forgotten')
        ->toContain('both test doors')
        ->not->toContain('$carried');
});

it('says nothing when every payload property is carried', function (): void {
    $probe = carriedPropertiesProbe();

    $probe::guard(['carried', 'forgotten']);

    expect(true)->toBeTrue();
});

it('lets both real payloads build themselves', function (): void {
    expect(ApplicationData::fromValidated(['name' => 'Probe', 'redirect_uris' => ['https://probe.test/cb']]))
        ->toBeInstanceOf(ApplicationData::class)
        ->and(OrganizationData::fromValidated(['name' => 'Probe Corp']))
        ->toBeInstanceOf(OrganizationData::class);
});

it('derives an organisation slug from the name when none is submitted', function (): void {
    expect(OrganizationData::fromValidated(['name' => 'Probe Corp'])->slug)->toBe('probe-corp')
        ->and(OrganizationData::fromValidated(['name' => 'Probe Corp', 'slug' => ''])->slug)->toBe('probe-corp')
        ->and(OrganizationData::fromValidated(['name' => 'Probe Corp', 'slug' => 'chosen'])->slug)->toBe('chosen');
});

it('reads a redirect URI list the same way from a textarea and from a JSON array', function (mixed $submitted): void {
    expect(ApplicationData::parseRedirectUris($submitted))->toBe([
        'https://probe.test/cb',
        'https://probe.test/other',
    ]);
})->with([
    'the admin textarea' => ["https://probe.test/cb\n\n  https://probe.test/other  \nhttps://probe.test/cb\n"],
    'the API JSON array' => [['https://probe.test/cb', ' https://probe.test/other ', 'https://probe.test/cb']],
    // A JSON array can hold anything; only strings are URIs.
    'a JSON array with a stray non-string' => [['https://probe.test/cb', 42, null, 'https://probe.test/other']],
]);

it('reads no redirect URIs from an empty or unusable submission', function (mixed $submitted): void {
    expect(ApplicationData::parseRedirectUris($submitted))->toBe([]);
})->with([
    'nothing' => [null],
    'an empty textarea' => ['   '],
    'an empty array' => [[]],
]);
