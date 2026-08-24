#!/bin/bash
# ==============================================================================
# SCRIPT DE ROLLBACK VERSIONADO POR DATA - IPBX ISSABEL (PRISMA TELECOM)
# ==============================================================================
# Restaura snapshots salvos em /var/backup/ipbx/
# Permite restaurar o último backup ou selecionar uma data/hora específica.
#
# Uso:
#   ipbx-rollback               → Menu de seleção de data / restauração do último
#   ipbx-rollback --latest      → Restaura imediatamente o snapshot mais recente
#   ipbx-rollback --list        → Lista todos os backups disponíveis com data/hora
#   ipbx-rollback --dry-run     → Simula o que seria restaurado sem alterar nada
# ==============================================================================

# Desativa aliases do root
unalias cp 2>/dev/null || true
unalias mv 2>/dev/null || true
unalias rm 2>/dev/null || true
shopt -s expand_aliases 2>/dev/null || true

# --- CORES ---
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
WHITE='\033[1;37m'
MAGENTA='\033[0;35m'
NC='\033[0m'

BACKUP_ROOT="/var/backup/ipbx"
ROLLBACK_LOG="/var/log/ipbx-rollback.log"

log_info()    { echo -e "${CYAN}[INFO]${NC} $1"; }
log_success() { echo -e "${GREEN}[RESTAURADO]${NC} $1"; }
log_warn()    { echo -e "${YELLOW}[AVISO]${NC} $1"; }
log_error()   { echo -e "${RED}[ERRO]${NC} $1"; }
log_dry()     { echo -e "${MAGENTA}[DRY-RUN]${NC} $1"; }

log_to_file() {
    local timestamp
    timestamp=$(date '+%Y-%m-%d %H:%M:%S')
    echo "[$timestamp] $1" >> "$ROLLBACK_LOG" 2>/dev/null || true
}

if [ "$EUID" -ne 0 ]; then
    log_error "Este script precisa ser executado como root!"
    exit 1
fi

# Detecta se stdin é terminal interativo
menu_read() {
    local var_name="${!#}"
    if [ -t 0 ]; then
        read "$@"
    elif [ -e /dev/tty ] && [ -r /dev/tty ]; then
        read "$@" < /dev/tty
    else
        eval "$var_name=\"\""
    fi
}

DRY_RUN=false
RESTORE_LATEST=false
LIST_ONLY=false

for arg in "$@"; do
    case "$arg" in
        --dry-run) DRY_RUN=true ;;
        --latest|-l) RESTORE_LATEST=true ;;
        --list) LIST_ONLY=true ;;
        --help|-h)
            echo -e "${CYAN}Uso:${NC}"
            echo "  ipbx-rollback            → Escolhe data interativamente ou restaura último"
            echo "  ipbx-rollback --latest   → Restaura o último backup diretamente"
            echo "  ipbx-rollback --list     → Lista todos os backups por data"
            echo "  ipbx-rollback --dry-run  → Simula a restauração"
            exit 0
            ;;
    esac
done

# Lista todos os backups disponíveis
list_backups() {
    if [ ! -d "$BACKUP_ROOT" ]; then
        echo -e "${YELLOW}Nenhum diretório de backup encontrado em $BACKUP_ROOT${NC}"
        return 1
    fi

    local dirs
    dirs=($(ls -dt "$BACKUP_ROOT"/backup_* 2>/dev/null || true))

    if [ ${#dirs[@]} -eq 0 ]; then
        echo -e "${YELLOW}Nenhum ponto de restauração gravado.${NC}"
        return 1
    fi

    echo -e "\n${BLUE}══════════════════════════════════════════════════════════════════════${NC}"
    echo -e "${WHITE}  PONTOS DE RESTAURAÇÃO DISPONÍVEIS (POR DATA/HORA)${NC}"
    echo -e "${BLUE}══════════════════════════════════════════════════════════════════════${NC}"

    local idx=1
    for d in "${dirs[@]}"; do
        local bname=$(basename "$d")
        local desc="Backup"
        if [ -f "$d/manifesto.txt" ]; then
            desc=$(head -n 1 "$d/manifesto.txt")
        fi
        local is_latest=""
        if [ "$idx" -eq 1 ]; then
            is_latest=" ${GREEN}(MAIS RECENTE)${NC}"
        fi
        echo -e "  ${WHITE}[$idx]${NC} ${CYAN}$bname${NC} - $desc$is_latest"
        ((idx++))
    done
    echo -e "${BLUE}══════════════════════════════════════════════════════════════════════${NC}\n"
    return 0
}

if $LIST_ONLY; then
    list_backups
    exit 0
fi

# ==============================================================================
# SELEÇÃO DO BACKUP
# ==============================================================================
SELECTED_BACKUP=""

if [ -d "$BACKUP_ROOT" ]; then
    DIRS=($(ls -dt "$BACKUP_ROOT"/backup_* 2>/dev/null || true))
else
    DIRS=()
fi

if [ ${#DIRS[@]} -eq 0 ]; then
    log_warn "Nenhum snapshot com data encontrado em $BACKUP_ROOT."
    log_info "Verificando se existem backups legados com sufixo _old..."
    
    # Fallback para backups _old legados
    HAS_OLD=false
    for legacy in /var/www/html/admin_old /var/www/html/lang_old /var/www/html/modules_old; do
        if [ -e "$legacy" ]; then HAS_OLD=true; break; fi
    done

    if $HAS_OLD; then
        log_info "Backups _old legados encontrados. Restaurando backups _old..."
        if [ -e /var/www/html/admin_old ]; then
            rm -rf /var/www/html/admin
            mv /var/www/html/admin_old /var/www/html/admin
            log_success "Admin restaurado de admin_old"
        fi
        if [ -e /var/www/html/lang_old ]; then
            rm -rf /var/www/html/lang
            mv /var/www/html/lang_old /var/www/html/lang
            log_success "Lang restaurado de lang_old"
        fi
        if [ -e /var/www/html/modules_old ]; then
            rm -rf /var/www/html/modules
            mv /var/www/html/modules_old /var/www/html/modules
            log_success "Modules restaurado de modules_old"
        fi
        log_success "Restauração de backups legados concluída."
        exit 0
    else
        log_error "Nenhum backup encontrado no sistema para restaurar."
        exit 1
    fi
fi

if $RESTORE_LATEST; then
    SELECTED_BACKUP="${DIRS[0]}"
else
    list_backups
    echo -e "${YELLOW}Digite o número do backup que deseja restaurar (ou ENTER para o [1] Mais Recente, 0 para Cancelar):${NC}"
    echo -ne "${CYAN}Opção [1]: ${NC}"
    menu_read -r ESCOLHA

    if [ "$ESCOLHA" = "0" ]; then
        echo -e "${GREEN}Rollback cancelado.${NC}"
        exit 0
    elif [ -z "$ESCOLHA" ] || [ "$ESCOLHA" = "1" ]; then
        SELECTED_BACKUP="${DIRS[0]}"
    elif [[ "$ESCOLHA" =~ ^[0-9]+$ ]] && [ "$ESCOLHA" -le "${#DIRS[@]}" ] && [ "$ESCOLHA" -gt 0 ]; then
        SELECTED_BACKUP="${DIRS[$((ESCOLHA-1))]}"
    else
        log_error "Opção inválida."
        exit 1
    fi
fi

log_info "Ponto de restauração selecionado: $(basename "$SELECTED_BACKUP")"
if [ -f "$SELECTED_BACKUP/manifesto.txt" ]; then
    echo -e "${CYAN}Detalhes:${NC} $(cat "$SELECTED_BACKUP/manifesto.txt")"
fi

echo ""
echo -ne "${RED}Confirma a restauração deste ponto? (sim/nao) [sim]: ${NC}"
menu_read -r CONFIRMA
CONFIRMA=${CONFIRMA:-sim}

if [[ "$CONFIRMA" != "sim" && "$CONFIRMA" != "s" && "$CONFIRMA" != "SIM" && "$CONFIRMA" != "S" ]]; then
    echo -e "${GREEN}Rollback cancelado pelo usuário.${NC}"
    exit 0
fi

# ==============================================================================
# EXECUÇÃO DA RESTAURAÇÃO
# ==============================================================================
log_info "Iniciando restauração do snapshot..."
log_to_file "Iniciando restauração de $SELECTED_BACKUP"

# 1. Restauração segura de arquivos e pastas WEB
if [ -d "$SELECTED_BACKUP/html" ]; then
    if $DRY_RUN; then
        log_dry "Restauraria arquivos de $SELECTED_BACKUP/html/ para /var/www/html/"
    else
        cp -rpf "$SELECTED_BACKUP/html/." "/var/www/html/"
        chown -R asterisk:asterisk /var/www/html/
        log_success "Arquivos e módulos WEB restaurados com sucesso a partir de $SELECTED_BACKUP/html"
    fi
fi

# 3. Restauração dos bancos SQLite (menu.db, acl.db)
if [ -d "$SELECTED_BACKUP/db" ]; then
    for db in menu.db acl.db; do
        if [ -f "$SELECTED_BACKUP/db/$db" ]; then
            if $DRY_RUN; then
                log_dry "Restauraria /var/www/db/$db"
            else
                cp -pf "$SELECTED_BACKUP/db/$db" "/var/www/db/$db"
                chown asterisk:asterisk "/var/www/db/$db"
                chmod 664 "/var/www/db/$db"
                log_success "/var/www/db/$db restaurado"
            fi
        fi
    done
fi

# 4. Restauração de arquivos Asterisk .conf
if [ -d "$SELECTED_BACKUP/asterisk" ]; then
    for conf in $(ls "$SELECTED_BACKUP/asterisk/" 2>/dev/null || true); do
        if $DRY_RUN; then
            log_dry "Restauraria /etc/asterisk/$conf"
        else
            cp -pf "$SELECTED_BACKUP/asterisk/$conf" "/etc/asterisk/$conf"
            chown asterisk:asterisk "/etc/asterisk/$conf"
            chmod 644 "/etc/asterisk/$conf"
            log_success "/etc/asterisk/$conf restaurado"
        fi
    done
fi

# 5. Restauração de Crontab
if [ -f "$SELECTED_BACKUP/crontab.txt" ]; then
    if $DRY_RUN; then
        log_dry "Restauraria crontab"
    else
        crontab "$SELECTED_BACKUP/crontab.txt" 2>/dev/null || true
        log_success "Crontab restaurado"
    fi
fi

# 6. Restauração de MOTD
if [ -f "$SELECTED_BACKUP/motd.sh" ]; then
    if ! $DRY_RUN; then
        cp -pf "$SELECTED_BACKUP/motd.sh" /usr/local/sbin/motd.sh
        chmod +x /usr/local/sbin/motd.sh
        log_success "MOTD restaurado"
    fi
fi

# 7. Recarrega serviços e garante integridade
if ! $DRY_RUN; then
    mkdir -p /var/log/asterisk/cdr-csv /var/log/asterisk/cdr-custom 2>/dev/null || true
    touch /var/log/asterisk/cdr-csv/Master.csv 2>/dev/null || true
    chown -R asterisk:asterisk /var/log/asterisk/cdr-csv /var/log/asterisk/cdr-custom 2>/dev/null || true
    chmod 755 /var/log/asterisk/cdr-csv /var/log/asterisk/cdr-custom 2>/dev/null || true
    chmod 664 /var/log/asterisk/cdr-csv/Master.csv 2>/dev/null || true

    rm -rf /var/www/html/var/templates_c/* 2>/dev/null || true
    rm -rf /tmp/smarty* 2>/dev/null || true

    asterisk -rx "module reload" 2>/dev/null || asterisk -rx "core reload" 2>/dev/null || true
    if command -v fwconsole &>/dev/null; then
        fwconsole reload 2>/dev/null || true
    elif command -v amportal &>/dev/null; then
        amportal a r 2>/dev/null || true
    fi

    if systemctl is-active httpd &>/dev/null; then
        systemctl restart httpd 2>/dev/null || true
    elif systemctl is-active apache2 &>/dev/null; then
        systemctl restart apache2 2>/dev/null || true
    fi
fi

echo ""
echo -e "${GREEN}╔══════════════════════════════════════════════════════════════════════╗${NC}"
echo -e "${GREEN}║          ROLLBACK REALIZADO COM SUCESSO!                             ║${NC}"
echo -e "${GREEN}║  Restaurado para o estado de: $(basename "$SELECTED_BACKUP")                   ║${NC}"
echo -e "${GREEN}╚══════════════════════════════════════════════════════════════════════╝${NC}\n"

log_to_file "Rollback concluído com sucesso para $(basename "$SELECTED_BACKUP")"
exit 0
