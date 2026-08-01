<?php

declare(strict_types=1);

namespace Ruhrcoder\RcOrderAttachment\Service\Validation;

/**
 * Sammelt Validierungs-Befunde. Fail-fast wäre für UX schlechter — der Customer
 * soll bei einem Upload alle Probleme der Datei auf einmal sehen.
 */
final class ValidationResult
{
    /**
     * @var list<array{code: ValidationCode, context: array<string, scalar>}>
     */
    private array $failures = [];

    /**
     * @param array<string, scalar> $context
     */
    public function fail(ValidationCode $code, array $context = []): void
    {
        $this->failures[] = ['code' => $code, 'context' => $context];
    }

    public function isValid(): bool
    {
        return $this->failures === [];
    }

    /**
     * @return list<array{code: ValidationCode, context: array<string, scalar>}>
     */
    public function getFailures(): array
    {
        return $this->failures;
    }

    public function firstCode(): ?ValidationCode
    {
        return $this->failures[0]['code'] ?? null;
    }

    /**
     * @return list<string>
     */
    public function getCodeValues(): array
    {
        return array_map(static fn (array $failure): string => $failure['code']->value, $this->failures);
    }
}
