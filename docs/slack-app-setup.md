# Slack app setup

This guide sets up the Slack app for a deployed Docket instance.

Do this once per workspace. Repeat the affected steps if you add a scope or change an endpoint URL.

Docket needs a live, deployed instance before some steps in this guide will work. See [install-shared-hosting-mysql.md](install-shared-hosting-mysql.md) for that.

## 1. Create the app

1. Go to [api.slack.com/apps](https://api.slack.com/apps).
2. Click **Create New App**, then **From scratch**.
3. Name the app and pick your workspace.
4. Find **Socket Mode** in the sidebar. Turn it off. Docket uses HTTPS webhooks, not a socket connection.

## 2. Add bot token scopes

1. Open **OAuth & Permissions**.
2. Under **Scopes**, find **Bot Token Scopes**.
3. Add each scope in this table.

| Scope | Purpose |
|---|---|
| `chat:write` | Post and update the pinned list. Send DMs for assignments and the weekly digest. |
| `commands` | Run the `/docket` slash command. |
| `pins:write` | Pin the list message. |
| `channels:join` | Join a public channel automatically, before pinning there for the first time. |
| `im:write` | Open a DM for assignment notices and the weekly digest. |

Add all five scopes now. This avoids a second app install later.

## 3. Add the slash command

1. Open **Slash Commands**.
2. Click **Create New Command**.
3. Set **Command** to `/docket`.
4. Set **Request URL** to `https://your-domain/slack/commands`.
5. Add a short description, for example "Add a task to this channel's list".
6. Save.

## 4. Turn on Interactivity

1. Open **Interactivity & Shortcuts**.
2. Turn the toggle **On**.
3. Set **Request URL** to `https://your-domain/slack/interactions`.
4. On the same page, scroll down and click **Create New Shortcut**.
5. Choose **On messages**.
6. Set **Name** to "Add as task".
7. Add a short description, for example "Add this message as a task".
8. Set **Callback ID** to `add_as_task`.
9. Save.

This shortcut then appears in every message's "More actions" (`⋮`) menu.

## 5. Turn on App Home

1. Open **App Home** in the sidebar.
2. Turn on **Home Tab**.

This setting is off by default. Without it, "My Tasks" has nowhere to render, even after every other step is done.

## 6. Turn on Event Subscriptions

Event Subscriptions is a separate page from both Interactivity & Shortcuts and App Home. It is easy to skip. App Home does nothing without it.

Without this step, opening the app shows Slack's own placeholder page forever. That placeholder says "This is still a work in progress." Docket's own logs stay empty, because Slack never sends the `app_home_opened` event.

1. Open **Event Subscriptions**.
2. Turn the toggle **On**.
3. Set **Request URL** to `https://your-domain/slack/events`.
4. Wait for Slack to verify the URL. Slack sends a one-time `url_verification` request as soon as you enter the URL. Your app must already be deployed and responding for this to succeed.
5. Confirm a green "Verified" checkmark appears next to the Request URL field. If it does not appear, the subscription is not active. Check the URL for typos, then save again.
6. Under **Subscribe to bot events**, add `app_home_opened`.
7. Save.

## 7. Install the app

1. Open **OAuth & Permissions**.
2. Click **Install to Workspace**. If you already installed the app before, click **Reinstall to Workspace** instead — a scope or shortcut change needs this.
3. Approve the requested scopes.

This step generates the **Bot User OAuth Token** (it starts with `xoxb-`).

A bot event subscription, such as `app_home_opened`, takes effect immediately. It does not need a reinstall, as long as it adds no new scope.

## 8. Collect credentials for `.env`

1. Open **OAuth & Permissions**. Copy the **Bot User OAuth Token**.
2. Open **Basic Information**, then **App Credentials**. Copy the **Signing Secret**.
3. On the server, add both values to `.env` (see `.env.example`):

```
SLACK_SIGNING_SECRET=...
SLACK_BOT_TOKEN=xoxb-...
```

## 9. Invite the bot to a test channel

- **Public channel**: no manual step needed. Docket joins the channel itself, the first time someone adds a task there.
- **Private channel**: run `/invite @docket` first. Slack does not let apps join private channels on their own.

## Known gotchas

These are real problems hit while setting up a live Docket instance. Check here before assuming something is a new bug.

### A stray `robots.txt` breaks routing for that one path

Some hosting control panels create a default `public/robots.txt` file when you add a domain. A web server serves an existing static file directly. It does this before `index.php` ever runs.

This breaks Docket's placeholder behavior for that one path. Check for an auto-generated `public/robots.txt` after setting up the domain. Delete it if it exists.

### A newly toggled Interactivity setting can take a short while to settle

Right after you turn Interactivity on, a click may show "app took too long to respond." This can happen even though the endpoint itself responds in milliseconds.

Retry the click. If it works on retry, this was Slack or host propagation delay, not a bug. If it keeps failing, time a direct `curl` request against the endpoint to check its real response speed.

### An edited shortcut Callback ID can silently revert

You type a new Callback ID and click the shortcut's own **Update** button. This appears to work. But clicking the page-level **Save Changes** afterward can restore the old value. This is a bug in Slack's own dashboard, not in Docket.

If a Callback ID will not stick after an edit, delete the shortcut. Recreate it from scratch with the correct value from the start.
