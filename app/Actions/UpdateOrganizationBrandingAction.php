<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\Organization;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Saves per-organisation logo files and brand colours for login/consent overlays.
 *
 * @side-effect Writes/deletes files under `branding/organizations/{id}/` on the local disk.
 */
final class UpdateOrganizationBrandingAction
{
    /**
     * @param  array{brand_color: string|null, brand_color_dark: string|null}  $colours
     * @param  array{logo?: UploadedFile, logo_dark?: UploadedFile}  $uploads
     * @param  list<'logo'|'logo_dark'>  $removals
     */
    public function execute(Organization $organization, array $colours, array $uploads, array $removals): Organization
    {
        $attributes = [
            'brand_color' => $colours['brand_color'],
            'brand_color_dark' => $colours['brand_color_dark'],
        ];

        if (isset($uploads['logo']) || in_array('logo', $removals, true)) {
            $this->deletePath($organization->logo_path);
            $attributes['logo_path'] = isset($uploads['logo'])
                ? $this->store($organization, $uploads['logo'])
                : null;
        }

        if (isset($uploads['logo_dark']) || in_array('logo_dark', $removals, true)) {
            $this->deletePath($organization->logo_dark_path);
            $attributes['logo_dark_path'] = isset($uploads['logo_dark'])
                ? $this->store($organization, $uploads['logo_dark'])
                : null;
        }

        $organization->forceFill($attributes)->save();

        return $organization->refresh();
    }

    /**
     * @throws RuntimeException when the disk refuses the file
     */
    private function store(Organization $organization, UploadedFile $upload): string
    {
        $directory = 'branding/organizations/'.$organization->getKey();
        $path = $upload->storeAs($directory, Str::random(20).'.'.$upload->extension(), 'local');

        if ($path === false) {
            throw new RuntimeException('The organisation branding file could not be stored.');
        }

        return $path;
    }

    private function deletePath(mixed $path): void
    {
        if (is_string($path) && $path !== '') {
            Storage::disk('local')->delete($path);
        }
    }
}
