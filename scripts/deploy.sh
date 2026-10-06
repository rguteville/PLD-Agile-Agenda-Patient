#!/usr/bin/env bash
# Déploiement sur la VM de démo. À lancer SUR LA VM, depuis le dossier du projet :
#   bash scripts/deploy.sh            -> déploie main
#   bash scripts/deploy.sh <branche>  -> déploie une autre branche (pour tester)
set -euo pipefail

BRANCH="${1:-main}"
cd "$(dirname "$0")/.."

if [ ! -f config/config.php ]; then
  echo "config/config.php absent : copiez config/config.example.php et renseignez la base." >&2
  exit 1
fi

# On ne modifie jamais le code directement sur la VM : tout passe par GitHub.
if [ -n "$(git status --porcelain --untracked-files=no)" ]; then
  echo "Modifications locales sur la VM : déploiement annulé." >&2
  git status --short
  exit 1
fi

git fetch origin
git checkout "$BRANCH"
git pull --ff-only origin "$BRANCH"
composer install --no-dev --optimize-autoloader --no-interaction

echo "Déployé : $(git log -1 --format='%h %s') (branche $BRANCH)"

if curl -fsS http://localhost/ > /dev/null; then
  echo "Site OK : http://localhost/"
else
  echo "Attention : le site ne répond pas sur http://localhost/" >&2
fi
