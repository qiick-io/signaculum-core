<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\EmailTemplateData;
use App\Enums\EmailTemplateTypeEnum;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Lang;

/**
 * Resolves transactional email templates: Signaculum-branded locale defaults from
 * lang/{locale}/mail.php, overlaid by instance-admin overrides in settings (email_templates).
 * Empty override fields fall back to the default for that locale (then English).
 */
final class EmailTemplateService
{
    public function __construct(
        private readonly SettingsRepositoryService $settings,
    ) {}

    /**
     * Packaged default for one type and locale (English when the locale file is missing).
     */
    public function defaultFor(EmailTemplateTypeEnum $type, string $locale): EmailTemplateData
    {
        $locale = $this->normalizeLocale($locale);
        $key = 'mail.'.$type->value;

        $subject = Lang::get($key.'.subject', [], $locale);
        $body = Lang::get($key.'.body', [], $locale);
        $actionLabel = Lang::get($key.'.action_label', [], $locale);

        if (! is_string($subject) || $subject === $key.'.subject') {
            $subject = (string) Lang::get($key.'.subject', [], 'en');
        }

        if (! is_string($body) || $body === $key.'.body') {
            $body = (string) Lang::get($key.'.body', [], 'en');
        }

        if (! is_string($actionLabel) || $actionLabel === $key.'.action_label') {
            $actionLabel = (string) Lang::get($key.'.action_label', [], 'en');
        }

        return new EmailTemplateData($subject, $body, $actionLabel);
    }

    /**
     * Effective template: saved override fields win; blank fields use the default.
     */
    public function resolve(EmailTemplateTypeEnum $type, string $locale): EmailTemplateData
    {
        $locale = $this->normalizeLocale($locale);
        $default = $this->defaultFor($type, $locale);
        $override = $this->overrideFor($type, $locale);

        return new EmailTemplateData(
            subject: $this->nonEmpty($override['subject'] ?? null) ?? $default->subject,
            body: $this->nonEmpty($override['body'] ?? null) ?? $default->body,
            actionLabel: $this->nonEmpty($override['action_label'] ?? null) ?? $default->actionLabel,
        );
    }

    /**
     * Builds a Laravel mail message from the resolved template and placeholders.
     *
     * @param  array<string, string|int>  $placeholders
     */
    public function mailMessage(
        EmailTemplateTypeEnum $type,
        string $locale,
        array $placeholders,
    ): MailMessage {
        $template = $this->resolve($type, $locale);
        $subject = $this->replacePlaceholders($template->subject, $placeholders);
        $body = $this->replacePlaceholders($template->body, $placeholders);
        $actionLabel = $this->replacePlaceholders($template->actionLabel, $placeholders);
        $url = isset($placeholders['url']) ? (string) $placeholders['url'] : '';

        $message = (new MailMessage)->subject($subject);

        foreach ($this->bodyParagraphs($body) as $paragraph) {
            $message->line($paragraph);
        }

        if ($url !== '' && $actionLabel !== '') {
            $message->action($actionLabel, $url);
        }

        return $message;
    }

    /**
     * Effective templates for the settings form (defaults merged with overrides).
     *
     * @return array<string, array<string, array{subject: string, body: string, action_label: string}>>
     */
    public function forEdit(): array
    {
        return $this->mapAllTypesAndLocales(
            fn (EmailTemplateTypeEnum $type, string $locale): array => $this->resolve($type, $locale)->toArray(),
        );
    }

    /**
     * Packaged defaults only (so the UI can restore / show “customised”).
     *
     * @return array<string, array<string, array{subject: string, body: string, action_label: string}>>
     */
    public function defaultsForEdit(): array
    {
        return $this->mapAllTypesAndLocales(
            fn (EmailTemplateTypeEnum $type, string $locale): array => $this->defaultFor($type, $locale)->toArray(),
        );
    }

    /**
     * Placeholder names per template type for the settings UI.
     *
     * @return array<string, list<string>>
     */
    public function placeholdersForEdit(): array
    {
        $placeholders = [];

        foreach (EmailTemplateTypeEnum::cases() as $type) {
            $placeholders[$type->value] = $type->placeholders();
        }

        return $placeholders;
    }

    /**
     * Normalises a form payload into stored overrides: omit fields that match the default
     * so empty/default installs keep falling back to lang/{locale}/mail.php.
     *
     * @param  array<string, mixed>  $templates
     * @return array<string, array<string, array{subject: string, body: string, action_label: string}>>
     */
    public function overridesFromForm(array $templates): array
    {
        $overrides = [];

        foreach (EmailTemplateTypeEnum::cases() as $type) {
            $typeKey = $type->value;
            $perLocale = is_array($templates[$typeKey] ?? null) ? $templates[$typeKey] : [];

            foreach ($this->availableLocales() as $locale) {
                $incoming = is_array($perLocale[$locale] ?? null) ? $perLocale[$locale] : [];
                $default = $this->defaultFor($type, $locale);
                $subject = $this->trimString($incoming['subject'] ?? null);
                $body = $this->trimString($incoming['body'] ?? null);
                $actionLabel = $this->trimString($incoming['action_label'] ?? null);

                $stored = [
                    'subject' => $subject !== null && $subject !== $default->subject ? $subject : '',
                    'body' => $body !== null && $body !== $default->body ? $body : '',
                    'action_label' => $actionLabel !== null && $actionLabel !== $default->actionLabel ? $actionLabel : '',
                ];

                if ($stored['subject'] !== '' || $stored['body'] !== '' || $stored['action_label'] !== '') {
                    $overrides[$typeKey][$locale] = $stored;
                }
            }
        }

        return $overrides;
    }

    /**
     * @return array<string, mixed>
     */
    private function overrideFor(EmailTemplateTypeEnum $type, string $locale): array
    {
        $all = $this->settings->all()['email_templates'] ?? [];

        if (! is_array($all)) {
            return [];
        }

        $forType = $all[$type->value] ?? [];

        if (! is_array($forType)) {
            return [];
        }

        $forLocale = $forType[$locale] ?? [];

        return is_array($forLocale) ? $forLocale : [];
    }

    /**
     * @param  callable(EmailTemplateTypeEnum, string): array{subject: string, body: string, action_label: string}  $mapper
     * @return array<string, array<string, array{subject: string, body: string, action_label: string}>>
     */
    private function mapAllTypesAndLocales(callable $mapper): array
    {
        $result = [];

        foreach (EmailTemplateTypeEnum::cases() as $type) {
            foreach ($this->availableLocales() as $locale) {
                $result[$type->value][$locale] = $mapper($type, $locale);
            }
        }

        return $result;
    }

    /**
     * @param  array<string, string|int>  $placeholders
     */
    private function replacePlaceholders(string $text, array $placeholders): string
    {
        $replacements = [];

        foreach ($placeholders as $name => $value) {
            $replacements['{{'.$name.'}}'] = (string) $value;
        }

        return strtr($text, $replacements);
    }

    /**
     * @return list<string>
     */
    private function bodyParagraphs(string $body): array
    {
        $paragraphs = preg_split("/\n\s*\n/", trim($body)) ?: [];

        return array_values(array_filter(
            array_map(static fn (string $paragraph): string => trim($paragraph), $paragraphs),
            static fn (string $paragraph): bool => $paragraph !== '',
        ));
    }

    private function normalizeLocale(string $locale): string
    {
        $locale = strtolower(substr($locale, 0, 2));

        return in_array($locale, $this->availableLocales(), true) ? $locale : 'en';
    }

    /**
     * @return list<string>
     */
    private function availableLocales(): array
    {
        return array_keys((array) config('i18n.available_locales', ['en' => 'English']));
    }

    private function nonEmpty(mixed $value): ?string
    {
        return $this->trimString($value);
    }

    private function trimString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
