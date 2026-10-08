<?php

declare(strict_types=1);

namespace ymwl\think8mcp\Skills\Remote;

class AuditResult
{
    public function __construct(
        public string  $partner,
        public Risk    $risk,
        public ?int    $alerts     = null,
        public ?string $analyzedAt = null,
    ) {}
}
