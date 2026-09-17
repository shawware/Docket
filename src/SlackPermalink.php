<?php

// Copyright © 2026 shawware.com.au

declare(strict_types=1);

namespace Shawware\Docket;

/**
 * A Slack permalink (`https://workspace.slack.com/archives/C1/p169999...`)
 * encodes the message's timestamp in the `p<digits>` segment — the first
 * 10 digits are Unix seconds, the rest are microseconds. This lets us
 * recover a message's real creation time from any permalink, whether it
 * was constructed by handleMessageShortcut() or pasted in by hand, with
 * no Slack API call.
 */
final class SlackPermalink
{
    /**
     * Null for anything that isn't a recognizable Slack permalink — a
     * different URL entirely, or free text someone typed into the Link
     * field. Callers fall back to some other timestamp in that case.
     */
    public static function messageTimestamp(string $permalink): ?\DateTimeImmutable
    {
        if (preg_match('#/archives/[^/]+/p(\d{10})\d*#', $permalink, $matches) !== 1) {
            return null;
        }

        return (new \DateTimeImmutable())->setTimestamp((int) $matches[1]);
    }

    /**
     * Builds a link back to Docket's own pinned list message, from the
     * `channel_id`/`message_ts` already in storage — no `chat.getPermalink`
     * call needed. Uses the generic `slack.com` host, which auto-redirects
     * a logged-in user to their own workspace; this is only ever clicked
     * from inside Slack, unlike a real message permalink shared outside it.
     */
    public static function forMessage(string $channelId, string $messageTs): string
    {
        return 'https://slack.com/archives/' . $channelId . '/p' . str_replace('.', '', $messageTs);
    }
}
