# Migrations

One-time transformations of an existing schema. Each file runs once per
install, ever, tracked by filename in the `taas_migrations_ran_core` option.

## What belongs here

Only changes `dbDelta` cannot make:

- dropping a column or index
- renaming a column
- narrowing a type, or removing `NOT NULL`
- changing a primary key, or adding an `AUTO_INCREMENT` column to an existing table
- changing a column that a foreign key uses, on either side
- changing an existing constraint (see below)
- changing data (backfills, normalising values)
- changing a relationship key while preserving existing references, such as
  moving courses from professor UFIDs to stable professor IDs

`dbDelta` never reports these. It fails or skips them and the old structure
stays, which is how 0.7.8.8.2.5 reached prod with half-converted `professors`
and `courses` tables (fixed by `004-professor-surrogate-key.php`). If unsure,
run `bin/check-schema-upgrade.sh`: it fails when a change needs a migration.

## Changing a constraint

The installer adds a constraint from `sql/constraints.sql` only when no
constraint has that name. It never alters one that exists, so editing a
definition in place reaches fresh installs but not existing ones. To change one:

1. Give it a new name in `sql/constraints.sql`, e.g. `fk_courses_professor_id`
2. Drop the old name in a migration, guarded on it existing

The installer then adds the new one. It also reports a foreign key whose
columns or `ON DELETE` rule differ from `sql/constraints.sql`, but MySQL
rewrites `CHECK` expressions, so those are matched by name only. Renaming is
what makes a changed `CHECK` apply.

**Not** initial setup. `sql/schema.sql` and `sql/constraints.sql` describe
the desired end state and run idempotently on every activation. A fresh
install gets the final schema from those files directly.

## Naming

Zero-padded numeric prefix, then a short description of what it does:

```
001-add-term-column.php
002-drop-unique-student-course.php
003-backfill-term.php
```

The runner sorts filenames to decide run order, so the prefix is what
guarantees sequence. Never rename or delete a migration that has run anywhere.
The applied list is keyed by filename; renaming one makes it run again.

## Template

Copy this, rename it, fill it in.

```php
<?php
/**
 * One line saying what this changes and why
 *
 * @package TAAS_Core
 */

defined( 'ABSPATH' ) || exit;

return function ( wpdb $wpdb ): void {
	$table = TAAS_Schema::table( 'courses' );

	// Guard: make this a no-op on a fresh install, where sql/schema.sql
	// already produced the end state. Every migration needs one
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$needs_work = $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'old_column'" );

	if ( empty( $needs_work ) ) {
		return;
	}

	// Preserve data before destroying its container, if the change is a
	// restructure rather than a discard
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "UPDATE {$table} SET new_column = old_column WHERE new_column IS NULL" );

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "ALTER TABLE {$table} DROP COLUMN old_column" );

	// Throw rather than return quietly. The runner halts on an exception,
	// because a later migration may assume this one succeeded
	if ( '' !== $wpdb->last_error ) {
		throw new RuntimeException( $wpdb->last_error );
	}
};
```

## Rules worth remembering

- Keep the migration and `sql/schema.sql` in agreement.

- No version bump needed. Adding a file changes the schema fingerprint, and
  the installer runs on the next wp-admin load after deploy.

- There is no rollback, and MySQL commits each `ALTER TABLE` at once. Guard
  every step on the current state, so a run that stopped halfway can repeat.

- The installer applies `sql/`, runs migrations, then applies `sql/` again,
  so a migration only needs to unblock what `dbDelta` could not do.

- Test locally against real rows.

## When an update does not finish

After migrations, the installer compares the tables with `sql/`. If anything
differs, or a migration failed, it does not record the upgrade: administrators
see an error notice listing the differences, the debug log has the full list,
and the installer retries every 15 minutes and after the next deploy.

## Checking state

```
docker compose exec wordpress php /var/www/taas/bin/db-status.php
```

Shows applied and pending migrations, whether the fingerprint says an upgrade
is due, and every way the live tables differ from `sql/`.

Before opening a PR that touches `sql/` or `migrations/`:

```
bin/check-schema-upgrade.sh                    # needs docker compose up -d
bin/check-schema-upgrade.sh --write-snapshots  # then commit snapshots/taas-core/
```

It replays every release tag's schema in order, upgrades to your branch, and
fails unless the result matches a fresh install and `snapshots/taas-core/`
matches too. CI runs the same script. Refresh snapshots with
`--write-snapshots` rather than `bin/export-schema.php`, which exports your
local database, whatever state it is in.
