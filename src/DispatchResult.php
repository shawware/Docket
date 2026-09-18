<?php

// Copyright © 2026 shawware.com.au

declare(strict_types=1);

namespace Shawware\Docket;

/**
 * What a route handler wants sent back to Slack — the HTTP status, body,
 * and (when there's a body) its content type. `public/index.php` just
 * applies this mechanically; all the decision-making about what to
 * return on success vs. failure lives in RequestDispatcher, where it's
 * unit-testable.
 */
final class DispatchResult
{
    public function __construct(
        public readonly int $status,
        public readonly string $body = '',
        public readonly ?string $contentType = null
    ) {}
}
