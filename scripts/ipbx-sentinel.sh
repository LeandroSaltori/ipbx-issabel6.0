#!/bin/bash
# ==============================================================================
# IPBX SENTINEL - Monitor de integridade web e anti-webshell (PRISMA TELECOM)
# ==============================================================================
# Compativel com Issabel 4 (CentOS 7) e Issabel 5 (Rocky Linux 8).
# Atua SOMENTE na camada web (/var/www/html). Nao toca no Asterisk, no dialplan,
# nem nos scripts PHP legitimos do painel, APIs, WhatsApp ou webhooks.
#
# O QUE FAZ
#  1. Webshells: procura assinaturas conhecidas (caso Emad e eval ofuscado em
#     cadeia, ex.: eval($a($b($c("...")))) ) em arquivos PHP.
#  2. Modulos falsos: diretorios de botnet em /admin/modules (phones, h, _cache,
#     tinfo, fpbxphones).
#  3. PHP em pastas estaticas (images/, img/): nunca deveria existir.
#  4. Para cada achado: move para QUARENTENA (reversivel), avisa no Telegram com
#     data/hora, arquivo e IP de origem (quando aparece no log do Apache).
#
# USO
#   ipbx-sentinel --scan           varredura completa (padrao)
#   ipbx-sentinel --incremental    so arquivos alterados recentemente (cron)
#   ipbx-sentinel --install        instala comando, config e cron (/etc/cron.d)
#   ipbx-sentinel --uninstall      remove o cron
#   ipbx-sentinel --list           lista itens em quarentena
#   ipbx-sentinel --restore ARQ    restaura um arquivo da quarentena
#   ipbx-sentinel --test-alert     envia mensagem de teste ao Telegram
#   ipbx-sentinel --selftest       testa o detector em pasta temporaria
#
# CONFIGURACAO (opcional) em /etc/ipbx/sentinel.conf (root, modo 600):
#   SENTINEL_ACTION=quarantine     # quarantine | alert (apenas avisa)
#   SENTINEL_HEAL_PERMS=0          # 1 = remove escrita de "outros" em arquivos web
# Telegram: /etc/ipbx/telegram.conf  ->  TELEGRAM_BOT_TOKEN=...  TELEGRAM_CHAT_ID=...
# Excecoes (falsos positivos): /etc/ipbx/sentinel.allow (um caminho/glob por linha)
# ==============================================================================

WEB_DIR="${WEB_DIR:-/var/www/html}"
CONF_FILE="${SENTINEL_CONF:-/etc/ipbx/sentinel.conf}"
TG_CONF="${SENTINEL_TG_CONF:-/etc/ipbx/telegram.conf}"
ALLOW_FILE="${SENTINEL_ALLOW:-/etc/ipbx/sentinel.allow}"
QUAR_ROOT="${SENTINEL_QUAR:-/var/backup/ipbx/quarantine}"
LOG_FILE="${SENTINEL_LOG:-/var/log/ipbx-sentinel.log}"
LOCK_FILE="/var/run/ipbx-sentinel.lock"
INTERVAL_MIN=5
HOSTNAME_S="$(hostname 2>/dev/null || echo pbx)"

SENTINEL_ACTION="${SENTINEL_ACTION:-quarantine}"
SENTINEL_HEAL_PERMS="${SENTINEL_HEAL_PERMS:-0}"
[ -r "$CONF_FILE" ] && . "$CONF_FILE" 2>/dev/null
SEND_ALERTS="${SENTINEL_NO_ALERT:+0}"
SEND_ALERTS="${SEND_ALERTS:-1}"

# Modulos falsos conhecidos de botnet (nao existem no Issabel/FreePBX homologado)
ROGUE_MODULES=(phones h _cache tinfo fpbxphones)

# Assinaturas (grep -E). Alta confianca: Emad e eval encadeado de variaveis-funcao.
SIG_EMAD='Emad__Was__Here|EmadWasHere'
SIG_CHAIN='eval\(\s*\$[A-Za-z_][A-Za-z0-9_]*\(\s*\$[A-Za-z_][A-Za-z0-9_]*\('
SIG_DECODE='eval\(\s*(base64_decode|gzinflate|gzuncompress|str_rot13)\('
SIG_SUPER='(passthru|shell_exec|system|assert)\(\s*\$_(GET|POST|REQUEST|COOKIE)'
SIG_ALL="${SIG_EMAD}|${SIG_CHAIN}|${SIG_DECODE}|${SIG_SUPER}|c99shell|r57shell"

# Legitimos conhecidos (evita falso positivo): camada de compatibilidade PHP,
# XML_Unserializer (usa doubleval) e referencias somente-leitura do repositorio.
EXCLUDES=(
  -not -path "*/libraries/php-upgrade/*"
  -not -name "XML_Unserializer.php"
  -not -path "*/front_api/pabx_panel/Inspiracoes/*"
  -not -path "*/var/templates_c/*"
)

log() { echo "$(date '+%Y-%m-%d %H:%M:%S') $*" >> "$LOG_FILE" 2>/dev/null; [ -t 1 ] && echo "$*"; return 0; }

load_telegram() {
  TELEGRAM_BOT_TOKEN=""; TELEGRAM_CHAT_ID=""
  [ -r "$TG_CONF" ] && . "$TG_CONF" 2>/dev/null
  if [ -z "$TELEGRAM_BOT_TOKEN" ]; then
    # Compatibilidade: reaproveita as credenciais do monitor ja instalado na frota
    local m
    for m in /usr/local/bin/monitor_prisma.sh /usr/local/bin/monitor_issabel_users.sh /etc/ipbx/monitor_prisma.sh; do
      if [ -r "$m" ]; then
        TELEGRAM_BOT_TOKEN="$(sed -n 's/^TOKEN="\([^"]*\)".*/\1/p' "$m" | head -1)"
        TELEGRAM_CHAT_ID="$(sed -n 's/^CHAT_ID="\([^"]*\)".*/\1/p' "$m" | head -1)"
        [ -n "$TELEGRAM_BOT_TOKEN" ] && break
      fi
    done
  fi
}

send_telegram() {
  [ "$SEND_ALERTS" = "1" ] || return 0
  load_telegram
  [ -n "$TELEGRAM_BOT_TOKEN" ] && [ -n "$TELEGRAM_CHAT_ID" ] || { log "Telegram nao configurado; alerta nao enviado."; return 0; }
  curl -s -m 15 -X POST "https://api.telegram.org/bot${TELEGRAM_BOT_TOKEN}/sendMessage" \
       --data-urlencode "chat_id=${TELEGRAM_CHAT_ID}" --data-urlencode "text=$1" >/dev/null 2>&1 || log "Falha ao enviar Telegram."
}

is_allowed() {  # $1 = caminho
  [ -r "$ALLOW_FILE" ] || return 1
  local pat
  while IFS= read -r pat; do
    case "$pat" in ''|\#*) continue ;; esac
    # shellcheck disable=SC2254
    case "$1" in $pat) return 0 ;; esac
  done < "$ALLOW_FILE"
  return 1
}

# IPs que acessaram a URL do arquivo (melhor esforco, logs do Apache)
source_ips() {
  local rel="${1#$WEB_DIR}" l out=""
  for l in ${SENTINEL_ACCESS_LOGS:-/var/log/httpd/access_log /var/log/httpd/ssl_access_log /var/log/apache2/access.log}; do
    [ -r "$l" ] || continue
    out="$out$(tail -n 100000 "$l" 2>/dev/null | grep -F -- "$rel" | awk '{print $1}')"$'\n'
  done
  echo "$out" | sed '/^$/d' | sort | uniq -c | sort -rn | head -3 | awk '{printf "%s(%sx) ", $2, $1}'
}

QUAR_DIR=""
FOUND=()   # linhas "motivo|caminho|destino|ips"

quarantine() {  # $1 = caminho, $2 = motivo
  local f="$1" why="$2" dest="" ips
  is_allowed "$f" && return 0
  [ -e "$f" ] || return 0
  ips="$(source_ips "$f")"
  if [ "$SENTINEL_ACTION" = "quarantine" ]; then
    [ -n "$QUAR_DIR" ] || { QUAR_DIR="$QUAR_ROOT/$(date '+%Y-%m-%d_%H%M%S')"; mkdir -p "$QUAR_DIR" && chmod 700 "$QUAR_ROOT" "$QUAR_DIR" 2>/dev/null; }
    dest="$QUAR_DIR/${f#/}"
    mkdir -p "$(dirname "$dest")" && mv -f "$f" "$dest" 2>/dev/null && { [ -d "$dest" ] || chmod 000 "$dest" 2>/dev/null; }
    printf '%s\t%s\t%s\t%s\n' "$(date '+%F %T')" "$f" "$dest" "$why" >> "$QUAR_ROOT/INDEX.tsv" 2>/dev/null
  fi
  FOUND+=("${why}|${f}|${dest}|${ips}")
  log "ACHADO [$why] $f -> ${dest:-(somente alerta)} ${ips:+IPs: $ips}"
}

scan_signatures() {  # $1 = "full" | "inc"
  local -a find_args=("$WEB_DIR" -type f \( -iname '*.php' -o -iname '*.phtml' -o -iname '*.phar' -o -iname '*.inc' -o -iname '*.php[3-8]' \))
  [ "$1" = "inc" ] && find_args+=(-mmin "-$((INTERVAL_MIN * 2))")
  find_args+=("${EXCLUDES[@]}" -print0)
  local f why
  while IFS= read -r -d '' f; do
    if grep -qE "$SIG_EMAD" "$f" 2>/dev/null; then why="assinatura Emad"
    elif grep -qE "$SIG_CHAIN" "$f" 2>/dev/null; then why="eval ofuscado em cadeia"
    elif grep -qE "$SIG_DECODE" "$f" 2>/dev/null; then why="eval de codigo decodificado"
    elif grep -qE "$SIG_SUPER" "$f" 2>/dev/null; then why="execucao de comando via requisicao"
    elif grep -qE 'c99shell|r57shell' "$f" 2>/dev/null; then why="webshell conhecida"
    else continue; fi
    quarantine "$f" "$why"
  done < <(find "${find_args[@]}" 2>/dev/null | xargs -0 -r grep -lZE "$SIG_ALL" 2>/dev/null)
}

scan_rogue_modules() {
  local m d
  for m in "${ROGUE_MODULES[@]}"; do
    d="$WEB_DIR/admin/modules/$m"
    [ -d "$d" ] && quarantine "$d" "modulo falso de botnet"
  done
}

scan_static_php() {
  local f
  while IFS= read -r -d '' f; do
    quarantine "$f" "PHP em pasta de imagens"
  done < <(find "$WEB_DIR" -type f \( -iname '*.php' -o -iname '*.phtml' -o -iname '*.phar' -o -iname '*.php[3-8]' \) \
            \( -path '*/images/*' -o -path '*/img/*' \) -not -path "*/front_api/pabx_panel/Inspiracoes/*" -print0 2>/dev/null)
}

heal_perms() {
  [ "$SENTINEL_HEAL_PERMS" = "1" ] || return 0
  # Conservador: apenas remove escrita de "outros" (nunca chmod/chown recursivo geral)
  local n
  n="$(find "$WEB_DIR" -type f -perm -0002 -not -path "*/var/templates_c/*" 2>/dev/null | wc -l)"
  [ "$n" -gt 0 ] && find "$WEB_DIR" -type f -perm -0002 -not -path "*/var/templates_c/*" -exec chmod o-w {} + 2>/dev/null
  log "Permissoes: escrita de 'outros' removida de $n arquivo(s)."
}

report() {
  [ "${#FOUND[@]}" -gt 0 ] || return 0
  local msg line
  msg="ALERTA DE SEGURANCA - ${HOSTNAME_S}"$'\n'"$(date '+%d/%m/%Y %H:%M:%S')"$'\n'
  [ "$SENTINEL_ACTION" = "quarantine" ] && msg+="Acao: item(ns) movido(s) para quarentena (reversivel)."$'\n' || msg+="Acao: apenas alerta (nada foi movido)."$'\n'
  local i=0
  for line in "${FOUND[@]}"; do
    i=$((i+1)); [ $i -gt 10 ] && { msg+="... e mais $(( ${#FOUND[@]} - 10 )) item(ns)."$'\n'; break; }
    IFS='|' read -r why f dest ips <<<"$line"
    msg+=$'\n'"- ${f}"$'\n'"  motivo: ${why}"
    [ -n "$ips" ] && msg+=$'\n'"  origem provavel: ${ips}"
  done
  msg+=$'\n\n'"Para ver/restaurar: ipbx-sentinel --list | --restore ARQUIVO"
  send_telegram "$msg"
}

do_scan() {
  local mode="$1"
  [ -d "$WEB_DIR" ] || { log "Diretorio web $WEB_DIR nao existe."; return 0; }
  scan_rogue_modules
  scan_static_php
  scan_signatures "$mode"
  [ "$mode" = "full" ] && heal_perms
  report
  [ "${#FOUND[@]}" -eq 0 ] && log "Varredura ($mode) concluida: nada suspeito."
  return 0
}

cmd_install() {
  mkdir -p /etc/ipbx "$QUAR_ROOT" /var/backup/ipbx
  chmod 700 "$QUAR_ROOT" 2>/dev/null
  local self; self="$(readlink -f "$0" 2>/dev/null || echo "$0")"
  if [ -f "$self" ] && [ "$self" != "/usr/local/bin/ipbx-sentinel" ]; then /bin/cp -f "$self" /usr/local/bin/ipbx-sentinel; fi
  chmod +x /usr/local/bin/ipbx-sentinel
  if [ ! -f "$CONF_FILE" ]; then
    printf '# IPbx Sentinel\nSENTINEL_ACTION=quarantine\nSENTINEL_HEAL_PERMS=0\n' > "$CONF_FILE"; chmod 600 "$CONF_FILE"
  fi
  [ -f "$ALLOW_FILE" ] || { printf '# Caminhos/globs liberados (um por linha). Ex.: /var/www/html/meu_modulo/*\n' > "$ALLOW_FILE"; chmod 600 "$ALLOW_FILE"; }
  cat > /etc/cron.d/ipbx-sentinel <<'CRON'
# IPbx Sentinel - monitor anti-webshell (camada web)
*/5 * * * * root /usr/bin/flock -n /var/run/ipbx-sentinel.lock /usr/bin/nice -n 19 /usr/local/bin/ipbx-sentinel --incremental >/dev/null 2>&1
30 3 * * * root /usr/bin/flock -n /var/run/ipbx-sentinel.lock /usr/bin/nice -n 19 /usr/local/bin/ipbx-sentinel --scan >/dev/null 2>&1
CRON
  chmod 644 /etc/cron.d/ipbx-sentinel
  echo "Sentinel instalado: /usr/local/bin/ipbx-sentinel + /etc/cron.d/ipbx-sentinel (a cada 5 min e varredura completa as 03:30)."
  echo "Telegram: usa /etc/ipbx/telegram.conf (TELEGRAM_BOT_TOKEN / TELEGRAM_CHAT_ID) ou o monitor ja instalado."
}

cmd_list() { if [ -s "$QUAR_ROOT/INDEX.tsv" ]; then awk -F'\t' '{printf "%s | %s | %s\n   -> %s\n", $1, $4, $2, $3}' "$QUAR_ROOT/INDEX.tsv"; else echo "Quarentena vazia."; fi; }

cmd_restore() {
  local f="$1" dest
  [ -n "$f" ] || { echo "Informe o caminho original."; return 1; }
  dest="$(awk -F'\t' -v p="$f" '$2==p {d=$3} END{print d}' "$QUAR_ROOT/INDEX.tsv" 2>/dev/null)"
  [ -n "$dest" ] && [ -e "$dest" ] || { echo "Nao encontrado na quarentena: $f"; return 1; }
  chmod 644 "$dest" 2>/dev/null; mkdir -p "$(dirname "$f")" && mv -f "$dest" "$f" && echo "Restaurado: $f"
  echo "Dica: para nao ser pego de novo, adicione o caminho em $ALLOW_FILE"
}

cmd_selftest() {
  local T; T="$(mktemp -d)"; local ok=0 bad=0
  mkdir -p "$T/web/admin/modules/phones" "$T/web/admin/modules/core" "$T/web/images" "$T/web/admin/libraries/php-upgrade" "$T/web/modules"
  echo '<?php /* Emad__Was__Here */ ?>'                       > "$T/web/modules/a_emad.php"
  echo '<?php eval($Yv($qjOe($Ad("AAAA")))); ?>'              > "$T/web/modules/b_chain.php"
  echo '<?php eval(base64_decode("AAAA")); ?>'                > "$T/web/modules/c_decode.php"
  echo '<?php echo "ola"; ?>'                                  > "$T/web/images/d_static.php"
  echo '<?php echo "ok"; ?>'                                   > "$T/web/modules/legitimo.php"
  echo '<?php eval($x($y("z"))); ?>'                          > "$T/web/admin/libraries/php-upgrade/compat.php"
  echo '<?php $v = doubleval($a); ?>'                          > "$T/web/admin/modules/core/XML_Unserializer.php"
  echo 'x' > "$T/web/admin/modules/phones/index.php"
  ( WEB_DIR="$T/web" SENTINEL_QUAR="$T/quar" SENTINEL_LOG="$T/log" SENTINEL_NO_ALERT=1 SENTINEL_CONF=/nonexistent SENTINEL_ALLOW=/nonexistent "$0" --scan >/dev/null 2>&1 )
  chk() { if [ "$2" = "gone" ] && [ ! -e "$1" ]; then ok=$((ok+1)); elif [ "$2" = "kept" ] && [ -e "$1" ]; then ok=$((ok+1)); else bad=$((bad+1)); echo "FALHA: $1 deveria estar '$2'"; fi; }
  chk "$T/web/modules/a_emad.php" gone;  chk "$T/web/modules/b_chain.php" gone;  chk "$T/web/modules/c_decode.php" gone
  chk "$T/web/images/d_static.php" gone; chk "$T/web/admin/modules/phones" gone
  chk "$T/web/modules/legitimo.php" kept; chk "$T/web/admin/libraries/php-upgrade/compat.php" kept; chk "$T/web/admin/modules/core/XML_Unserializer.php" kept
  rm -rf "$T"
  echo "Selftest: $ok ok, $bad falha(s)."; [ "$bad" -eq 0 ]
}

case "${1:-}" in
  --install)     cmd_install ;;
  --uninstall)   rm -f /etc/cron.d/ipbx-sentinel; echo "Cron do Sentinel removido." ;;
  --list)        cmd_list ;;
  --restore)     cmd_restore "$2" ;;
  --selftest)    cmd_selftest ;;
  --test-alert)  send_telegram "Teste do IPbx Sentinel em ${HOSTNAME_S} - $(date '+%d/%m/%Y %H:%M:%S'). Se esta mensagem chegou, o alerta esta funcionando." ; echo "Enviado (se o Telegram estiver configurado)." ;;
  --incremental) do_scan inc ;;
  --scan|"")     do_scan full ;;
  *) echo "Uso: $0 [--scan|--incremental|--install|--uninstall|--list|--restore ARQ|--test-alert|--selftest]"; exit 1 ;;
esac
exit 0
