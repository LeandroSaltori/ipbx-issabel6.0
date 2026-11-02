#!/bin/bash
# ==============================================================================
# IPbx Prisma - ATUALIZACAO AUTOMATICA CONTINUA
# ==============================================================================
# Depois de instalado (uma unica vez, pelos comandos de atualizacao normais), o servidor
# passa a buscar sozinho as novidades do repositorio e a aplicar, com protecao:
#   1. a cada 10 min compara o commit remoto com o ultimo aplicado (consulta leve);
#   2. se ha novidade, baixa uma copia limpa e valida a sintaxe (bash/PHP) antes de tocar em algo;
#   3. espera nao haver chamadas em andamento (no maximo 4 h; depois aplica mesmo assim);
#   4. tira um snapshot do codigo (nunca dos dados: bancos, chaves, usuarios e agendas ficam como estao);
#   5. aplica com os mesmos comandos de sempre (instalar-front-api.sh + ipbx-sentinel --install);
#   6. testa o painel e o Asterisk; se algo falhar, volta sozinho ao snapshot e avisa no Telegram;
#   7. uma versao que falhou nao e repetida em loop: so tenta de novo quando sair uma versao nova.
#
# Comandos:
#   ipbx-autoupdate            verifica e aplica se houver novidade (usado pelo cron)
#   ipbx-autoupdate --now      igual, sem espera aleatoria e ignorando a espera por chamadas
#   ipbx-autoupdate --status   mostra versao aplicada, ultima execucao e se esta ligado
#   ipbx-autoupdate --snapshots lista os snapshots; --rollback [ARQ] volta o codigo ao snapshot (padrao: o ultimo)
#   ipbx-autoupdate --on|--off liga/desliga a atualizacao automatica deste servidor
#   ipbx-autoupdate --install  instala comando e cron (chamado pelos instaladores)
#   ipbx-autoupdate --selftest testa a logica com um repositorio falso
# Configuracao opcional: /etc/ipbx/autoupdate.conf  (AUTOUPDATE=1, AU_BRANCH=main, AU_REPO_URL=...)
# ==============================================================================

AU_CONF="${AU_CONF:-/etc/ipbx/autoupdate.conf}"
AU_STATE="${AU_STATE:-/var/lib/ipbx-autoupdate}"
AU_LOG="${AU_LOG:-/var/log/ipbx-autoupdate.log}"
AU_BACKUP="${AU_BACKUP:-/var/backup/ipbx/autoupdate}"
AU_DEST_DIR="${AU_DEST_DIR:-/var/www/html/front_api}"
AU_DEFAULT_URL="https://github.com/LeandroSaltori/ipbx-issabel6.0"
AU_MAX_DEFER="${AU_MAX_DEFER:-14400}"   # segundos esperando chamadas terminarem

AUTOUPDATE=1; AU_BRANCH="${AU_BRANCH:-main}"; AU_REPO_URL="${AU_REPO_URL:-$AU_DEFAULT_URL}"
[ -r "$AU_CONF" ] && . "$AU_CONF" 2>/dev/null
[ -n "$AU_BRANCH" ] || AU_BRANCH=main
[ -n "$AU_REPO_URL" ] || AU_REPO_URL="$AU_DEFAULT_URL"

log() { echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" >> "$AU_LOG" 2>/dev/null; [ -t 1 ] && echo "$*"; return 0; }

notify() {  # $1 = mensagem (primeira linha = titulo); usa o Telegram do Sentinel
  [ "${AU_NO_NOTIFY:-0}" = "1" ] && return 0
  local s=/usr/local/bin/ipbx-sentinel
  [ -x "$s" ] && "$s" --notify "$1" >/dev/null 2>&1
  return 0
}

active_calls() {
  command -v asterisk >/dev/null 2>&1 || { echo 0; return; }
  local n; n="$(asterisk -rx 'core show channels count' 2>/dev/null | awk '/active call/{print $1; exit}')"
  echo "${n:-0}"
}

panel_http() {  # codigo HTTP do painel local (000 = nao respondeu)
  [ -n "$AU_HEALTH_URL" ] || { local c; c="$(curl -sk -m 15 -o /dev/null -w '%{http_code}' https://127.0.0.1/front_api/ 2>/dev/null)"; [ "$c" = "000" ] && c="$(curl -s -m 15 -o /dev/null -w '%{http_code}' http://127.0.0.1/front_api/ 2>/dev/null)"; echo "${c:-000}"; return; }
  curl -sk -m 15 -o /dev/null -w '%{http_code}' "$AU_HEALTH_URL" 2>/dev/null || echo 000
}
ast_state() { systemctl is-active asterisk 2>/dev/null || echo unknown; }

health_ok() {  # $1 = http antes, $2 = asterisk antes
  local h a; h="$(panel_http)"; a="$(ast_state)"
  case "$h" in 5*|000) case "$1" in 5*|000) ;; *) log "Teste de saude: painel respondeu $h (antes: $1)"; return 1 ;; esac ;; esac
  if [ "$2" = "active" ] && [ "$a" != "active" ]; then log "Teste de saude: Asterisk nao esta ativo ($a)"; return 1; fi
  [ -f "$AU_DEST_DIR/index.php" ] && command -v php >/dev/null 2>&1 && { php -l "$AU_DEST_DIR/index.php" >/dev/null 2>&1 || { log "Teste de saude: index.php com erro de sintaxe"; return 1; }; }
  return 0
}

validate_tree() {  # $1 = pasta clonada: sintaxe de todos os scripts antes de aplicar
  local d="$1" f bad=0
  while IFS= read -r f; do bash -n "$f" 2>/dev/null || { log "Validacao: erro de sintaxe em ${f#$d/}"; bad=1; }; done < <(find "$d" -maxdepth 3 -name '*.sh' -not -path '*/.git/*' 2>/dev/null)
  if command -v php >/dev/null 2>&1; then
    while IFS= read -r f; do php -l "$f" >/dev/null 2>&1 || { log "Validacao: erro de sintaxe em ${f#$d/}"; bad=1; }; done < <(find "$d/src/front_api" -name '*.php' -not -path '*/vendor/*' 2>/dev/null)
  fi
  return $bad
}

make_snapshot() {  # $1 = arquivo .tar.gz de saida. So codigo: bancos/chaves ficam de fora e nunca sao revertidos.
  mkdir -p "$(dirname "$1")"
  local items=() p
  local list=("$AU_DEST_DIR")
  [ "${AU_NO_SYS:-0}" = "1" ] || list+=(/usr/local/bin/ipbx-sentinel /usr/local/bin/ipbx-autoupdate /etc/cron.d/ipbx-front-api /etc/cron.d/ipbx-sentinel /etc/cron.d/ipbx-autoupdate)
  for p in "${list[@]}"; do
    [ -e "$p" ] && items+=("${p#/}")
  done
  [ ${#items[@]} -gt 0 ] || return 1
  tar -czf "$1" -C / --exclude='*.sqlite' --exclude='*.sqlite-wal' --exclude='*.sqlite-shm' --exclude='*.db' --exclude='*.log' --exclude='*/logs/*' "${items[@]}" 2>/dev/null
}

restore_snapshot() { tar -xzf "$1" -C / 2>/dev/null; }

cmd_status() {
  echo "Atualizacao automatica: $([ "$AUTOUPDATE" = "1" ] && echo LIGADA || echo DESLIGADA)  (canal: $AU_BRANCH)"
  echo "Versao aplicada: $(cat "$AU_STATE/applied" 2>/dev/null | cut -c1-8) $(cat "$AU_STATE/applied_msg" 2>/dev/null)"
  echo "Ultimo resultado: $(cat "$AU_STATE/last_result" 2>/dev/null || echo 'nenhuma execucao ainda')"
  [ -s "$AU_STATE/bad" ] && echo "Versao com falha (ignorada ate sair outra): $(cut -c1-8 "$AU_STATE/bad")"
  tail -n 5 "$AU_LOG" 2>/dev/null
}

cmd_snapshots() { ls -1t "$AU_BACKUP"/*.tar.gz 2>/dev/null | while read -r f; do echo "$(basename "$f")  ($(du -h "$f" | cut -f1))  versao anterior: $(cut -c1-8 "${f%.tar.gz}.sha" 2>/dev/null)"; done; [ -n "$(ls "$AU_BACKUP"/*.tar.gz 2>/dev/null)" ] || echo "Nenhum snapshot ainda."; }

# Rollback manual: volta o CODIGO ao snapshot (o mais recente, ou o informado). Dados do cliente nao sao tocados.
# A versao que estava no repositorio fica marcada como "ruim": o servidor so volta a atualizar quando sair outra.
cmd_rollback() {
  local snap="$1"
  [ -n "$snap" ] || snap="$(ls -1t "$AU_BACKUP"/*.tar.gz 2>/dev/null | head -1)"
  [ -n "$snap" ] && [ -f "$snap" ] || snap="$AU_BACKUP/$1"
  [ -f "$snap" ] || { echo "Snapshot nao encontrado. Veja: ipbx-autoupdate --snapshots"; exit 1; }
  echo "Voltando ao snapshot $(basename "$snap") ..."
  restore_snapshot "$snap" || { echo "ERRO ao restaurar."; exit 1; }
  local prev remote; prev="$(cat "${snap%.tar.gz}.sha" 2>/dev/null)"
  remote="$(git ls-remote "$AU_REPO_URL" "refs/heads/$AU_BRANCH" 2>/dev/null | awk '{print $1; exit}')"
  mkdir -p "$AU_STATE"
  [ -n "$prev" ] && echo "$prev" > "$AU_STATE/applied"
  [ -n "$remote" ] && echo "$remote" > "$AU_STATE/bad"
  echo "ROLLBACK manual para $(basename "$snap") em $(date '+%d/%m/%Y %H:%M')" > "$AU_STATE/last_result"
  log "ROLLBACK manual: $(basename "$snap")"
  notify "Rollback executado"$'\n'"Codigo voltou ao snapshot $(basename "$snap"). A atualizacao automatica aguarda uma versao nova."
  [ -x /usr/local/bin/ipbx-sentinel ] && /usr/local/bin/ipbx-sentinel --install --no-update >/dev/null 2>&1
  echo "Pronto. Painel e Sentinel voltaram a versao anterior; dados preservados."
}

set_flag() { mkdir -p "$(dirname "$AU_CONF")"; touch "$AU_CONF"; sed -i '/^AUTOUPDATE=/d' "$AU_CONF"; echo "AUTOUPDATE=$1" >> "$AU_CONF"; chmod 600 "$AU_CONF" 2>/dev/null; }

cmd_install() {
  mkdir -p "$AU_STATE" "$(dirname "$AU_CONF")" 2>/dev/null
  local self; self="$(readlink -f "$0" 2>/dev/null || echo "$0")"
  if [ -f "$self" ] && [ "$self" != "/usr/local/bin/ipbx-autoupdate" ]; then
    # copia atomica: se este proprio script estiver rodando, o processo segue com o arquivo antigo
    /bin/cp -f "$self" /usr/local/bin/.ipbx-autoupdate.new && chmod +x /usr/local/bin/.ipbx-autoupdate.new && mv -f /usr/local/bin/.ipbx-autoupdate.new /usr/local/bin/ipbx-autoupdate
  fi
  [ -f "$AU_CONF" ] || { printf '# IPbx - atualizacao automatica\nAUTOUPDATE=1\nAU_BRANCH=main\n' > "$AU_CONF"; chmod 600 "$AU_CONF"; }
  cat > /etc/cron.d/ipbx-autoupdate <<'CRON'
# IPbx Prisma - atualizacao automatica (verificacao leve a cada 10 min; so aplica quando ha versao nova)
*/10 * * * * root /usr/bin/flock -n /var/run/ipbx-autoupdate.lock /usr/bin/nice -n 19 /usr/local/bin/ipbx-autoupdate >/dev/null 2>&1
CRON
  chmod 644 /etc/cron.d/ipbx-autoupdate
  rm -f /etc/cron.weekly/ipbx-autoupdate   # rotina semanal antiga, substituida por esta
  # Marca a versao que acabou de ser instalada manualmente como "ja aplicada" (evita reaplicar)
  if [ -z "$IPBX_AUTOUPDATE_RUNNING" ]; then
    local root; root="$(cd "$(dirname "$self")/.." 2>/dev/null && pwd)"
    if [ -d "$root/.git" ]; then
      git -C "$root" rev-parse HEAD > "$AU_STATE/applied" 2>/dev/null
      git -C "$root" log -1 --format=%s > "$AU_STATE/applied_msg" 2>/dev/null
      rm -f "$AU_STATE/bad" "$AU_STATE/defer_since"
    fi
  fi
  echo "Atualizacao automatica instalada: verifica novidades a cada 10 min (ipbx-autoupdate --status)."
}

run_update() {  # $1 = "now" ignora jitter e espera de chamadas
  [ "$AUTOUPDATE" = "1" ] || exit 0
  mkdir -p "$AU_STATE" 2>/dev/null
  [ "$1" = "now" ] || sleep $((RANDOM % 60))   # espalha a consulta entre os servidores

  local remote applied
  remote="$(git ls-remote "$AU_REPO_URL" "refs/heads/$AU_BRANCH" 2>/dev/null | awk '{print $1; exit}')"
  [ -n "$remote" ] || { log "Sem acesso ao repositorio (verificacao adiada)."; exit 0; }
  applied="$(cat "$AU_STATE/applied" 2>/dev/null)"
  [ "$remote" = "$applied" ] && { rm -f "$AU_STATE/defer_since"; exit 0; }
  [ "$remote" = "$(cat "$AU_STATE/bad" 2>/dev/null)" ] && exit 0   # versao que ja falhou aqui: espera uma nova

  # Espera as chamadas terminarem (no maximo AU_MAX_DEFER segundos)
  if [ "$1" != "now" ]; then
    local calls; calls="$(active_calls)"
    if [ "${calls:-0}" -gt 0 ]; then
      [ -f "$AU_STATE/defer_since" ] || date +%s > "$AU_STATE/defer_since"
      if [ $(( $(date +%s) - $(cat "$AU_STATE/defer_since") )) -lt "$AU_MAX_DEFER" ]; then
        log "Versao ${remote:0:8} disponivel; ${calls} chamada(s) em andamento, aguardando."; exit 0
      fi
      log "Espera por chamadas excedeu o limite; aplicando mesmo assim."
    fi
  fi
  rm -f "$AU_STATE/defer_since"

  local work; work="$(mktemp -d /tmp/ipbx-au.XXXXXX)" || exit 1
  trap 'rm -rf "$work"' EXIT
  log "Nova versao ${remote:0:8} disponivel (aplicada: ${applied:0:8}). Baixando..."
  if ! git clone -q --depth 1 -b "$AU_BRANCH" "$AU_REPO_URL" "$work/repo" 2>>"$AU_LOG"; then
    log "Falha ao baixar o repositorio (tenta de novo no proximo ciclo)."; exit 0
  fi
  local got msg; got="$(git -C "$work/repo" rev-parse HEAD)"; msg="$(git -C "$work/repo" log -1 --format=%s)"
  if ! validate_tree "$work/repo"; then
    echo "$got" > "$AU_STATE/bad"; echo "ERRO: versao com erro de sintaxe, nao aplicada ($(date '+%d/%m %H:%M'))" > "$AU_STATE/last_result"
    notify "FALHA: ATUALIZACAO AUTOMATICA NAO APLICADA"$'\n'"A versao ${got:0:8} (${msg}) tem erro de sintaxe e foi ignorada. Nada foi alterado neste servidor."
    exit 0
  fi

  local snap="$AU_BACKUP/$(date '+%Y-%m-%d_%H%M%S').tar.gz" http_before ast_before
  http_before="$(panel_http)"; ast_before="$(ast_state)"
  if ! make_snapshot "$snap"; then
    log "Nao foi possivel criar o snapshot; atualizacao cancelada por seguranca."
    echo "ERRO: sem snapshot, nao aplicado ($(date '+%d/%m %H:%M'))" > "$AU_STATE/last_result"; exit 0
  fi
  printf '%s\n' "$applied" > "${snap%.tar.gz}.sha"
  log "Snapshot gravado em $snap. Aplicando ${got:0:8} - ${msg}"

  export IPBX_AUTOUPDATE_RUNNING=1
  local ok=1
  if [ -n "$AU_APPLY_CMD" ]; then
    ( cd "$work/repo" && eval "$AU_APPLY_CMD" ) </dev/null >>"$AU_LOG" 2>&1 || ok=0
  else
    ( bash "$work/repo/src/front_api/instalar-front-api.sh" && bash "$work/repo/scripts/ipbx-sentinel.sh" --install ) </dev/null >>"$AU_LOG" 2>&1 || ok=0
  fi
  [ "$ok" = "1" ] && { health_ok "$http_before" "$ast_before" || ok=0; }

  if [ "$ok" = "1" ]; then
    echo "$got" > "$AU_STATE/applied"; echo "$msg" > "$AU_STATE/applied_msg"; rm -f "$AU_STATE/bad"
    echo "SUCESSO: ${got:0:8} aplicada em $(date '+%d/%m/%Y %H:%M')" > "$AU_STATE/last_result"
    log "SUCESSO: atualizado para ${got:0:8} - ${msg}"
    ls -1t "$AU_BACKUP"/*.tar.gz 2>/dev/null | tail -n +6 | while read -r o; do rm -f "$o" "${o%.tar.gz}.sha"; done   # guarda os 5 snapshots mais recentes
    notify "Atualizacao automatica concluida com sucesso"$'\n'"Versao ${got:0:8}: ${msg}"
    exit 0
  fi

  log "FALHA ao aplicar ${got:0:8}; voltando ao snapshot anterior."
  restore_snapshot "$snap"
  echo "$got" > "$AU_STATE/bad"
  echo "FALHA: ${got:0:8} revertida automaticamente em $(date '+%d/%m/%Y %H:%M')" > "$AU_STATE/last_result"
  if health_ok "$http_before" "$ast_before"; then
    notify "FALHA: ATUALIZACAO REVERTIDA AUTOMATICAMENTE"$'\n'"A versao ${got:0:8} (${msg}) falhou neste servidor e o sistema voltou ao estado anterior. O servidor segue funcionando. Veja /var/log/ipbx-autoupdate.log"
  else
    notify "ALERTA: ATUALIZACAO FALHOU E O PAINEL NAO VOLTOU"$'\n'"Versao ${got:0:8} (${msg}). O snapshot foi restaurado, mas o teste de saude ainda falha: verificar o servidor. Log: /var/log/ipbx-autoupdate.log"
  fi
  exit 1
}

cmd_selftest() {
  local T ok=0 bad=0; T="$(mktemp -d)"
  local R="$T/remote.git" W="$T/w" self; self="$(readlink -f "$0")"
  git init -q --bare -b main "$R" 2>/dev/null || { git init -q --bare "$R"; git -C "$R" symbolic-ref HEAD refs/heads/main; }
  git clone -q "$R" "$W" 2>/dev/null; git -C "$W" config user.email t@t; git -C "$W" config user.name t
  mkdir -p "$W/src/front_api" "$T/root/var/www/html/front_api"
  echo '<?php echo 1;' > "$W/src/front_api/index.php"; echo v1 > "$W/src/front_api/code.txt"
  git -C "$W" add -A; git -C "$W" commit -qm v1; git -C "$W" push -q origin HEAD:main
  echo v1 > "$T/root/var/www/html/front_api/code.txt"; echo "DADOS-DO-CLIENTE" > "$T/root/var/www/html/front_api/dados.sqlite"
  git -C "$W" rev-parse HEAD > "$T/state-applied"
  local env=(AU_CONF=/nonexistent AU_STATE="$T/st" AU_LOG="$T/log" AU_BACKUP="$T/bk" AU_DEST_DIR="$T/root/var/www/html/front_api" AU_NO_SYS=1 AU_REPO_URL="$R" AU_BRANCH=main AU_NO_NOTIFY=1 AU_HEALTH_URL=http://127.0.0.1:9/x)
  mkdir -p "$T/st"; cp "$T/state-applied" "$T/st/applied"
  chk() { if [ "$2" = "$3" ]; then ok=$((ok+1)); else bad=$((bad+1)); echo "FALHA: $1 (obtido '$2', esperado '$3')"; fi; }
  local APPLY='cp src/front_api/code.txt "'"$T"'/root/var/www/html/front_api/code.txt" && ! grep -q BOOM src/front_api/code.txt'
  run() { env "${env[@]}" AU_APPLY_CMD="$APPLY" bash "$self" --now >/dev/null 2>&1; }
  # 1) sem novidade: nada muda
  run; chk "sem novidade nao altera" "$(cat "$T/root/var/www/html/front_api/code.txt")" "v1"
  # 2) versao nova valida: aplica e guarda sha (saude: AU_HEALTH_URL inalcancavel nos dois lados = nao piorou)
  echo v2 > "$W/src/front_api/code.txt"; git -C "$W" commit -qam v2; git -C "$W" push -q origin HEAD:main
  run; chk "aplica versao nova" "$(cat "$T/root/var/www/html/front_api/code.txt")" "v2"
  chk "guarda sha aplicado" "$(cat "$T/st/applied")" "$(git -C "$W" rev-parse HEAD)"
  # 3) versao que falha ao aplicar: reverte codigo, preserva dados, marca como ruim
  echo BOOM > "$W/src/front_api/code.txt"; git -C "$W" commit -qam boom; git -C "$W" push -q origin HEAD:main
  run; chk "reverte codigo apos falha" "$(cat "$T/root/var/www/html/front_api/code.txt")" "v2"
  chk "dados do cliente intactos" "$(cat "$T/root/var/www/html/front_api/dados.sqlite")" "DADOS-DO-CLIENTE"
  chk "versao ruim marcada" "$(cat "$T/st/bad")" "$(git -C "$W" rev-parse HEAD)"
  chk "sha aplicado nao avancou" "$(cat "$T/st/applied")" "$(git -C "$W" rev-parse HEAD~1)"
  # 4) versao ruim nao e repetida em loop
  : > "$T/log"; run; chk "nao repete versao ruim" "$(grep -c 'Baixando' "$T/log")" "0"
  # 5) erro de sintaxe e barrado antes de aplicar
  printf '#!/bin/bash\nif then\n' > "$W/x.sh"; echo v3 > "$W/src/front_api/code.txt"; git -C "$W" add -A; git -C "$W" commit -qm sintaxe; git -C "$W" push -q origin HEAD:main
  run; chk "sintaxe invalida nao aplica" "$(cat "$T/root/var/www/html/front_api/code.txt")" "v2"
  # 6) corrigida a versao, volta a aplicar
  git -C "$W" rm -qf x.sh; git -C "$W" commit -qm fix; git -C "$W" push -q origin HEAD:main
  run; chk "versao corrigida aplica" "$(cat "$T/root/var/www/html/front_api/code.txt")" "v3"
  # 6b) rollback manual volta ao snapshot e nao reaplica a mesma versao
  env "${env[@]}" bash "$self" --rollback >/dev/null 2>&1
  chk "rollback manual restaura codigo" "$(cat "$T/root/var/www/html/front_api/code.txt")" "v2"
  chk "rollback preserva dados" "$(cat "$T/root/var/www/html/front_api/dados.sqlite")" "DADOS-DO-CLIENTE"
  run; chk "apos rollback nao reaplica a mesma versao" "$(cat "$T/root/var/www/html/front_api/code.txt")" "v2"
  git -C "$W" commit -q --allow-empty -m v3b; echo v3b > "$W/src/front_api/code.txt"; git -C "$W" commit -qam v3b2; git -C "$W" push -q origin HEAD:main
  run; chk "versao nova apos rollback aplica" "$(cat "$T/root/var/www/html/front_api/code.txt")" "v3b"
  git -C "$W" push -q origin HEAD:main
  # 7) desligado nao atualiza
  echo v4 > "$W/src/front_api/code.txt"; git -C "$W" commit -qam v4; git -C "$W" push -q origin HEAD:main
  printf 'AUTOUPDATE=0\n' > "$T/off.conf"; env "${env[@]}" AU_CONF="$T/off.conf" AU_APPLY_CMD="$APPLY" bash "$self" --now >/dev/null 2>&1
  chk "desligado nao atualiza" "$(cat "$T/root/var/www/html/front_api/code.txt")" "v3b"
  [ -n "$AU_DEBUG" ] && cat "$T/log"
  rm -rf "$T"
  echo "Selftest autoupdate: $ok ok, $bad falha(s)."; [ "$bad" -eq 0 ]
}

case "${1:-}" in
  --install)  cmd_install ;;
  --status)   cmd_status ;;
  --snapshots) cmd_snapshots ;;
  --rollback) cmd_rollback "$2" ;;
  --on)       set_flag 1; echo "Atualizacao automatica LIGADA." ;;
  --off)      set_flag 0; echo "Atualizacao automatica DESLIGADA neste servidor." ;;
  --selftest) cmd_selftest ;;
  --now)      run_update now ;;
  "")         run_update ;;
  *) echo "Uso: $0 [--now|--status|--snapshots|--rollback [ARQ]|--on|--off|--install|--selftest]"; exit 1 ;;
esac
