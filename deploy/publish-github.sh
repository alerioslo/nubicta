#!/usr/bin/env bash
# Publica el estado actual de main en GitHub como un commit nuevo sobre la historia publica (refs/friigo/public),
# sin subir la historia privada del servidor. El token se lee de GITHUB_TOKEN o de /root/.gh-friigo-token.
# Uso: deploy/publish-github.sh "Mensaje del commit"
set -euo pipefail
cd "$(dirname "$0")/.."
REPO="${FRIIGO_REPO:-github.com/bvr218/friigo.git}"
TOKEN="${GITHUB_TOKEN:-$(cat /root/.gh-friigo-token 2>/dev/null || true)}"
[ -n "$TOKEN" ] || { echo "Falta GITHUB_TOKEN o /root/.gh-friigo-token" >&2; exit 1; }
[ -z "$(git status --porcelain)" ] || { echo "Hay cambios sin commit; haz commit primero." >&2; exit 1; }

MSG="${1:-Actualización $(date +%Y-%m-%d)}"
TREE="$(git rev-parse 'HEAD^{tree}')"
PARENT="$(git rev-parse -q --verify refs/friigo/public || true)"
if [ -n "$PARENT" ] && [ "$(git rev-parse "$PARENT^{tree}")" = "$TREE" ]; then
    echo "GitHub ya tiene este estado."; exit 0
fi
COMMIT="$(git -c user.name="${GIT_AUTHOR_NAME:-Friigo}" -c user.email="${GIT_AUTHOR_EMAIL:-friigo@users.noreply.github.com}" \
    commit-tree "$TREE" ${PARENT:+-p "$PARENT"} -m "$MSG")"
AUTH="$(printf 'x-access-token:%s' "$TOKEN" | base64 -w0)"
git -c http.extraHeader="Authorization: Basic $AUTH" push "https://$REPO" "$COMMIT:refs/heads/main"
git update-ref refs/friigo/public "$COMMIT"
echo "PUBLICADO $(git rev-parse --short "$COMMIT")"
