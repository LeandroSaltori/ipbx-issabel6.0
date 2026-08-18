#!/bin/bash
# ==============================================================================
# SCRIPT DE ATUALIZAÇÃO AUTOMÁTICA SEMANAL - IPBX PRISMA TELECOM
# ==============================================================================
# Este script é executado semanalmente via cron (/etc/cron.weekly/ipbx-autoupdate).
# Ele faz git pull do repositório e executa o install.sh.
# Mantém logs detalhados tanto na pasta local do cliente quanto em /var/log/.
# ==============================================================================

# Busca diretório do repositório
REPO_DIR=""
POSSIBLE_PATHS=(
    "$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
    "/usr/src/ipbx-issabel6.0"
    "/root/ipbx-issabel6.0"
    "/var/www/html/ipbx-issabel6.0"
    "/opt/ipbx-issabel6.0"
)

for p in "${POSSIBLE_PATHS[@]}"; do
    if [ -f "$p/install.sh" ] && [ -d "$p/.git" ]; then
        REPO_DIR="$p"
        break
    fi
done

# Se não encontrou repositório clonado localmente, verifica caminho padrão de clonagem
if [ -z "$REPO_DIR" ]; then
    if [ -d "/tmp/ipbx-issabel-repo/.git" ]; then
        REPO_DIR="/tmp/ipbx-issabel-repo"
    fi
fi

SYSTEM_LOG="/var/log/ipbx-autoupdate.log"

if [ -n "$REPO_DIR" ]; then
    CLIENT_LOG="$REPO_DIR/autoupdate.log"
    STATUS_FILE="$REPO_DIR/autoupdate_last_status.txt"
else
    CLIENT_LOG="/var/log/ipbx-autoupdate.log"
    STATUS_FILE="/var/log/ipbx-autoupdate_last_status.txt"
fi

log_msg() {
    local level="$1"
    local msg="$2"
    local timestamp=$(date '+%Y-%m-%d %H:%M:%S')
    local log_entry="[$timestamp] [$level] $msg"

    echo "$log_entry" >> "$SYSTEM_LOG" 2>/dev/null || true
    if [ -n "$CLIENT_LOG" ]; then
        echo "$log_entry" >> "$CLIENT_LOG" 2>/dev/null || true
    fi
}

log_msg "INFO" "======================================================================"
log_msg "INFO" "Iniciando verificação de atualização semanal do IPbx Prisma Telecom..."

if [ -z "$REPO_DIR" ] || [ ! -d "$REPO_DIR/.git" ]; then
    log_msg "ERRO" "Repositório Git não localizado nas pastas padrão. Atualização abortada."
    echo "ERRO: Repositório Git não encontrado em $(date '+%Y-%m-%d %H:%M:%S')" > "$STATUS_FILE" 2>/dev/null || true
    exit 1
fi

cd "$REPO_DIR" || {
    log_msg "ERRO" "Falha ao acessar diretório $REPO_DIR."
    echo "ERRO: Falha ao acessar diretório $REPO_DIR em $(date '+%Y-%m-%d %H:%M:%S')" > "$STATUS_FILE"
    exit 1
}

log_msg "INFO" "Pasta do cliente localizada em: $REPO_DIR"
log_msg "INFO" "Executando git fetch para verificar novas atualizações..."

FETCH_OUTPUT=$(git fetch origin 2>&1)
FETCH_STATUS=$?

if [ $FETCH_STATUS -ne 0 ]; then
    log_msg "ERRO" "Falha de conexão ou erro no git fetch: $FETCH_OUTPUT"
    echo "ERRO: git fetch falhou em $(date '+%Y-%m-%d %H:%M:%S') - $FETCH_OUTPUT" > "$STATUS_FILE"
    exit 1
fi

PULL_OUTPUT=$(git pull origin main 2>&1 || git pull origin master 2>&1)
PULL_STATUS=$?

if [ $PULL_STATUS -ne 0 ]; then
    log_msg "ERRO" "Falha ao aplicar git pull: $PULL_OUTPUT"
    echo "ERRO: git pull falhou em $(date '+%Y-%m-%d %H:%M:%S') - $PULL_OUTPUT" > "$STATUS_FILE"
    exit 1
fi

log_msg "INFO" "Git pull realizado com sucesso. Detalhes: $PULL_OUTPUT"
log_msg "INFO" "Executando install.sh para aplicar atualizações no sistema..."

INSTALL_OUTPUT=$(bash install.sh 2>&1)
INSTALL_STATUS=$?

if [ $INSTALL_STATUS -eq 0 ]; then
    log_msg "SUCESSO" "Atualização semanal concluída com sucesso!"
    echo "SUCESSO: Atualizado com sucesso em $(date '+%Y-%m-%d %H:%M:%S')" > "$STATUS_FILE"
else
    log_msg "ERRO" "Erro durante a execução do install.sh. Saída do instalador:\n$INSTALL_OUTPUT"
    echo "ERRO: install.sh falhou em $(date '+%Y-%m-%d %H:%M:%S')" > "$STATUS_FILE"
    exit 1
fi

exit 0
