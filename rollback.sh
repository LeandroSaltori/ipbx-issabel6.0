#!/bin/bash
# ==============================================================================
# SCRIPT DE ROLLBACK COMPLETO - IPBX ISSABEL (PRISMA TELECOM)
# ==============================================================================
# Este script reverte TODAS as alterações feitas pelo install.sh.
# Restaura os backups _old, remove configurações injetadas no Asterisk,
# desfaz entradas de menu/ACL nos bancos SQLite e remove serviços adicionados.
#
# Uso:
#   bash rollback.sh          → Rollback completo (interativo, pede confirmação)
#   bash rollback.sh --force  → Rollback completo sem perguntar
#   bash rollback.sh --dry-run → Mostra o que seria feito, sem alterar nada
#
# IMPORTANTE: Execute como root.
# ==============================================================================

set -euo pipefail

# --- CORES PARA LOGS ---
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
MAGENTA='\033[0;35m'
NC='\033[0m' # No Color

# --- FLAGS ---
DRY_RUN=false
FORCE=false
ROLLBACK_LOG="/var/log/ipbx-rollback.log"

for arg in "$@"; do
    case "$arg" in
        --dry-run) DRY_RUN=true ;;
        --force)   FORCE=true ;;
        --help|-h)
            echo -e "${CYAN}Uso:${NC}"
            echo "  bash rollback.sh            → Rollback completo (interativo)"
            echo "  bash rollback.sh --force     → Rollback sem confirmação"
            echo "  bash rollback.sh --dry-run   → Simula o rollback (não altera nada)"
            echo "  bash rollback.sh --help      → Mostra esta ajuda"
            exit 0
            ;;
    esac
done

# --- FUNÇÕES DE LOG ---
log_info()    { echo -e "${CYAN}[INFO]${NC} $1"; }
log_success() { echo -e "${GREEN}[RESTAURADO]${NC} $1"; }
log_warn()    { echo -e "${YELLOW}[AVISO]${NC} $1"; }
log_error()   { echo -e "${RED}[ERRO]${NC} $1"; }
log_dry()     { echo -e "${MAGENTA}[DRY-RUN]${NC} $1"; }
log_step()    { echo -e "\n${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"; echo -e "${BLUE}  $1${NC}"; echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"; }

log_to_file() {
    local timestamp
    timestamp=$(date '+%Y-%m-%d %H:%M:%S')
    echo "[$timestamp] $1" >> "$ROLLBACK_LOG" 2>/dev/null || true
}

# Executa um comando real ou apenas mostra (dry-run)
run_cmd() {
    if $DRY_RUN; then
        log_dry "Executaria: $*"
    else
        log_to_file "EXEC: $*"
        eval "$@"
    fi
}

# --- VERIFICAÇÃO DE PERMISSÃO ROOT ---
if [ "$EUID" -ne 0 ]; then
    log_error "Este script precisa ser executado como root!"
    exit 1
fi

# --- BANNER INICIAL ---
echo -e "\n${RED}╔══════════════════════════════════════════════════════════════════════╗${NC}"
echo -e "${RED}║          ROLLBACK COMPLETO - IPBX ISSABEL (PRISMA TELECOM)         ║${NC}"
echo -e "${RED}║                                                                    ║${NC}"
echo -e "${RED}║  Este script irá REVERTER todas as alterações feitas pelo          ║${NC}"
echo -e "${RED}║  install.sh, restaurando o estado ORIGINAL do servidor.            ║${NC}"
echo -e "${RED}╚══════════════════════════════════════════════════════════════════════╝${NC}\n"

if $DRY_RUN; then
    echo -e "${MAGENTA}▶ MODO DRY-RUN: Nenhuma alteração será feita. Apenas simulação.${NC}\n"
fi

# --- CONFIRMAÇÃO INTERATIVA ---
if ! $FORCE && ! $DRY_RUN; then
    echo -e "${YELLOW}⚠  ATENÇÃO: Esta operação irá reverter TODAS as customizações IPBX.${NC}"
    echo -e "${YELLOW}   Os seguintes itens serão restaurados/removidos:${NC}"
    echo ""
    echo "   • Pastas /var/www/html/admin, lang, modules → restauradas do backup _old"
    echo "   • Tema Prisma v5 → removido"
    echo "   • Webphone WebRTC → removido"
    echo "   • Servidor LDAP de Ramais → desinstalado"
    echo "   • ChanSpy e TextMessages → removidos do dialplan"
    echo "   • Pesquisa de Satisfação → removida (dialplan + módulo web + banco)"
    echo "   • Relatório de Filas → removido"
    echo "   • Asternic CDR → restaurado do backup"
    echo "   • MOTD personalizado → restaurado"
    echo "   • User-Agent PJSIP → restaurado"
    echo "   • Entradas de menu/ACL no Issabel → removidas"
    echo "   • Crontab (parselog, monitor_issabel, autoupdate) → removidos"
    echo ""

    # Detecta se está sendo executado via pipe (curl | bash) — nesse caso /dev/tty é necessário
    if [ -t 0 ]; then
        # stdin é terminal — lê normalmente
        read -rp "$(echo -e "${RED}Deseja continuar com o ROLLBACK? (sim/nao): ${NC}")" CONFIRMA
    elif [ -e /dev/tty ]; then
        # stdin é pipe mas /dev/tty existe — lê do terminal real
        read -rp "$(echo -e "${RED}Deseja continuar com o ROLLBACK? (sim/nao): ${NC}")" CONFIRMA < /dev/tty
    else
        # Sem terminal disponível — executa direto (comportamento --force)
        log_warn "Sem terminal interativo detectado. Executando rollback automaticamente..."
        CONFIRMA="sim"
    fi

    if [[ "$CONFIRMA" != "sim" && "$CONFIRMA" != "s" && "$CONFIRMA" != "SIM" && "$CONFIRMA" != "S" ]]; then
        echo -e "${GREEN}Rollback cancelado pelo usuário.${NC}"
        exit 0
    fi
    echo ""
fi

log_to_file "=========================================="
log_to_file "INÍCIO DO ROLLBACK"
log_to_file "Modo: $(if $DRY_RUN; then echo 'DRY-RUN'; else echo 'EXECUÇÃO REAL'; fi)"
log_to_file "=========================================="

ERRORS=0

# ==============================================================================
# FUNÇÃO: Restaurar backup _old → original
# ==============================================================================
restore_old_backup() {
    local dest="$1"
    local backup="${dest}_old"

    if [ -e "$backup" ]; then
        if $DRY_RUN; then
            log_dry "Restauraria: $backup → $dest"
        else
            rm -rf "$dest"
            mv "$backup" "$dest"
            log_success "$backup → $dest"
            log_to_file "RESTAURADO: $backup → $dest"
        fi
    else
        log_warn "Backup não encontrado: $backup (nada a restaurar)"
    fi
}

# ==============================================================================
# FUNÇÃO: Remover bloco injetado de arquivo de configuração
# ==============================================================================
remove_config_block() {
    local file="$1"
    local start_marker="$2"
    local end_marker="${3:-}"

    if [ ! -f "$file" ]; then
        log_warn "Arquivo não existe: $file"
        return
    fi

    if grep -q "$start_marker" "$file" 2>/dev/null; then
        if $DRY_RUN; then
            log_dry "Removeria bloco '$start_marker' de $file"
        else
            # Cria backup do arquivo antes de alterar
            cp -f "$file" "${file}.pre_rollback"

            if [ -n "$end_marker" ]; then
                # Remove do marcador inicial até o marcador final (inclusive)
                sed -i "/$start_marker/,/$end_marker/d" "$file"
            else
                # Remove do marcador até o fim do arquivo
                sed -i "/$start_marker/,\$d" "$file"
            fi
            log_success "Bloco '$start_marker' removido de $file"
            log_to_file "BLOCO REMOVIDO: '$start_marker' de $file"
        fi
    else
        log_info "Bloco '$start_marker' não encontrado em $file (já limpo)"
    fi
}

# ==============================================================================
# 1. RESTAURAR PASTAS PRINCIPAIS COM BACKUP _old
# ==============================================================================
log_step "1/12 - Restaurando pastas principais a partir dos backups _old"

restore_old_backup "/var/www/html/admin"
restore_old_backup "/var/www/html/lang"
restore_old_backup "/usr/local/sbin/motd.sh"

# Para modules, o install.sh faz cp -rf (não mv), então verifica modules_old
if [ -d "/var/www/html/modules_old" ]; then
    if $DRY_RUN; then
        log_dry "Restauraria: /var/www/html/modules_old → /var/www/html/modules"
    else
        rm -rf /var/www/html/modules
        mv /var/www/html/modules_old /var/www/html/modules
        chown -R asterisk:asterisk /var/www/html/modules
        log_success "/var/www/html/modules_old → /var/www/html/modules"
    fi
else
    log_warn "Backup /var/www/html/modules_old não encontrado"
fi

# Asternic CDR backup específico
if [ -d "/var/www/html/admin/modules/asternic_cdr_OLD" ]; then
    if $DRY_RUN; then
        log_dry "Restauraria: asternic_cdr_OLD → asternic_cdr"
    else
        rm -rf /var/www/html/admin/modules/asternic_cdr
        mv /var/www/html/admin/modules/asternic_cdr_OLD /var/www/html/admin/modules/asternic_cdr
        chown -R asterisk:asterisk /var/www/html/admin/modules/asternic_cdr
        log_success "Asternic CDR restaurado do backup _OLD"
    fi
fi

# ==============================================================================
# 2. REMOVER ARQUIVOS INSTALADOS (sem backup _old)
# ==============================================================================
log_step "2/12 - Removendo arquivos instalados sem backup"

REMOVE_FILES=(
    "/var/www/html/Agenda.php"
    "/var/www/html/agenda.php"
    "/var/www/html/call.php"
    "/var/www/html/favicon.ico"
)

REMOVE_DIRS=(
    "/var/www/html/webphone"
    "/var/www/html/nome_ramais"
    "/var/www/html/ramais"
    "/var/www/html/modules/nome_ramais"
    "/var/www/html/modules/ramais"
    "/var/www/html/modules/pesquisa"
    "/var/www/html/modules/cdrreport"
    "/var/www/html/modules/channelusage"
    "/var/www/html/modules/graphic_report"
    "/var/www/html/modules/summary_by_extension"
    "/var/www/html/modules/missed_calls"
    "/var/www/html/modules/relatorio_de_filas"
    "/var/www/html/modules/control_panel"
    "/var/www/html/Relatorio_de_filas"
    "/var/www/html/relatorio_de_filas"
    "/var/www/html/stats"
    "/var/www/html/themes/prisma_v5"
)

for f in "${REMOVE_FILES[@]}"; do
    if [ -f "$f" ]; then
        run_cmd "rm -f '$f'"
        $DRY_RUN || log_success "Removido: $f"
    fi
done

for d in "${REMOVE_DIRS[@]}"; do
    if [ -d "$d" ]; then
        run_cmd "rm -rf '$d'"
        $DRY_RUN || log_success "Removido: $d"
    fi
done

# ==============================================================================
# 3. REMOVER SERVIDOR LDAP
# ==============================================================================
log_step "3/12 - Removendo Servidor LDAP de Ramais"

if systemctl is-active issabel-ldap &>/dev/null || systemctl is-enabled issabel-ldap &>/dev/null; then
    run_cmd "systemctl stop issabel-ldap.service 2>/dev/null || true"
    run_cmd "systemctl disable issabel-ldap.service 2>/dev/null || true"
fi

if [ -f "/etc/systemd/system/issabel-ldap.service" ]; then
    run_cmd "rm -f /etc/systemd/system/issabel-ldap.service"
    run_cmd "systemctl daemon-reload"
    $DRY_RUN || log_success "Serviço LDAP removido"
fi

if [ -f "/usr/local/bin/issabel-ldap" ]; then
    run_cmd "rm -f /usr/local/bin/issabel-ldap"
    $DRY_RUN || log_success "Binário LDAP removido"
fi

# ==============================================================================
# 4. REVERTER DIALPLAN DO ASTERISK (ChanSpy, TextMessages, Pesquisa)
# ==============================================================================
log_step "4/12 - Revertendo configurações do Dialplan Asterisk"

CHANSPY_FILE="/etc/asterisk/extensions_override_issabelpbx.conf"
CUSTOM_EXT="/etc/asterisk/extensions_custom.conf"

# ChanSpy - remove o bloco injetado
remove_config_block "$CHANSPY_FILE" "; --- CONFIGURAÇÃO CHANSPY (PRISMA TELECOM) ---" "exten => _555X\.,n,Hangup"
# Fallback: remove pelo contexto [app-chanspy]
if [ -f "$CHANSPY_FILE" ] && grep -q "\[app-chanspy\]" "$CHANSPY_FILE" 2>/dev/null; then
    if $DRY_RUN; then
        log_dry "Removeria contexto [app-chanspy] de $CHANSPY_FILE"
    else
        cp -f "$CHANSPY_FILE" "${CHANSPY_FILE}.pre_rollback"
        # Remove de [app-chanspy] até a próxima seção ou fim do arquivo
        python3 -c "
import re, sys
with open('$CHANSPY_FILE', 'r') as f:
    content = f.read()
# Remove bloco [app-chanspy] até próximo contexto ou fim
content = re.sub(r'\n*\[app-chanspy\].*?(?=\n\[|\Z)', '', content, flags=re.DOTALL)
with open('$CHANSPY_FILE', 'w') as f:
    f.write(content)
" 2>/dev/null || sed -i '/\[app-chanspy\]/,/^$/d' "$CHANSPY_FILE" 2>/dev/null || true
        log_success "Contexto [app-chanspy] removido de $CHANSPY_FILE"
    fi
fi

# TextMessages - remove blocos injetados
remove_config_block "$CUSTOM_EXT" "; --- CONFIGURAÇÃO ENVIO MENSAGEM TEXTO PJSIP ---" "exten => s,n,Hangup()"
# Fallback
if [ -f "$CUSTOM_EXT" ] && grep -q "\[textmessages\]" "$CUSTOM_EXT" 2>/dev/null; then
    if ! $DRY_RUN; then
        cp -f "$CUSTOM_EXT" "${CUSTOM_EXT}.pre_rollback_txt"
        python3 -c "
import re
with open('$CUSTOM_EXT', 'r') as f:
    content = f.read()
content = re.sub(r'\n*\[textmessages\].*?\[send-text\].*?(?=\n\[|\Z)', '', content, flags=re.DOTALL)
with open('$CUSTOM_EXT', 'w') as f:
    f.write(content)
" 2>/dev/null || true
        log_success "Contexto [textmessages]/[send-text] removido"
    fi
fi

# Pesquisa de Satisfação - remove dialplan inteiro
for marker in "; --- PESQUISA DE SATISFAÇÃO (PRISMA TELECOM) ---" "[pesquisa-satisfação]" "[pesquisa]" "[menu1a5]" "[menu1a2]" "[fim]" "[menu2]"; do
    if [ -f "$CUSTOM_EXT" ] && grep -q "$marker" "$CUSTOM_EXT" 2>/dev/null; then
        if ! $DRY_RUN; then
            sed -i "/$marker/d" "$CUSTOM_EXT" 2>/dev/null || true
        fi
    fi
done

# Remove bloco completo da pesquisa (entre os marcadores)
if [ -f "$CUSTOM_EXT" ] && grep -q "pesquisa-satisfação" "$CUSTOM_EXT" 2>/dev/null; then
    if ! $DRY_RUN; then
        python3 -c "
import re
with open('$CUSTOM_EXT', 'r') as f:
    content = f.read()
# Remove tudo entre PESQUISA e Hangup final
content = re.sub(r'\n*; --- PESQUISA DE SATISFAÇÃO.*?exten => s,n,Hangup\n?', '', content, flags=re.DOTALL)
# Remove blocos remanescentes de pesquisa
for ctx in ['pesquisa-satisfação', 'pesquisa', 'menu', 'menu1a5', 'menu1a2', 'menu2', 'fim']:
    content = re.sub(rf'\n*\[{ctx}\].*?(?=\n\[|\Z)', '', content, flags=re.DOTALL)
with open('$CUSTOM_EXT', 'w') as f:
    f.write(content)
" 2>/dev/null || true
        log_success "Blocos da Pesquisa de Satisfação removidos do dialplan"
    fi
fi

# Atalho 8996 e from-internal-custom da pesquisa
if [ -f "$CUSTOM_EXT" ] && grep -q "8996" "$CUSTOM_EXT" 2>/dev/null; then
    if ! $DRY_RUN; then
        sed -i '/ATALHO DIRETO DE TESTE E TRANSFERENCIA DE PESQUISA/d' "$CUSTOM_EXT"
        sed -i '/CHAMADA DIRETA PARA PESQUISA DE SATISFACAO 8996/d' "$CUSTOM_EXT"
        sed -i '/exten => 8996/d' "$CUSTOM_EXT"
        sed -i '/Goto(pesquisa,s,1)/d' "$CUSTOM_EXT"
        log_success "Atalho 8996 removido do dialplan"
    fi
fi

# ==============================================================================
# 5. REVERTER FEATURES DO ASTERISK (transferência e BIP)
# ==============================================================================
log_step "5/12 - Revertendo configurações de Features do Asterisk"

FEATURES_CUSTOM="/etc/asterisk/features_general_custom.conf"
remove_config_block "$FEATURES_CUSTOM" "; --- CONFIGURAÇÕES DE TRANSFERÊNCIA DE CHAMADAS (PRISMA TELECOM) ---"

# Se o bloco padrão sem marcador foi adicionado, remove as linhas específicas
if [ -f "$FEATURES_CUSTOM" ] && grep -q "transferdigittimeout" "$FEATURES_CUSTOM" 2>/dev/null; then
    if ! $DRY_RUN; then
        cp -f "$FEATURES_CUSTOM" "${FEATURES_CUSTOM}.pre_rollback"
        sed -i '/transferdigittimeout/d' "$FEATURES_CUSTOM"
        sed -i '/atxfernoanswertimeout/d' "$FEATURES_CUSTOM"
        sed -i '/atxferdropcall/d' "$FEATURES_CUSTOM"
        sed -i '/atxferloopdelay/d' "$FEATURES_CUSTOM"
        sed -i '/atxfercallbackretries/d' "$FEATURES_CUSTOM"
        sed -i '/courtesytone/d' "$FEATURES_CUSTOM"
        sed -i '/xfersound/d' "$FEATURES_CUSTOM"
        log_success "Linhas de features_general_custom.conf removidas"
    fi
fi

# ==============================================================================
# 6. REVERTER USER-AGENT PJSIP
# ==============================================================================
log_step "6/12 - Revertendo User-Agent PJSIP"

PJSIP_CONF="/etc/asterisk/pjsip.conf"
PJSIP_CUSTOM="/etc/asterisk/pjsip_custom.conf"

if [ -f "$PJSIP_CONF" ] && grep -q "IPbx-Prisma" "$PJSIP_CONF" 2>/dev/null; then
    if ! $DRY_RUN; then
        sed -i 's/^user_agent=IPbx-Prisma/user_agent=Asterisk PBX/' "$PJSIP_CONF"
        log_success "User-Agent restaurado em pjsip.conf"
    else
        log_dry "Restauraria user_agent em pjsip.conf"
    fi
fi

if [ -f "$PJSIP_CUSTOM" ] && grep -q "IPbx-Prisma" "$PJSIP_CUSTOM" 2>/dev/null; then
    if ! $DRY_RUN; then
        cp -f "$PJSIP_CUSTOM" "${PJSIP_CUSTOM}.pre_rollback"
        # Remove o bloco [global] com user_agent=IPbx-Prisma adicionado
        python3 -c "
import re
with open('$PJSIP_CUSTOM', 'r') as f:
    content = f.read()
content = re.sub(r'\n*\[global\]\s*\ntype=global\s*\nuser_agent=IPbx-Prisma\s*\n?', '', content)
with open('$PJSIP_CUSTOM', 'w') as f:
    f.write(content)
" 2>/dev/null || {
            sed -i '/user_agent=IPbx-Prisma/d' "$PJSIP_CUSTOM"
        }
        log_success "User-Agent removido de pjsip_custom.conf"
    else
        log_dry "Removeria user_agent de pjsip_custom.conf"
    fi
fi

# ==============================================================================
# 7. REVERTER ENTRADAS DE MENU E ACL NO ISSABEL (SQLite)
# ==============================================================================
log_step "7/12 - Removendo entradas de menu e ACL dos bancos do Issabel"

if command -v sqlite3 &>/dev/null; then
    MENU_DB="/var/www/db/menu.db"
    ACL_DB="/var/www/db/acl.db"

    # IDs de menu inseridos pelo install.sh
    MENU_IDS=(
        "relatorio_geral"
        "relatorio_cdr"
        "pesquisa"
        "pesquisa_ajuda"
        "pesquisa_como_funciona"
        "nome_ramais"
        "control_panel"
        "relatorio_de_filas"
    )

    # Nomes de recursos ACL inseridos pelo install.sh
    ACL_NAMES=(
        "relatorio_geral"
        "relatorio_cdr"
        "pesquisa"
        "pesquisa_ajuda"
        "pesquisa_como_funciona"
        "nome_ramais"
        "control_panel"
        "relatorio_de_filas"
    )

    if [ -f "$MENU_DB" ]; then
        if ! $DRY_RUN; then
            # Backup dos bancos SQLite antes de alterar
            cp -f "$MENU_DB" "${MENU_DB}.pre_rollback"
        fi
        for mid in "${MENU_IDS[@]}"; do
            if $DRY_RUN; then
                log_dry "Removeria menu id='$mid' de menu.db"
            else
                sqlite3 "$MENU_DB" "DELETE FROM menu WHERE id = '$mid';" 2>/dev/null || true
            fi
        done
        $DRY_RUN || log_success "Entradas de menu removidas de menu.db"
    fi

    if [ -f "$ACL_DB" ]; then
        if ! $DRY_RUN; then
            cp -f "$ACL_DB" "${ACL_DB}.pre_rollback"
        fi
        for aname in "${ACL_NAMES[@]}"; do
            if $DRY_RUN; then
                log_dry "Removeria ACL resource='$aname' de acl.db"
            else
                sqlite3 "$ACL_DB" "DELETE FROM acl_group_permission WHERE id_resource IN (SELECT id FROM acl_resource WHERE name = '$aname');" 2>/dev/null || true
                sqlite3 "$ACL_DB" "DELETE FROM acl_resource WHERE name = '$aname';" 2>/dev/null || true
            fi
        done
        $DRY_RUN || log_success "Entradas ACL removidas de acl.db"
    fi

    # Remove banco da pesquisa de satisfação (SQLite)
    if [ -f "/var/www/db/pesquisa.db" ]; then
        run_cmd "rm -f /var/www/db/pesquisa.db"
        $DRY_RUN || log_success "Banco pesquisa.db removido"
    fi
else
    log_warn "sqlite3 não encontrado — entradas de menu/ACL não puderam ser removidas"
fi

# ==============================================================================
# 8. REMOVER TABELA DE PESQUISA DO MYSQL (se existir)
# ==============================================================================
log_step "8/12 - Removendo tabela de pesquisa do MySQL"

MYSQL_PWD=""
if [ -f /etc/issabel.conf ]; then
    MYSQL_PWD=$(grep -i mysqlrootpwd /etc/issabel.conf 2>/dev/null | cut -d'=' -f2 | tr -d ' ')
fi

if command -v mysql &>/dev/null; then
    if $DRY_RUN; then
        log_dry "Removeria tabela 'pesquisa' do banco asteriskcdrdb"
        log_dry "Removeria custom_destination 'pesquisa-satisfação' do banco asterisk"
    else
        mysql -u root ${MYSQL_PWD:+-p"$MYSQL_PWD"} -e "DROP TABLE IF EXISTS asteriskcdrdb.pesquisa;" 2>/dev/null || \
        mysql -u root -e "DROP TABLE IF EXISTS asteriskcdrdb.pesquisa;" 2>/dev/null || true
        log_success "Tabela pesquisa removida do MySQL"

        mysql -u root ${MYSQL_PWD:+-p"$MYSQL_PWD"} asterisk -e "DELETE FROM custom_destinations WHERE custom_dest LIKE '%pesquisa-satisf%';" 2>/dev/null || \
        mysql -u root asterisk -e "DELETE FROM custom_destinations WHERE custom_dest LIKE '%pesquisa-satisf%';" 2>/dev/null || true
        log_success "Custom Destination da pesquisa removido"
    fi
fi

# ==============================================================================
# 9. REMOVER CRONTAB ENTRIES
# ==============================================================================
log_step "9/12 - Removendo agendamentos do crontab"

if crontab -l &>/dev/null; then
    CRON_BACKUP=$(crontab -l 2>/dev/null)
    CRON_ENTRIES_TO_REMOVE=(
        "monitor_issabel_users.sh"
        "parselog.php"
    )

    HAS_CHANGES=false
    NEW_CRON="$CRON_BACKUP"
    for entry in "${CRON_ENTRIES_TO_REMOVE[@]}"; do
        if echo "$NEW_CRON" | grep -q "$entry"; then
            if $DRY_RUN; then
                log_dry "Removeria crontab entry: $entry"
            else
                NEW_CRON=$(echo "$NEW_CRON" | grep -v "$entry")
                HAS_CHANGES=true
            fi
        fi
    done

    if $HAS_CHANGES && ! $DRY_RUN; then
        echo "$NEW_CRON" | crontab -
        log_success "Entradas do crontab removidas (monitor_issabel, parselog)"
    fi
fi

# Remove o cron semanal de autoupdate
if [ -f "/etc/cron.weekly/ipbx-autoupdate" ]; then
    run_cmd "rm -f /etc/cron.weekly/ipbx-autoupdate"
    $DRY_RUN || log_success "Cron semanal /etc/cron.weekly/ipbx-autoupdate removido"
fi

# Remove o binário do autoupdate
if [ -f "/usr/local/bin/ipbx-autoupdate" ]; then
    run_cmd "rm -f /usr/local/bin/ipbx-autoupdate"
    $DRY_RUN || log_success "Binário /usr/local/bin/ipbx-autoupdate removido"
fi

# Remove o monitor telegram
if [ -f "/usr/local/bin/monitor_issabel_users.sh" ]; then
    run_cmd "rm -f /usr/local/bin/monitor_issabel_users.sh"
    $DRY_RUN || log_success "Script monitor Telegram removido"
fi

# ==============================================================================
# 10. REMOVER PARSELOG DO ASTERNIC STATS LITE
# ==============================================================================
log_step "10/12 - Removendo Asternic Stats Lite (parselog)"

if [ -d "/usr/local/parselog" ]; then
    run_cmd "rm -rf /usr/local/parselog"
    $DRY_RUN || log_success "Diretório /usr/local/parselog removido"
fi

# ==============================================================================
# 11. REMOVER SUDOERS DO ASTERISK (se criado pelo install.sh)
# ==============================================================================
log_step "11/12 - Verificando sudoers do asterisk"

if [ -f "/etc/sudoers.d/asterisk" ]; then
    if grep -q "asterisk ALL=(ALL) NOPASSWD: ALL" /etc/sudoers.d/asterisk 2>/dev/null; then
        run_cmd "rm -f /etc/sudoers.d/asterisk"
        $DRY_RUN || log_success "Sudoers do asterisk removido (era NOPASSWD:ALL)"
    else
        log_info "Sudoers do asterisk tem configuração customizada, mantido."
    fi
fi

# ==============================================================================
# 12. RECARREGAR SERVIÇOS
# ==============================================================================
log_step "12/12 - Recarregando serviços do sistema"

if ! $DRY_RUN; then
    # Limpa cache do Smarty
    rm -rf /var/www/html/var/templates_c/* 2>/dev/null || true
    rm -rf /tmp/smarty* 2>/dev/null || true

    # Recarrega Asterisk
    asterisk -rx "module reload" 2>/dev/null || asterisk -rx "core reload" 2>/dev/null || true
    log_success "Asterisk recarregado"

    # Recarrega FreePBX/IssabelPBX
    if command -v fwconsole &>/dev/null; then
        fwconsole reload 2>/dev/null || true
        log_success "fwconsole reload executado"
    elif command -v amportal &>/dev/null; then
        amportal a r 2>/dev/null || true
        log_success "amportal reload executado"
    fi

    # Reinicia Apache
    if systemctl is-active httpd &>/dev/null; then
        systemctl restart httpd
        log_success "Apache (httpd) reiniciado"
    elif systemctl is-active apache2 &>/dev/null; then
        systemctl restart apache2
        log_success "Apache (apache2) reiniciado"
    fi
else
    log_dry "Recarregaria: Asterisk, FreePBX/IssabelPBX, Apache"
fi

# ==============================================================================
# RESULTADO FINAL
# ==============================================================================
echo ""
if $DRY_RUN; then
    echo -e "${MAGENTA}╔══════════════════════════════════════════════════════════════════════╗${NC}"
    echo -e "${MAGENTA}║              SIMULAÇÃO DE ROLLBACK CONCLUÍDA (DRY-RUN)             ║${NC}"
    echo -e "${MAGENTA}║                                                                    ║${NC}"
    echo -e "${MAGENTA}║  Nenhuma alteração foi feita. Para executar de verdade:             ║${NC}"
    echo -e "${MAGENTA}║  bash rollback.sh --force                                          ║${NC}"
    echo -e "${MAGENTA}╚══════════════════════════════════════════════════════════════════════╝${NC}"
else
    echo -e "${GREEN}╔══════════════════════════════════════════════════════════════════════╗${NC}"
    echo -e "${GREEN}║              ROLLBACK CONCLUÍDO COM SUCESSO!                        ║${NC}"
    echo -e "${GREEN}║                                                                    ║${NC}"
    echo -e "${GREEN}║  O servidor foi restaurado ao estado anterior à instalação IPBX.   ║${NC}"
    echo -e "${GREEN}║                                                                    ║${NC}"
    echo -e "${GREEN}║  Backups .pre_rollback foram criados para os arquivos de config.   ║${NC}"
    echo -e "${GREEN}║  Log completo: /var/log/ipbx-rollback.log                          ║${NC}"
    echo -e "${GREEN}╚══════════════════════════════════════════════════════════════════════╝${NC}"

    echo -e "\n${YELLOW}RECOMENDAÇÕES PÓS-ROLLBACK:${NC}"
    echo -e "  1. Verifique se o Issabel está acessível: https://<IP_DO_SERVIDOR>"
    echo -e "  2. Teste uma ligação interna entre ramais"
    echo -e "  3. Verifique o Asterisk: ${CYAN}asterisk -rvvv${NC}"
    echo -e "  4. Se precisar reinstalar: ${CYAN}bash install.sh${NC}"
    echo ""

    log_to_file "ROLLBACK CONCLUÍDO COM SUCESSO"
fi

exit 0
