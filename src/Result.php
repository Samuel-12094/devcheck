<?php

declare(strict_types=1);

namespace DevCheck;

/**
 * Resultat unitaire d'un test.
 *
 * status : ok | warn | ko | skip | info
 */
final class Result
{
    public const OK   = 'ok';
    public const WARN = 'warn';
    public const KO   = 'ko';
    public const SKIP = 'skip';
    public const INFO = 'info';

    private const RANK = [self::KO => 0, self::WARN => 1, self::SKIP => 2, self::OK => 3, self::INFO => 4];

    /**
     * @param array<string,mixed> $meta
     */
    public function __construct(
        public readonly string $category,
        public readonly string $label,
        public readonly string $status,
        public readonly string $value = '',
        public readonly string $detail = '',
        public readonly ?string $fix = null,
        public readonly array $meta = [],
    ) {
    }

    public function severity(): int
    {
        return self::RANK[$this->status] ?? 5;
    }

    public function isFailure(): bool
    {
        return $this->status === self::KO;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'category' => $this->category,
            'label'    => $this->label,
            'status'   => $this->status,
            'value'    => $this->value,
            'detail'   => $this->detail,
            'fix'      => $this->fix,
            'meta'     => $this->meta,
        ];
    }
}
