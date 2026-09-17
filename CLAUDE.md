# Docket — Slack team backlog app

## Context

Docket is a new Slack app. It works in one free Slack workspace. It gives each channel a prioritized, pinned task list. Tasks have real assignees. The app pushes visibility to people. It also gives each person a "My Tasks" view.

Earlier investigation ruled out Slack's free-plan-friendly native options. **Slack Lists** has assignee and priority fields, but it is not available on the free plan. **Canvas checklists** support drag-and-drop reordering, but they have no structured assignee field — only a typed `@mention`, with no way to query "my tasks." A custom app is needed to get a real assignee field, plus add-from-anywhere, reminders, and a weekly digest.

The list lives as **one bot-maintained message, pinned per channel**. We chose this over an App Home tab because it is visible to the whole team by default. We chose it over Canvas because Canvas cannot hold a real assignee field. The one thing this gives up, compared to Canvas or Slack Lists, is native drag-and-drop. Priority changes instead use ▲/▼ buttons per row.

Priority stays a single, always-authoritative **manual rank** (▲/▼). There is one list, one order, and no competing sort to reconcile. Two things sit on top of this rank. Both are purely informational. Both are computed at render time. Neither one ever changes the rank. An **Important** flag (⭐, on or off) badges a row without moving it. Two callouts appear at the top of the pinned message: an **"⚠️ Overdue" callout**, listing anything past its due date, most-recently-overdue first; and a **"⏰ Due soon" callout**, listing anything due within a few days but not yet overdue, nearest first. This way, a near-due or overdue task is never silently buried at the bottom of a long list. The ranked list below stays untouched, exactly as people left it with ▲/▼.

**Hosting target: shared PHP/MySQL hosting** (SSH, Composer, git, cron, free HTTPS). This fits Slack's webhook-based integration model well. The Events API, Interactivity, and Slash Commands are just HTTPS POST callbacks. No persistent process or Socket Mode connection is needed. Cron covers the weekly digest.

### Hosting & deployment

- **The document root points at `public/`.** Only `public/index.php` is web-exposed. `.env`, `vendor/`, migrations, and all other code sit outside the document root. They are never reachable by URL, regardless of routing logic.
- **Config loads from `.env`** (via `vlucas/phpdotenv`) through a thin `envValue(string $name): ?string` helper. This helper checks `$_ENV`, then `$_SERVER`, then falls back to `getenv()`. Some shared hosts disable PHP's `putenv()` for security. This silently breaks `getenv()` for values that loaded correctly into `$_ENV`. Reading `$_ENV` directly sidesteps that problem, regardless of whether a given host allows `putenv()`.
- **Deploy is `git pull` plus `composer install` over SSH.** There is no build step and no container. `php bin/migrate.php` applies schema migrations. It is safe to re-run, because already-applied migrations are skipped, using a tracking table.
- **Any unrecognized path** (including `/` and `/robots.txt`) returns a static placeholder page, with HTTP 200. There is no redirect, because not all shared-host panels support a clean same-URL redirect. There is no diagnostic information, and no DB connection is triggered by bots probing random paths.
- **There is one cron entry**, for the weekly digest script.

## Decisions

- **Single workspace only.** One bot token lives in `.env`. There is no OAuth install flow and no per-workspace credential storage.
- **One-off reminders use Slack's native `reminders.add`**, not a custom mechanism. The 2023 API change retired the *user-token* path for reminding someone else. The *bot-token* path (`reminders:read` plus `reminders:write` scopes) still works for exactly this use case: reminding another user. This matches the spec literally, and it is simpler — one API call on modal submit, no reminders table, and no polling cron.
- **The weekly digest is what guarantees everyone with an open task gets a regular nudge.** It is Docket's own cron job, so it does not depend on any Slack API staying available. This makes it reliable by construction, and it is distinct from the native one-off reminder above.
- The weekly digest **also posts an unassigned-tasks summary to each channel**, in addition to the per-assignee DMs. This way tasks nobody owns do not silently sit unaddressed.
- Each person's digest DM is **grouped by channel/project** (one section per backlog they have open tasks in), not a flat merged list.
- Done tasks show struck-through at the bottom of the pinned list. The same weekly cron that sends the digest also sweeps them out of the pinned view, so no separate cleanup timer is needed.
- Tasks have an optional due date, in addition to title, assignee, and priority.
- **Priority is one field: manual rank.** `important` (a boolean) and due-date proximity are both rendered as informational signals — a badge and a computed "Due soon" callout. Neither one ever reorders or renumbers the ranked list. This keeps "what to work on next" a single, unambiguous read (the top of the list), instead of two systems that can disagree.
- **"Overdue"** means past `due_date`. **"Due soon"** means due within a configurable window (default 3 days) but not yet overdue. Both are computed fresh on every render, from `due_date` versus today, and rendered as two separate callouts — overdue first, most-recently-overdue first; then due-soon, nearest first. Nothing is stored, and nothing is scheduled.
- **A task's row links back to its `source_permalink` only while the message is likely to still exist.** Slack's free plan has a fixed 90-day message retention, so an older link is almost certainly dead. Rather than asking Slack whether the specific message still exists (an extra API call on every render, plus a scope Docket otherwise avoids needing), the link is hidden once it is older than a configurable window (default 90 days, matching free-plan retention). "Older than" is judged by the *message's own timestamp*, not the task's `created_at` — a Slack permalink encodes its message's timestamp directly in the URL (the `p<digits>` segment), so it can be recovered by parsing the stored link itself, with no extra API call, whether the link was auto-filled by the message shortcut or typed in by hand later. A link that isn't a recognizable Slack permalink (a different URL, or free text) falls back to the task's `created_at`. Either way, this is a heuristic, not a live check: if the workspace is ever on a paid plan with longer retention, still-valid links older than the window would be hidden until the default is changed.
- Any channel member can add, reorder, assign, or complete tasks. There are no per-user permission checks. This matches the "shared team backlog" spirit and keeps the logic simple.
- **No framework** (no Slim, no DI container). A single front controller, handling a couple of webhook routes, does not need one. Every incoming request is verified against Slack's signing secret (HMAC-SHA256 over `v0:{timestamp}:{rawBody}`, `hash_equals` comparison, rejecting anything older than 300 seconds) before anything else runs. Requests are handled synchronously, within Slack's 3-second budget. There is no queue and no background worker.
- **No Slack SDK dependency.** The Web API call surface (`chat.postMessage`, `chat.update`, `pins.add`, `pins.remove`, `views.open`, `views.publish`, `conversations.open`, `reminders.add`) is small enough for a thin, hand-rolled client over Guzzle.
- **No `users` table.** Slack renders `<@USER_ID>` client-side, so Docket only ever needs the Slack user ID, never a cached display name.
- **No `reminders` table.** Native `reminders.add` means Slack owns reminder state, not Docket.

## Requirements

### Slack surfaces

- `POST /slack/commands` — the slash command (`/docket ...`), with a standard `application/x-www-form-urlencoded` body.
- `POST /slack/interactions` — block actions (▲/▼, done, edit, remind-me buttons), the "Add as task" message shortcut, and modal (`view_submission`) submissions (the add-task/edit modal, and the reminder modal). The body is `application/x-www-form-urlencoded`, with a single `payload` field containing JSON.
- `POST /slack/events` — just `app_home_opened` (which renders "My Tasks") plus the one-time `url_verification` challenge.
- Any other path (including `/`) returns a static placeholder, with HTTP 200. There is no signature check and no DB connection, and it is never a redirect.

**Scopes:** `chat:write` (to post and update the list, and to DM digests and assignments), `commands`, `pins:write`, `reminders:read` plus `reminders:write`, `im:write`, `channels:join`. There is no `channels:history` or `groups:history`, because nothing here reads ambient channel messages. Message-shortcut payloads include the message text, author, channel, team domain, and message timestamp directly — enough to construct the permalink ourselves (`https://{team_domain}.slack.com/archives/{channel_id}/p{ts_without_the_dot}`), so no extra scope or API call is needed to get it.

**Joining a channel is automatic for public channels, not manual.** `pins.add` requires actual channel membership — posting alone, via `chat:write.public`, is not enough. So the first time someone adds a task in a public channel, Docket calls `conversations.join` itself, before posting the initial pinned message. No `/invite` step is needed. Private channels are the one case this cannot cover. Slack does not allow apps to join private channels programmatically, for privacy reasons, so a private-channel backlog still needs one manual `/invite @docket`.

### Data model

One `tasks` table:
- `id`
- `channel_id` — which project or list this task belongs to. A Slack message only ever lives in one channel, so "one list per project channel" and "each task tagged with its project" are the same data. The list rendered in a channel is `tasks WHERE channel_id = that channel`.
- `title`
- `assignee_user_id` (nullable — "no one" is a valid state, surfaced by the channel-level unassigned summary)
- `important` (a boolean, default false) — a badge only, and it does not affect rank
- `priority` (a gapped integer — for example, multiples of 1000 — so reordering or inserting does not require renumbering; scoped per `channel_id`)
- `due_date` (nullable)
- `status` (`open` or `done`)
- `created_by`, `created_at`, `completed_at`
- `source_permalink` (nullable — auto-filled when a task originates from "Add as task" on a message, or entered manually via the add/edit modal's optional Link field; see the Decisions section for how long it stays shown)

Plus a `list_state` table, with one row per channel (`channel_id`, `message_ts`), recording which pinned message to `chat.update`. A channel opts in the first time someone adds a task there. At that point, the app posts the initial pinned message and records its `channel_id` and `message_ts`.

"My Tasks" does not need a second list kept in sync. It is simply `tasks WHERE assignee_user_id = you`, across all channels, in the same table.

### Feature behavior

- **Pinned per-channel list**: one bot-owned message per channel, rebuilt and pushed with `chat.update` after every mutation, and pinned once via `pins.add` when first created. Two callouts render first, each only when non-empty: "⚠️ Overdue", listing anything past its due date, most-recently-overdue first; then "⏰ Due soon", listing anything due within the configured window but not yet overdue, nearest due date first. Both are purely a computed view over the same rows below them. Below them sits the single ranked list: `N. ⭐ Title — <@assignee>` (the ⭐ appears only if `important`), with the due date shown and flagged with ⚠️ if overdue, a 🔗 link to `source_permalink` if the task has one and isn't past the link-retention window (see Decisions), plus ✅ Done, ✏️ Edit, ▲, ▼, and ⏰ Remind-me actions, ordered by `priority`. Done tasks (struck through) stay appended at the bottom, each with a single ↩️ Reopen action, until the weekly cron clears them. A trailing "➕ Add task" button opens the add-task modal.
- **Add task**, from three entry points, all converging on the same modal, scoped to the channel the action happened in:
  1. `/docket Fix the login bug` — a slash command, adding to the bottom of the current channel's list.
  2. The "➕ Add task" button on the list message.
  3. The "Add as task" message shortcut, on any message — a modal pre-filled with the message text, permalink, and the message's author as a suggested assignee.
  The modal collects the title, assignee, due date, the Important toggle, and an optional link (auto-filled by the message shortcut, but also editable by hand for a task added via `/docket` or the button, if it relates to a conversation too).
- **Reorder**: ▲/▼ swaps `priority` with the adjacent row, then re-renders. The gapped integers make this a simple swap, with no renumbering.
- **Edit**: the ✏️ button opens the add-task modal, pre-filled, letting the title, assignee, due date, Important toggle, and link all be changed in one place. None of these changes touch `priority` — a task's position in the list only ever changes via ▲/▼. Changing the assignee re-fires the assignment DM.
- **Complete**: the ✅ block action on the pinned message writes to storage, then re-renders with a `chat.update`.
- **Reopen**: a mis-click's undo. The ↩️ action on a done row (shown in place of the other row actions) sets it back to `open` and clears `completedAt`, then re-renders. This only matters up until the weekly sweep permanently removes done rows — after that, reopening means re-adding the task.
- **Immediate DM on assignment**: whenever a task is created, or edited with a new assignee, Docket opens a DM and posts to them right after the storage write. This is the primary "push" visibility mechanism, and it requires zero typing to discover. No DM fires when someone assigns a task to themselves — you already know.
- **Weekly digest** (cron): for every assignee with open tasks, it DMs a summary, grouped by channel. For every channel with unassigned open tasks, it posts a summary to that channel. It also sweeps done tasks out of each channel's pinned list.
- **My Tasks (App Home)**: on `app_home_opened`, Docket queries the tasks assigned to that user, across all channels, and renders them via `views.publish`. This view is read-only, using the same rendering approach as the channel list, just filtered differently.
- **One-off reminder**: the ⏰ button (shown to the assignee) opens a modal with Slack's built-in datetime-picker block. On submit, it calls `reminders.add` for that user, with text linking back to the list message, at the chosen time. This shows up in the user's native Slack Reminders — no Docket-side storage is needed.

### Suggested build order

1. The `tasks` and `list_state` tables, the migration runner, and a storage layer behind an interface (real and in-memory, contract-tested against both).
2. A pure function: `tasks[]` to Block Kit JSON, for the pinned list — the ranked list, plus the computed "Due soon" callout and the Important badge — unit-tested with no Slack calls.
3. The slash command (`/docket <title>`) — the simplest add path (bottom of the list), proving the storage-plus-render loop end-to-end.
4. Block actions: reorder and done — proving the `chat.update` round-trip.
5. Modals: the add/edit-task modal (title, assignee, due date, Important), and the reminder modal.
6. The message shortcut, going to the same add-task modal, pre-filled.
7. The assignment DM — firing on task create, or edit with a new assignee, and proving the DM-open-plus-post path that the digest reuses.
8. The `reminders.add` integration, for the reminder modal.
9. The App Home "My Tasks" tab.
10. The weekly digest script (per-assignee grouped DM, plus the channel unassigned summary, plus the pinned-list sweep), plus the cron entry.
11. Deploy: a signature check on every route, with secrets kept outside the web root.

### Verification

Verification is mostly manual, since Slack UI flows are not practically automatable end-to-end. Create the Slack app with the scopes above. Register the slash command and message shortcut. Deploy the app, and invite the bot to a test channel. Then walk through every feature: add a task via all three entry points; assign one via Edit and confirm the DM; reorder tasks and confirm rank alone drives position; toggle Important via Edit and confirm the badge appears without moving the row; set a due date within the window and confirm it appears in the "⏰ Due soon" callout, without moving in the ranked list below; set a past due date and confirm it appears in the separate "⚠️ Overdue" callout instead, and is flagged with ⚠️ in the ranked list; complete a task and confirm the pinned message updates; reopen a done task and confirm it returns to the ranked list; set a native reminder and confirm it lands in Slack's own Reminders; open App Home and confirm "My Tasks"; and manually run the digest script, confirming the per-assignee grouping, the channel unassigned-tasks summary, and that done tasks clear from the pinned list.

Automated verification covers: unit tests for task-ordering and completion logic, and for the Block Kit builders (with no Slack or DB dependency); a storage contract test, run against both the in-memory fake and real MySQL; mocked-HTTP tests for the Slack API client; and routing tests, covering signature verification and path dispatch for all three endpoints.
