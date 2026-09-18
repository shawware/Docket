# Install Docket on shared hosting with MySQL

This guide deploys Docket to a shared hosting account with PHP and MySQL.

Exact steps and menu names vary by host. This guide uses generic terms. It describes what each step needs, so you can find the matching option on your own host. Where DreamHost is known to behave differently, this guide says so.

## Prerequisites

- A shared hosting account with a domain or subdomain, PHP 8.5, and MySQL
- SSH access to that account
- Composer, installed on the server or installable over SSH
- A Slack account that can install apps into the workspace you want to use

## 1. Set up the domain

In your host's control panel:

1. Add the domain or subdomain for this deployment.
2. Set the **document root** to the `public/` folder inside the repo, not the repo root. This keeps `.env`, `vendor/`, and the rest of the code unreachable by URL.
3. Confirm PHP 8.5 is selected for that domain.
4. Turn on HTTPS. Most hosts offer a free Let's Encrypt certificate. Slack requires `https://` for every URL it calls.

## 2. Get the code onto the server

Over SSH:

```
git clone <your-remote-url> ~/yourdomain.com
cd ~/yourdomain.com
composer install
```

## 3. Create the MySQL database

You need three things: a database, a MySQL user, and a grant that connects that user to that database.

On many hosts, all three come from the hosting **control panel**, not phpMyAdmin. phpMyAdmin usually only manages a database you already have access to. It does not create accounts.

1. In your host's control panel, find the database section. Create a new database.
2. In the same section, create a MySQL user, or reuse an existing one.
3. Grant that user access to this specific database. Many hosts need this step even for a user that already exists. A user created for one database does not automatically get access to another.
4. Note the database's hostname, name, username, and password. You need these for `.env` in the next step.

If your host instead gives you direct MySQL access over SSH, run `CREATE DATABASE` and `GRANT ALL ON dbname.* TO 'user'@'host'` yourself.

## 4. Create `.env`

Create this file on the server, at the project root — a sibling of `composer.json`, outside `public/`. Never commit this file to git.

```
DB_DSN=mysql:host=<db-hostname>;port=3306;dbname=<db-name>;charset=utf8mb4
DB_USER=<mysql-username>
DB_PASS=<mysql-password>

SLACK_SIGNING_SECRET=
SLACK_BOT_TOKEN=
```

Leave the two Slack values blank for now. You collect them in [slack-app-setup.md](slack-app-setup.md), after this guide confirms the site is live. See `.env.example` in the repo root for every available setting, including the optional `DUE_SOON_DAYS`, `SOURCE_LINK_MAX_AGE_DAYS`, and `DEBUG_LOGGING` values.

## 5. Run migrations

```
php bin/migrate.php
```

This creates the `tasks` and `list_state` tables, plus a `schema_migrations` table that tracks which migrations already ran. Safe to run again later — an already-applied migration is skipped.

## 6. Confirm the site responds

```
curl -i https://yourdomain.com/
```

This should return `200` and a small placeholder page. If this does not work, fix it before moving on — nothing past this point will work either.

## 7. Set up the Slack app

Follow [slack-app-setup.md](slack-app-setup.md) now. Your domain must already be live, because one of its steps (Event Subscriptions) verifies the URL immediately.

That guide ends with two values for `.env`: `SLACK_SIGNING_SECRET` and `SLACK_BOT_TOKEN`. Add them to the `.env` file you created in step 4.

## 8. Add the weekly digest cron job

Docket's weekly digest is a script, not a background service. A cron job runs it on a schedule you choose.

1. In your host's control panel (or `crontab -e` over SSH), add an entry like:

```
0 8 * * 1 php /home/you/yourdomain.com/bin/digest.php
```

This example runs every Monday at 8am server time. Pick whatever day and time suits your team.

2. Before relying on the schedule, run it once by hand to confirm it works:

```
php bin/digest.php --dry-run
```

This prints what the digest would do, with no messages sent and no data changed. Drop `--dry-run` to send it for real.

## 9. Invite the bot and test

- Public channel: run `/docket <a task title>` there. Docket joins the channel and posts the pinned list itself.
- Private channel: run `/invite @docket` first, then `/docket <a task title>`.

Then work through the rest of Docket's features: add a task from the "➕ Add task" button and from the "Add as task" message shortcut, assign someone and confirm the DM, reorder with ▲/▼, mark a task done, and open the app's Home tab to see "My Tasks".

---

## Troubleshooting

These are real problems hit getting a live install working. Check here before assuming it is a new bug. See also [slack-app-setup.md](slack-app-setup.md)'s own "Known gotchas" section, for problems specific to the Slack dashboard.

### `PDOException: Access denied for user ... to database ...`

The database and the user both need to exist. The user also needs an explicit grant on that specific database. On many hosts, this does not happen automatically, even for a user that already exists for another database. See step 3 above.

### A value in `.env` looks correct, but the app behaves as if it is empty

Some shared hosts disable PHP's `putenv()` function, for security. This can break `getenv()` for a value that loaded into `.env` correctly.

Docket avoids this by reading config through `envValue()`, in `env.php`, which checks `$_ENV` and `$_SERVER` before falling back to `getenv()`. This code path is already handled — if you hit this symptom, check for a typo or missing line in `.env` first, rather than the `putenv()` issue itself.

### `bin/digest.php` fails when run from cron, but works fine over SSH

Cron often runs with a different working directory and a smaller set of environment variables than an interactive SSH session. Use the full path to `php` and to `bin/digest.php` in the crontab entry, as shown in step 8. Do not rely on a relative path.

### Cron ran, but nobody got a message

Run `php bin/digest.php --dry-run` by hand first. It lists exactly what the digest would send, without sending anything.

If the dry run shows nothing, there is nothing to send yet. For example, every task might already be assigned, with nothing overdue and nothing recently completed.

If the dry run shows the expected messages, but a real run sends nothing, check two things: `.env`'s `SLACK_BOT_TOKEN`, and the app's installed scopes.

### Strange `ModSecurity` warnings in the Apache error log about `/.git/` or `/.env`

This is background internet noise, not a sign that anything is wrong. Automated scanners constantly probe public web servers for common mistakes. Your host's firewall is likely blocking these attempts.

Even without that firewall, these files were never reachable. `.git` and `.env` sit outside `public/`, the only folder a URL can reach.
