#!/bin/bash
# Aplica a escolha "Painel Novo 6.0 / Painel Clássico" na tela de login do painel clássico.
# Uso: bash aplicar.sh [--desfazer]    (como root, no servidor)
set -e
HTML=${HTML_DIR:-/var/www/html}
AQUI="$(cd "$(dirname "$0")" && pwd)"
TS=$(date +%Y-%m-%d_%H%M%S)
SNAP=/var/backup/ipbx/backup_$TS
mkdir -p "$SNAP/html/themes/prisma_v5/_common"
cp -p "$HTML/index.php" "$SNAP/html/index.php"
cp -p "$HTML/themes/prisma_v5/_common/login.tpl" "$SNAP/html/themes/prisma_v5/_common/login.tpl"
echo "Seletor de painel no login (${1:-aplicar}) - $(date '+%Y-%m-%d %H:%M:%S')" > "$SNAP/manifesto.txt"
ln -sfn "$SNAP" /var/backup/ipbx/latest
if [ "$1" != "--desfazer" ]; then
  cp -f "$AQUI/seletor.php" "$HTML/seletor.php"
  chown asterisk:asterisk "$HTML/seletor.php" 2>/dev/null || true; chmod 644 "$HTML/seletor.php"
fi
php "$AQUI/aplicar.php" "$HTML" "$@"
php -l "$HTML/index.php" >/dev/null && echo "[ok] index.php válido. Snapshot em $SNAP (restaurar: cp -rpf $SNAP/html/. $HTML/)"
