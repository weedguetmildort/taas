#!/usr/bin/env sh
#
# Rebuild dev/ufl-assets.tar.gz: the UF theme and the plugins it needs, which
# the ufl-assets Docker service unpacks for local development. Prod is never
# touched; its copies stay untracked.
#
# SSH defaults match the prod login; override with TAAS_SSH_HOST and
# TAAS_SSH_USER.

set -eu

usage() {
	cat >&2 <<'USAGE'
Usage:
  bin/pack-ufl-assets.sh                pack the local htdocs/wp-content copies
  bin/pack-ufl-assets.sh --server       pull fresh copies from prod over SSH first
  bin/pack-ufl-assets.sh --from <dir>   pack from another wp-content directory
USAGE
	exit 1
}

cd "$(dirname "$0")/.."

ASSETS="themes/ufl-main-uni themes/ufl-main-uni-child-main plugins/advanced-custom-fields plugins/ufl-block plugins/section-nav"
OUT=dev/ufl-assets.tar.gz
SSH_HOST=${TAAS_SSH_HOST:-az1-apacheint-prod01.server.ufl.edu}
SSH_USER=${TAAS_SSH_USER:-cnswww-taas.cise}
REMOTE=/h/cnswww-taas.cise/taas.cise.ufl.edu/htdocs/wp-content

src=htdocs/wp-content
tmp=

cleanup() {
	if [ -n "$tmp" ]; then
		rm -rf "$tmp"
	fi
}
trap cleanup EXIT

case "${1:-}" in
	--server)
		tmp=$(mktemp -d)
		echo "Pulling from $SSH_USER@$SSH_HOST ..."
		# shellcheck disable=SC2029 # $REMOTE and $ASSETS expand locally, on purpose
		ssh -p 22 -l "$SSH_USER" "$SSH_HOST" "tar czf - -C $REMOTE $ASSETS" | tar xzf - -C "$tmp"
		src=$tmp
		;;
	--from)
		[ $# -ge 2 ] || usage
		src=$2
		;;
	'')
		;;
	*)
		usage
		;;
esac

# Refuse to build from a partial copy, e.g. after a failed SSH session
for dir in $ASSETS; do
	if [ -z "$(ls -A "$src/$dir" 2>/dev/null)" ]; then
		echo "Missing or empty: $src/$dir" >&2
		exit 1
	fi
done

# Same content gives the same bytes: fixed order, times, owners and modes,
# and gzip -n drops the timestamp. That needs GNU tar (gtar on macOS).
if command -v gtar >/dev/null 2>&1; then
	gnutar=gtar
else
	gnutar=tar
fi

if ! "$gnutar" --version 2>/dev/null | grep -q 'GNU tar'; then
	echo "GNU tar is required (macOS: brew install gnu-tar)" >&2
	exit 1
fi

mkdir -p dev

# shellcheck disable=SC2086 # $ASSETS is a space-separated list
"$gnutar" -C "$src" --sort=name --mtime='2026-01-01 00:00:00Z' \
	--owner=0 --group=0 --numeric-owner --mode='a=rX,u+w' \
	-cf - $ASSETS | gzip -9n > "$OUT.tmp"
mv "$OUT.tmp" "$OUT"

echo "Wrote $OUT ($(du -h "$OUT" | cut -f1)). Commit it to share; 'docker compose up -d' unpacks it."
