#!/bin/bash
# Troca a tela de login do painel clássico (tema prisma_v5) pelo login novo da Prisma Telecom.
# Uso (como root): bash aplicar-login.sh [--desfazer]
# Rode antes o ../seletor-painel/aplicar.sh (cria seletor.php e o redirecionamento ao painel novo).
set -e
HTML=${HTML_DIR:-/var/www/html}
AQUI="$(cd "$(dirname "$0")" && pwd)"
TPL="$HTML/themes/prisma_v5/_common/login.tpl"
IMG="$HTML/themes/prisma_v5/images/login"
ORIG="$TPL.original-prisma"
[ -f "$TPL" ] || { echo "login.tpl não encontrado em $TPL"; exit 1; }
TS=$(date +%Y-%m-%d_%H%M%S); SNAP=/var/backup/ipbx/backup_$TS
mkdir -p "$SNAP/html/themes/prisma_v5/_common"
cp -p "$TPL" "$SNAP/html/themes/prisma_v5/_common/login.tpl"
echo "Login novo Prisma (${1:-aplicar}) - $(date '+%Y-%m-%d %H:%M:%S')" > "$SNAP/manifesto.txt"
ln -sfn "$SNAP" /var/backup/ipbx/latest
if [ "$1" = "--desfazer" ]; then
  [ -f "$HTML/favicon.ico.original-prisma" ] && cp -f "$HTML/favicon.ico.original-prisma" "$HTML/favicon.ico"
  [ -f "$ORIG" ] && cp -f "$ORIG" "$TPL" && echo "[ok] login anterior restaurado." || echo "Nada a desfazer (cópia original não encontrada)."
else
  [ -f "$ORIG" ] || cp -p "$TPL" "$ORIG"
  mkdir -p "$IMG"; cp -f "$AQUI"/img/* "$IMG/"
  cp -f "$AQUI/login.tpl" "$TPL"
  # favicon da Prisma na raiz do painel clássico (guarda o original uma única vez)
  [ -f "$HTML/favicon.ico" ] && [ ! -f "$HTML/favicon.ico.original-prisma" ] && cp -p "$HTML/favicon.ico" "$HTML/favicon.ico.original-prisma"
  cp -f "$AQUI/favicon.ico" "$HTML/favicon.ico"; chmod 644 "$HTML/favicon.ico"
  chown -R asterisk:asterisk "$IMG" "$TPL" 2>/dev/null || true; chmod 644 "$IMG"/* "$TPL"
  rm -rf /var/www/html/templates_c/* 2>/dev/null || true   # limpa o cache do Smarty para ler o novo modelo
  echo "[ok] login novo aplicado. Snapshot em $SNAP. Para voltar: bash aplicar-login.sh --desfazer"
fi
