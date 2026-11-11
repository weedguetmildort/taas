#!/usr/bin/env bash
# Check that upgrading from every release reaches the same schema as a fresh install.
#
# Extracts each release's sql/ and migrations/ from its git tag, then runs
# bin/check-schema-upgrade.php in the wordpress container. CI runs this on every
# PHP change; run it yourself before opening a PR that touches sql/ or migrations/.
#
#   bin/check-schema-upgrade.sh                    # check
#   bin/check-schema-upgrade.sh --write-snapshots  # also refresh snapshots/taas-core/
#
# Needs the stack running (docker compose up -d) and the release tags (git fetch --tags).

set -euo pipefail

# Git Bash on Windows would otherwise rewrite the container paths below
export MSYS_NO_PATHCONV=1

cd "$(dirname "$0")/.."

plugin=htdocs/wp-content/plugins/taas-core
out=build/schema-releases

rm -rf "$out"
mkdir -p "$out"

count=0
for tag in $(git tag --list 'v*' --sort=v:refname); do
	# Releases from before sql/ existed have nothing to replay
	git cat-file -e "$tag:$plugin/sql/schema.sql" 2>/dev/null || continue

	count=$((count + 1))
	dir="$out/$(printf '%03d' "$count")-$tag"
	mkdir -p "$dir/migrations"

	git show "$tag:$plugin/sql/schema.sql" > "$dir/schema.sql"
	git show "$tag:$plugin/sql/constraints.sql" > "$dir/constraints.sql" 2>/dev/null || : > "$dir/constraints.sql"

	for file in $(git ls-tree --name-only "$tag" "$plugin/migrations/" | grep '\.php$' || true); do
		git show "$tag:$file" > "$dir/migrations/$(basename "$file")"
	done
done

echo "Replaying $count release(s) with a schema"

# The wordpress entrypoint writes wp-config.php before Apache starts
for _ in $(seq 1 60); do
	if docker compose exec -T wordpress test -f /var/www/html/wp-config.php 2>/dev/null; then
		break
	fi
	sleep 2
done

password=$(docker compose exec -T db printenv MYSQL_ROOT_PASSWORD)

docker compose exec -T -e TAAS_DB_ROOT_PASSWORD="$password" wordpress \
	php /var/www/taas/bin/check-schema-upgrade.php "/var/www/taas/$out" "$@"
