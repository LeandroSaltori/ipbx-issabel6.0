#!/bin/bash
# ==============================================================================
# CONFIGURAÇÃO DE LOGROTATE & OTIMIZAÇÃO DE DISCO - IPBX ISSABEL
# ==============================================================================
# Prisma Telecom - IPBX Issabel 4 (CentOS 7) e Issabel 5 (Rocky Linux 8)
# Configura rotação automática com compressão para Asterisk, Apache, Fail2ban e IPbx.
# Executa logger reload sem interromper chamadas e sem travar processos.
# ==============================================================================
set -e

unalias cp 2>/dev/null || true
unalias mv 2>/dev/null || true
unalias rm 2>/dev/null || true
shopt -s expand_aliases 2>/dev/null || true

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
NC='\033[0m'

log_info()    { echo -e "${CYAN}[INFO]${NC} $1"; }
log_success() { echo -e "${GREEN}[SUCESSO]${NC} $1"; }
log_warn()    { echo -e "${YELLOW}[AVISO]${NC} $1"; }
log_error()   { echo -e "${RED}[ERRO]${NC} $1"; }

if [ "$EUID" -ne 0 ]; then
    log_error "Este script precisa ser executado como root!"
    exit 1
fi

log_info "Configurando Logrotate otimizado para Asterisk, Apache e IPbx..."

# 1. Cria a configuração unificada do Logrotate
cat <<'EOF' > /etc/logrotate.d/ipbx-issabel
# ==============================================================================
# ROTAÇÃO AUTOMÁTICA DE LOGS - IPBX PRISMA TELECOM
# ==============================================================================

# Logs do Asterisk PBX
/var/log/asterisk/full
/var/log/asterisk/messages
/var/log/asterisk/issabelpbx.log
/var/log/asterisk/freepbx.log
/var/log/asterisk/fail2ban
{
    weekly
    maxsize 50M
    missingok
    rotate 8
    compress
    delaycompress
    notifempty
    create 0640 asterisk asterisk
    sharedscripts
    postrotate
        /usr/sbin/asterisk -rx "logger reload" >/dev/null 2>&1 || true
    endscript
}

# Logs do Apache Web Server
/var/log/httpd/*log
{
    weekly
    maxsize 50M
    missingok
    rotate 8
    compress
    delaycompress
    notifempty
    sharedscripts
    postrotate
        /bin/systemctl reload httpd.service >/dev/null 2>&1 || /bin/systemctl restart httpd.service >/dev/null 2>&1 || true
    endscript
}

# Logs do Sistema e Segurança IPbx
/var/log/fail2ban.log
/var/log/ipbx-autoupdate.log
/var/log/ipbx-rollback.log
{
    weekly
    maxsize 30M
    missingok
    rotate 6
    compress
    delaycompress
    notifempty
    create 0640 root root
}
EOF

chmod 644 /etc/logrotate.d/ipbx-issabel
log_success "Arquivo /etc/logrotate.d/ipbx-issabel configurado."

# 2. Testa a sintaxe do Logrotate
if command -v logrotate &>/dev/null; then
    logrotate -d /etc/logrotate.d/ipbx-issabel >/dev/null 2>&1 && log_success "Sintaxe do Logrotate validada com sucesso." || log_warn "Aviso ao testar logrotate."
fi

# 3. Cria atalho 'ipbx-limpalogs' no sistema
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" 2>/dev/null && pwd || echo "")"
if [ -f "$SCRIPT_DIR/limpa_logs.sh" ]; then
    cp -f "$SCRIPT_DIR/limpa_logs.sh" /usr/local/bin/ipbx-limpalogs
    chmod +x /usr/local/bin/ipbx-limpalogs
    log_success "Atalho 'ipbx-limpalogs' instalado em /usr/local/bin/ipbx-limpalogs."
fi

# 4. Executa limpeza imediata de arquivos gigantescos se solicitado
if [ "$1" == "--now" ] || [ "$1" == "-f" ]; then
    if [ -f "$SCRIPT_DIR/limpa_logs.sh" ]; then
        bash "$SCRIPT_DIR/limpa_logs.sh"
    fi
fi

log_success "Configuração de rotação e limpeza de logs finalizada."
