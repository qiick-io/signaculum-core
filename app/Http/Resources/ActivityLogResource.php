<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Activity;
use App\Models\ApiKey;
use App\Models\Client;
use App\Models\Organization;
use App\Models\Principal;
use App\Models\User;
use App\Models\UserGroup;
use App\Models\Webhook;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LogicException;

/**
 * An activity log entry for the activity log page, as in qiick-io/excubia-app.
 *
 * @mixin Activity
 */
final class ActivityLogResource extends JsonResource
{
    /**
     * @return array{
     *     id: int,
     *     event: string|null,
     *     description: string,
     *     log_name: string|null,
     *     created_at: string|null,
     *     causer: mixed,
     *     subject: mixed,
     *     changes: array<string, mixed>,
     *     properties: array{ip: mixed, user_agent: mixed, meta: array<string, mixed>}
     * }
     */
    public function toArray(Request $request): array
    {
        /** @var Activity $activity */
        $activity = $this->resource;
        $properties = $activity->properties?->toArray() ?? [];

        return [
            'id' => $activity->id,
            'event' => $activity->event,
            'description' => $activity->description,
            'log_name' => $activity->log_name,
            'created_at' => $activity->created_at?->toIso8601String(),
            'causer' => $this->whenLoaded('causer', fn () => match (true) {
                $activity->causer instanceof User => [
                    'id' => $activity->causer->id,
                    'name' => $activity->causer->name,
                    'email' => $activity->causer->email,
                ],
                // Admin API actions: the key's service account; the key is in properties.meta.
                $activity->causer instanceof Principal => [
                    'id' => $activity->causer->id,
                    'name' => $activity->causer->name,
                    'email' => null,
                ],
                default => null,
            }),
            'subject' => $this->whenLoaded('subject', fn () => $activity->subject !== null ? [
                'type' => class_basename((string) $activity->subject_type),
                // The model's own key type (integer or UUID), not the string column.
                'id' => $activity->subject->getKey(),
                'label' => $this->subjectLabel($activity->subject),
            ] : null),
            'changes' => $activity->attribute_changes?->toArray() ?? [],
            'properties' => [
                'ip' => $properties['ip'] ?? null,
                'user_agent' => $properties['user_agent'] ?? null,
                // Manual activity() events store their payload here, not in attribute_changes.
                'meta' => collect($properties)->except(['ip', 'user_agent'])->all(),
            ],
        ];
    }

    /**
     * A readable label for the subject, chosen explicitly per logged model (#171).
     *
     * Guessing attributes like `name` breaks under Model::shouldBeStrict() for models
     * that lack that column (Webhook). Unknown subjects throw so a new logged model
     * fails a coverage test instead of the activity log page.
     *
     * @throws LogicException when the subject class has no label arm
     */
    private function subjectLabel(Model $subject): string
    {
        $label = match ($subject::class) {
            User::class,
            Client::class,
            ApiKey::class,
            Organization::class,
            UserGroup::class => trim((string) $subject->name),
            // url is the admin-facing identity; secret must never appear in the label.
            Webhook::class => trim((string) $subject->url),
            default => throw new LogicException(
                'No activity subject label for '.$subject::class.'; add an arm in ActivityLogResource::subjectLabel().',
            ),
        };

        return $label !== '' ? $label : '#'.$subject->getKey();
    }
}
