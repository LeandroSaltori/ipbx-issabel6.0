#!/bin/bash
# Instala o tema prisma_v6 (visual escuro) no painel clássico, SEM mexer no prisma_v5.
# Uso (como root):
#   bash aplicar-tema.sh              instala/atualiza a pasta do tema (não troca o tema ativo)
#   bash aplicar-tema.sh --ativar     instala e passa a usar o prisma_v6 como tema padrão
#   bash aplicar-tema.sh --desativar  volta ao tema que estava ativo antes (ex.: prisma_v5)
set -e
HTML=${HTML_DIR:-/var/www/html}
AQUI="$(cd "$(dirname "$0")" && pwd)"
ORIGEM="$AQUI/../../../../src/themes/prisma_v6"
DB=/var/www/db/settings.db
BK=/var/backup/ipbx; PREV="$BK/tema_anterior.txt"
getTema(){ php -r '$d=new PDO("sqlite:'$DB'");$r=$d->query("select value from settings where key=\"theme\"")->fetchColumn();echo $r;' 2>/dev/null; }
setTema(){ php -r '$d=new PDO("sqlite:'$DB'");$s=$d->prepare("update settings set value=? where key=\"theme\"");$s->execute(["'"$1"'"]);if(!$s->rowCount()){$d->prepare("insert into settings(key,value) values(\"theme\",?)")->execute(["'"$1"'"]);}'; }
TS=$(date +%Y-%m-%d_%H%M%S); SNAP=$BK/backup_$TS
mkdir -p "$SNAP/html/themes" && ln -sfn "$SNAP" "$BK/latest"
[ -f "$DB" ] && cp -p "$DB" "$SNAP/settings.db" 2>/dev/null || true
if [ "$1" = "--desativar" ]; then
  echo "Tema prisma_v6 desativado - $(date '+%Y-%m-%d %H:%M:%S')" > "$SNAP/manifesto.txt"
  ANT=$(cat "$PREV" 2>/dev/null || echo prisma_v5); setTema "$ANT"
  rm -rf "$HTML/templates_c/"* 2>/dev/null || true
  echo "[ok] tema ativo: $ANT (a pasta prisma_v6 foi mantida). Snapshot: $SNAP"; exit 0
fi
[ -d "$ORIGEM" ] || { echo "Pasta do tema não encontrada: $ORIGEM"; exit 1; }
[ -d "$HTML/themes/prisma_v6" ] && cp -a "$HTML/themes/prisma_v6" "$SNAP/html/themes/prisma_v6" 2>/dev/null || true
echo "Tema prisma_v6 (${1:-instalar}) - $(date '+%Y-%m-%d %H:%M:%S')" > "$SNAP/manifesto.txt"
/bin/cp -rf "$ORIGEM" "$HTML/themes/"
mkdir -p "$HTML/themes/prisma_v6/images"; cp -f "$ORIGEM/images/login/favicon.png" "$HTML/themes/prisma_v6/images/favicon.png" 2>/dev/null || true
chown -R asterisk:asterisk "$HTML/themes/prisma_v6" 2>/dev/null || true
rm -rf "$HTML/templates_c/"* 2>/dev/null || true
if [ "$1" = "--ativar" ]; then
  ATUAL=$(getTema); [ "$ATUAL" != "prisma_v6" ] && echo "${ATUAL:-prisma_v5}" > "$PREV"
  setTema prisma_v6; echo "[ok] tema ativo: prisma_v6 (anterior: ${ATUAL:-prisma_v5}). Para voltar: bash aplicar-tema.sh --desativar"
else
  echo "[ok] tema instalado em $HTML/themes/prisma_v6 (não ativado). Para ativar: bash aplicar-tema.sh --ativar"
fi
echo "Snapshot em $SNAP"
