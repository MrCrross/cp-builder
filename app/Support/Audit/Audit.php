<?php

namespace App\Support\Audit;

use App\Models\AuditEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use InvalidArgumentException;

/**
 * Единая точка записи audit-событий (план §9.6).
 *
 * Правила:
 * - только известные действия из ALLOWED_ATTRIBUTES — неаудируемая мутация
 *   падает с исключением, а не проходит молча;
 * - before/after фильтруются allowlist'ом атрибутов действия;
 * - значения чувствительных ключей (password, token, secret, …) редятся
 *   на [redacted] на любом уровне вложенности;
 * - request_id подставляется из текущего HTTP-запроса автоматически.
 */
final class Audit
{
    /**
     * Действие => разрешённые ключи before/after.
     *
     * @var array<string, list<string>>
     */
    private const ALLOWED_ATTRIBUTES = [
        'user.created' => ['name', 'email', 'roles'],
        'user.updated' => ['name', 'roles'],
        'user.deactivated' => ['email'],
        'user.activated' => ['email'],
        'user.password-reset-completed' => ['email'],
        'organization-settings.updated' => ['name', 'legal_name', 'city', 'website', 'slogan'],
        'branding.logo-uploaded' => ['kind', 'mime', 'bytes', 'width', 'height'],
        'branding.logo-deleted' => ['kind'],
        'proposal.created' => ['template', 'title', 'project'],
        'proposal.new-draft' => ['version'],
        'proposal.issued' => ['number', 'version'],
        'proposal.archived' => ['number'],
        'proposal.restored' => ['number'],
        'proposal.link-rotated' => ['number'],
        'proposal.deleted' => ['title'],
        'proposal.catalog-refreshed' => ['version', 'updated'],
        'project.created' => ['name'],
        'project.updated' => ['name'],
        'project.deleted' => ['name'],
        'catalog-category.created' => ['name'],
        'catalog-category.updated' => ['name'],
        'catalog-category.deleted' => ['name'],
        'catalog-item.created' => ['name', 'category', 'price'],
        'catalog-item.updated' => ['name', 'category', 'price', 'active'],
        'catalog-item.deleted' => ['name'],
        'import.uploaded' => ['profile', 'files', 'size'],
        'import.confirmed' => ['rows'],
        'import.applied' => ['created', 'updated', 'deactivated', 'errors'],
        'import.cancelled' => ['profile'],
        'template.created' => ['name'],
        'template.updated' => ['name'],
        'template.deleted' => ['name'],
    ];

    /**
     * Список действий для фильтров read API журнала аудита.
     *
     * @return list<string>
     */
    public static function actions(): array
    {
        return array_keys(self::ALLOWED_ATTRIBUTES);
    }

    /**
     * Ключи, значения которых никогда не записываются в audit.
     */
    private const SENSITIVE_KEY_PATTERN = '/passw|token|secret|authorization|cookie|_key/i';

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public static function record(
        ?User $actor,
        string $action,
        ?Model $subject = null,
        array $before = [],
        array $after = [],
    ): AuditEntry {
        if (! array_key_exists($action, self::ALLOWED_ATTRIBUTES)) {
            throw new InvalidArgumentException("Неизвестное audit-действие: {$action}");
        }

        $allowed = self::ALLOWED_ATTRIBUTES[$action];

        return AuditEntry::query()->create([
            'actor_id' => $actor?->id,
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'request_id' => self::currentRequestId(),
            'before' => self::filter($before, $allowed),
            'after' => self::filter($after, $allowed),
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<string>  $allowed
     * @return array<string, mixed>
     */
    private static function filter(array $attributes, array $allowed): array
    {
        $filtered = Arr::only($attributes, $allowed);

        return self::redact($filtered);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private static function redact(array $attributes): array
    {
        foreach ($attributes as $key => $value) {
            if (is_array($value)) {
                $attributes[$key] = self::redact($value);

                continue;
            }

            if (preg_match(self::SENSITIVE_KEY_PATTERN, (string) $key) === 1) {
                $attributes[$key] = '[redacted]';
            }
        }

        return $attributes;
    }

    private static function currentRequestId(): ?string
    {
        if (! app()->bound('request')) {
            return null;
        }

        $request = request();

        $requestId = $request->attributes->get('request_id');

        return is_string($requestId) ? $requestId : null;
    }
}
