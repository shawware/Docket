<?php

// Copyright © 2026 shawware.com.au

declare(strict_types=1);

return [
    'dueSoonWindowDays' => (int) (envValue('DUE_SOON_DAYS') ?? 3),
    'sourceLinkMaxAgeDays' => (int) (envValue('SOURCE_LINK_MAX_AGE_DAYS') ?? 90),
    'priorityGap' => 1000,
];
