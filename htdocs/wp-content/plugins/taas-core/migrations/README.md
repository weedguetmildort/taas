# Migrations

One-time transformations of an existing schema. Each file runs once per
install, ever, tracked by filename in the `taas_migrations_ran_core` option.

## What belongs here

Only changes `dbDelta` cannot make:

- dropping a column or index
- renaming a column
- narrowing a type, or removing `NOT NULL`
- changing data (backfills, normalising values)

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

- A version bump ships with every migration.

- There is no rollback.

- Test locally against real rows.

## Checking state

```
docker compose exec wordpress php \
  /var/www/html/wp-content/plugins/taas-core/bin/db-status.php
```

Shows applied and pending migrations. After running one, snapshot the result
and read the diff:

```
docker compose exec wordpress php \
  /var/www/html/wp-content/plugins/taas-core/bin/export-schema.php
```
