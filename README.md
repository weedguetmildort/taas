# TAAS

TA Assignment System for UF CISE, live at `taas.cise.ufl.edu`. One WordPress
install with TAAS plugins; only `taas-core` exists so far.

## Layout

The repo root **is** the server root, so a deploy is a `git checkout`.

| Repo path | Server path | Notes |
|---|---|---|
| `/` | `/h/cnswww-taas.cise/taas.cise.ufl.edu/` | Above the document root, not web-reachable |
| `htdocs/` | Document root | Only `.htaccess` and `taas-*` plugins are tracked |
| `htdocs/.htaccess` | | FastCGI handler, Shibboleth, rewrites. Never mount it into Docker |
| `htdocs/wp-content/plugins/taas-core/` | | The plugin: `includes/`, `sql/`, `migrations/`, `pages/`, `seeds/` |
| `bin/`, `tests/`, `snapshots/` | | Dev tooling, kept out of the document root on purpose |

Everything else on the server (WordPress core, third-party plugins, themes,
uploads, `cgi-bin/`, `logs/`) is ignored. `.gitignore` is an allow list under
`htdocs/`; check any path with `git check-ignore -v <path>`.

## Local setup

Requires Docker. WordPress core comes from the image, not the repo.

```sh
cp .env.example .env
docker compose up -d
```

- Site: <http://localhost:8080>. Database for GUI tools: `127.0.0.1:3307`
- `TAAS_LOCAL_DEV` mocks Shibboleth. Set the role with `TAAS_LOCAL_DEV_USER`
  in `.env`, or add `?taas_role=instructor` to any URL
- Activating `taas-core` creates the tables, constraints and pages, and
  imports `seeds/demo-data.json`

The repo root is mounted at `/var/www/taas` inside the container:

```sh
docker compose exec wordpress php /var/www/taas/bin/db-status.php      # tables, constraints, migrations, fingerprint
docker compose exec wordpress php /var/www/taas/bin/seed.php [--fresh] # reload demo data
docker compose exec wordpress php /var/www/taas/bin/export-schema.php  # live DDL -> snapshots/taas-core/
docker compose exec wordpress php /var/www/taas/bin/export-pages.php   # page edits -> pages/*.html
docker compose exec wordpress php /var/www/taas/bin/import-courses.php /var/www/taas/real-data/<file>.xlsx
```

`real-data/` is gitignored, for real course files that must never be committed.
Course import runs locally only; the server has no `vendor/`.

### UF theme

UF's Mercury theme and the plugins it needs ship as one archive,
`dev/ufl-assets.tar.gz`. `docker compose up -d` unpacks it before WordPress
starts (the `ufl-assets` service), and skips that when the archive has not
changed. Prod is unaffected: there, these folders stay untracked.

```
htdocs/wp-content/themes/ufl-main-uni/              Mercury (parent)
htdocs/wp-content/themes/ufl-main-uni-child-main/   Mercury Child (active on the site)
htdocs/wp-content/plugins/advanced-custom-fields/   required by the child theme
htdocs/wp-content/plugins/ufl-block/                UF blocks used in page content
htdocs/wp-content/plugins/section-nav/              section navigation template
```

After the first `up`, activate the three plugins in wp-admin, and only then
switch to Mercury Child (it calls ACF).

The unpacked folders are gitignored and overwritten whenever the archive
changes, so don't edit them. If they drift from the server, rebuild the
archive (Git Bash on Windows; macOS needs `brew install gnu-tar`), then
commit `dev/ufl-assets.tar.gz` in a PR:

```sh
bin/pack-ufl-assets.sh --server   # pull fresh copies from prod over SSH
bin/pack-ufl-assets.sh            # or pack the local copies as they are
```

The same content always produces the same bytes, so rebuilding without real
changes leaves the archive untouched.

## Checks

The same steps CI runs:

```sh
composer install
composer validate --strict
php bin/check-version.php
composer lint        # PHPCS, WordPress Coding Standards
composer test        # PHPUnit
```

## Database changes

- `sql/schema.sql` and `sql/constraints.sql` describe the end state and are
  applied idempotently
- `migrations/` holds one-time changes those cannot make: renames, drops,
  narrowing, backfills. See `migrations/README.md`
- No version bump is needed. The installer runs on the next wp-admin load
  whenever the schema files or migration filenames change (the schema
  fingerprint shown by `db-status.php`)

## Adding a plugin

1. Create `htdocs/wp-content/plugins/taas-<name>/`
2. Add `!/htdocs/wp-content/plugins/taas-<name>/` to `.gitignore`, or Git
   silently ignores it
3. Add its mount to `docker-compose.yml`
4. Use the `wp_taas_<ns>_*` table prefix, and expose data to other plugins
   through filters rather than direct queries

## Conventions

- Branches `type/short-description`, Conventional Commits, PRs into `main`,
  CI must pass
- Hyphens in folder, branch and REST names; underscores only where PHP
  requires them (functions, hooks, table prefixes, option keys)
- Store timestamps in UTC; convert to Eastern at display time
- The server caps `max_input_vars` at 1000, so paginate large forms
