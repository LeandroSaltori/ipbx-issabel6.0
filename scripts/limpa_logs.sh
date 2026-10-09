#!/bin/bash
# ==============================================================================
# SCRIPT DE LIMPEZA DE LOGS E OTIMIZAÇÃO DE DISCO - IPBX ISSABEL
# ==============================================================================
# Prisma Telecom - IPBX Issabel
# Esvazia arquivos de log gigantescos (truncate seguro sem travar processos ativos),
# remove logs rotacionados antigos (*.gz, *.1, *-YYYYMMDD) e limpa cache do Smarty.
# PRESERVA: Histórico de chamadas CDRs (Master.csv), gravações e bancos de dados.
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
NC='\033[0m'

log_info()    { echo -e "${CYAN}[INFO]${NC} $1"; }
log_success() { echo -e "${GREEN}[LIMPO]${NC} $1"; }
log_warn()    { echo -e "${YELLOW}[AVISO]${NC} $1"; }
log_error()   { echo -e "${RED}[ERRO]${NC} $1"; }

if [ "$EUID" -ne 0 ]; then
    log_error "Este script precisa ser executado como root!"
    exit 1
fi

echo -e "\n${BLUE}══════════════════════════════════════════════════════════════════════${NC}"
echo -e "${WHITE}   LIMPEZA DE LOGS E OTIMIZAÇÃO DE ESPAÇO EM DISCO                    ${NC}"
echo -e "${BLUE}══════════════════════════════════════════════════════════════════════${NC}\n"

# Mostra espaço em disco antes
echo -e "${CYAN}Espaço em disco ANTES da limpeza:${NC}"
df -h / | awk 'NR==1 || NR==2'
echo ""

# 1. Lista de arquivos de log do sistema e serviços
LOG_PATHS=(
    "/opt/issabel/dialer/dialerd.log"
    "/var/log/asterisk/full"
    "/var/log/asterisk/messages"
    "/var/log/asterisk/issabelpbx.log"
    "/var/log/asterisk/freepbx.log"
    "/var/log/asterisk/fail2ban"
    "/var/log/httpd/error_log"
    "/var/log/httpd/access_log"
    "/var/log/httpd/ssl_error_log"
    "/var/log/httpd/ssl_access_log"
    "/var/log/httpd/ssl_request_log"
    "/var/log/messages"
    "/var/log/cron"
    "/var/log/secure"
    "/var/log/maillog"
    "/var/log/spooler"
    "/var/log/fail2ban.log"
    "/var/log/ipbx-autoupdate.log"
)

# Tamanho máximo antes de truncar (50MB = 51200KB)
MAX_SIZE_KB=51200

log_info "1. Analisando e truncando arquivos de log com mais de 50MB..."
for LOG_PATH in "${LOG_PATHS[@]}"; do
    if [ -f "$LOG_PATH" ]; then
        FILE_SIZE=$(du -k "$LOG_PATH" 2>/dev/null | cut -f1 || echo 0)
        if [ "$FILE_SIZE" -gt "$MAX_SIZE_KB" ]; then
            HUMAN_SIZE=$(du -h "$LOG_PATH" | cut -f1)
            truncate -s 0 "$LOG_PATH"
            log_success "$LOG_PATH ($HUMAN_SIZE esvaziado)"
        fi
    fi
done

# 2. Remoção segura de arquivos de log rotacionados antigos (*.gz, *.1, *-YYYYMMDD)
log_info "2. Removendo arquivos compactados e logs rotacionados antigos..."

# Logs rotacionados do sistema em /var/log/
find /var/log/ -maxdepth 2 -type f \( -name "*.gz" -o -name "*.1" -o -name "*.2" -o -name "*.3" -o -name "*.old" -o -name "*-[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]" -o -name "*-[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9].gz" \) ! -path "/var/log/asterisk/cdr-csv/*" -delete 2>/dev/null || true

# Logs de mensagens do Asterisk
find /var/log/asterisk/ -type f \( -name "messages.*" -o -name "full.*" -o -name "*.gz" -o -name "*.old" \) -delete 2>/dev/null || true

# 3. Limpeza de cache de templates do Smarty e sessões temporárias
log_info "3. Limpando cache de templates do Smarty e temporários..."
rm -rf /var/www/html/var/templates_c/* 2>/dev/null || true
rm -rf /tmp/smarty* 2>/dev/null || true
rm -rf /tmp/ipbx-* 2>/dev/null || true

# 4. Garante permissões do Master.csv do Asterisk
mkdir -p /var/log/asterisk/cdr-csv /var/log/asterisk/cdr-custom 2>/dev/null || true
touch /var/log/asterisk/cdr-csv/Master.csv 2>/dev/null || true
chown -R asterisk:asterisk /var/log/asterisk/cdr-csv /var/log/asterisk/cdr-custom 2>/dev/null || true
chmod 755 /var/log/asterisk/cdr-csv /var/log/asterisk/cdr-custom 2>/dev/null || true
chmod 664 /var/log/asterisk/cdr-csv/Master.csv 2>/dev/null || true

echo ""
echo -e "${GREEN}Espaço em disco DEPOIS da limpeza:${NC}"
df -h / | awk 'NR==1 || NR==2'
echo ""

echo -e "${GREEN}══════════════════════════════════════════════════════════════════════${NC}"
echo -e "${GREEN}  LIMPEZA CONCLUÍDA COM SUCESSO!                                      ${NC}"
echo -e "${GREEN}══════════════════════════════════════════════════════════════════════${NC}\n"
