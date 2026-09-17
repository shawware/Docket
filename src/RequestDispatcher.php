<?php

// Copyright © 2026 shawware.com.au

declare(strict_types=1);

namespace Shawware\Docket;

/**
 * Owns the try/catch and response-shaping for each of Docket's three
 * Slack routes, given an already-decoded payload — `public/index.php`
 * only does bootstrap, signature verification, and raw-body decoding,
 * then applies whatever DispatchResult comes back. Pulling this logic
 * out of a plain script and into a class is what makes the
 * catch-and-respond behavior unit-testable at all.
 */
final class RequestDispatcher
{
    public function __construct(private readonly Router $router)
    {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function dispatchEvent(array $payload): DispatchResult
    {
        try {
            $challenge = $this->router->handleEvent($payload);
        } catch (\Throwable $e) {
            error_log('[Docket] handleEvent threw: ' . $e->getMessage());

            return new DispatchResult(200);
        }

        if ($challenge !== null) {
            return new DispatchResult(200, $challenge, 'text/plain');
        }

        return new DispatchResult(200);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function dispatchInteraction(array $payload): DispatchResult
    {
        $type = $payload['type'] ?? null;

        if ($type === 'view_submission') {
            try {
                $result = $this->router->handleViewSubmission($payload);
            } catch (\Throwable $e) {
                // A non-200 here is deliberate: Slack shows the user its
                // own "trouble connecting" error and leaves the modal
                // open. That's an honest failure signal — returning 200
                // would tell Slack to close the modal as if the
                // submission succeeded, when it didn't, leaving the user
                // with no idea anything went wrong.
                error_log('[Docket] handleViewSubmission threw: ' . $e->getMessage());

                return new DispatchResult(500);
            }

            return new DispatchResult(200, json_encode($result ?? new \stdClass()), 'application/json');
        }

        if ($type === 'message_action') {
            try {
                $this->router->handleMessageShortcut($payload);
            } catch (\Throwable $e) {
                error_log('[Docket] handleMessageShortcut threw: ' . $e->getMessage());
            }

            return new DispatchResult(200);
        }

        try {
            $this->router->handleBlockAction($payload);
        } catch (\Throwable $e) {
            error_log('[Docket] handleBlockAction threw: ' . $e->getMessage());
        }

        return new DispatchResult(200);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function dispatchCommand(array $payload): DispatchResult
    {
        try {
            $result = $this->router->handleSlashCommand($payload);
        } catch (\Throwable $e) {
            error_log('[Docket] handleSlashCommand threw: ' . $e->getMessage());

            // Slack's own fallback for a failed slash command ("failed
            // with the error 'dispatch_failed'") is more confusing than
            // useful — a plain 200 with our own ephemeral message reads
            // better and still tells the user to just try again.
            return new DispatchResult(
                200,
                json_encode(['response_type' => 'ephemeral', 'text' => 'Something went wrong. Please try again.']),
                'application/json'
            );
        }

        return new DispatchResult(200, json_encode($result), 'application/json');
    }
}
