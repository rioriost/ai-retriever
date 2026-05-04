<?php
declare(strict_types=1);

namespace WPRetriever\Provider;

final class RetrieveResult
{
    /** @param ResultHit[] $hits */
    public function __construct(
        public bool $ok,
        public array $hits = [],
        public ?string $error = null,
    ) {}

    /** @param ResultHit[] $hits */
    public static function success(array $hits): self
    {
        return new self(true, $hits);
    }
    public static function failure(string $error): self
    {
        return new self(false, [], $error);
    }

    /** @return int[] */
    public function post_ids(): array
    {
        return array_map(
            static fn(ResultHit $hit): int => $hit->post_id,
            $this->hits,
        );
    }
}
