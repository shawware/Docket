<?php

// Copyright © 2026 shawware.com.au

declare(strict_types=1);

return [
    'dueSoonWindowDays' => (int) (envValue('DUE_SOON_DAYS') ?? 3),
    'priorityGap' => 1000,
];
