#!/bin/bash
# ==============================================================================
# MONITOR DE SEGURANÇA & NOTIFICAÇÕES TELEGRAM - IPBX ISSABEL
# ==============================================================================
# Prisma Telecom - IPBX Issabel
# 1. Detecta backdoors e web shells (Emad, c99, r57, eval base64) em /var/www/html/
# 2. Monitora status do Firewall (iptables / firewalld) e reativa caso caia
# 3. Detecta novos usuários administradores criados na interface WEB (acl.db)
# ==============================================================================

# Configurações do Telegram
CLIENTE_DEFAULT=$(hostname -f 2>/dev/null || hostname 2>/dev/null || echo "IPBX Issabel")
CLIENTE="${CLIENTE_NAME:-$CLIENTE_DEFAULT}"
TOKEN="7558673015:AAEk7FbRtOXCB2xiiQ1fRT0Hwi-KEjf4JlI"
CHAT_ID="-1003562264947"

DB_PATH="/var/www/db/acl.db"
SNAPSHOT_FILE="/var/log/issabel_users_snapshot.txt"
WEB_DIR="/var/www/html/"

# Função de Envio Telegram
send_tg() {
    local text="$1"
    /usr/bin/curl -s -X POST "https://api.telegram.org/bot$TOKEN/sendMessage" \
        -d "chat_id=$CHAT_ID" \
        -d "text=$text" \
        -d "parse_mode=Markdown" > /dev/null 2>&1 || true
}

# --- 1. BUSCA POR RASTRO DE INVASÃO E WEB SHELLS ---
SUSPICIOUS_TERMS=("Emad__Was__Here" "c99shell" "r57shell" "eval(base64_decode" "passthru(\$_GET" "shell_exec(\$_POST")

for TERM in "${SUSPICIOUS_TERMS[@]}"; do
    FILES=$(/usr/bin/grep -rl "$TERM" "$WEB_DIR" 2>/dev/null | /usr/bin/head -n 3 || true)
    if [ -n "$FILES" ]; then
        MSG="%F0%9F%9A%A8 *ALERTA: INVASAO / WEB SHELL DETECTADA* %F0%9F%9A%A8%0A%0A"
        MSG="${MSG}%F0%9F%93%8C *Servidor:* ${CLIENTE}%0A"
        MSG="${MSG}%F0%9F%95%B5%EF%B8%8F *Assinatura:* ${TERM}%0A"
        MSG="${MSG}%F0%9F%93%82 *Arquivo:* ${FILES}%0A"
        MSG="${MSG}%F0%9F%93%85 *Data:* $(date '+%d/%m/%Y %H:%M:%S')"
        send_tg "$MSG"
        break
    fi
done

# --- 2. MONITOR DE FIREWALL (STATUS E REGRAS) ---
if systemctl is-enabled iptables &>/dev/null || systemctl is-active iptables &>/dev/null; then
    STATUS_FW=$(/usr/bin/systemctl is-active iptables 2>/dev/null || echo "inactive")
    REGRAS=$(/sbin/iptables -L -n 2>/dev/null | /usr/bin/wc -l || echo 0)

    if [ "$STATUS_FW" != "active" ] || [ "$REGRAS" -lt 10 ]; then
        MSG="%F0%9F%94%A5 *ALERTA: FIREWALL IPTABLES DOWN* %F0%9F%94%A5%0A%0A"
        MSG="${MSG}%F0%9F%93%8C *Servidor:* ${CLIENTE}%0A"
        MSG="${MSG}%F0%9F%9A%A7 *Status:* ${STATUS_FW}%0A"
        MSG="${MSG}%F0%9F%9B%A1 *Acao:* Reativando Firewall...%0A"
        MSG="${MSG}%F0%9F%93%85 *Data:* $(date '+%d/%m/%Y %H:%M:%S')"
        send_tg "$MSG"
        /usr/bin/systemctl restart iptables 2>/dev/null || true
    fi
elif systemctl is-enabled firewalld &>/dev/null || systemctl is-active firewalld &>/dev/null; then
    STATUS_FW=$(/usr/bin/systemctl is-active firewalld 2>/dev/null || echo "inactive")
    if [ "$STATUS_FW" != "active" ]; then
        MSG="%F0%9F%94%A5 *ALERTA: FIREWALLD DOWN* %F0%9F%94%A5%0A%0A"
        MSG="${MSG}%F0%9F%93%8C *Servidor:* ${CLIENTE}%0A"
        MSG="${MSG}%F0%9F%9A%A7 *Status:* ${STATUS_FW}%0A"
        MSG="${MSG}%F0%9F%9B%A1 *Acao:* Reativando Firewalld...%0A"
        MSG="${MSG}%F0%9F%93%85 *Data:* $(date '+%d/%m/%Y %H:%M:%S')"
        send_tg "$MSG"
        /usr/bin/systemctl restart firewalld 2>/dev/null || true
    fi
fi

# --- 3. MONITOR DE USUÁRIOS WEB (SQLITE acl.db) ---
if [ -f "$DB_PATH" ] && command -v sqlite3 &>/dev/null; then
    CURRENT_USERS=$(/usr/bin/sqlite3 "$DB_PATH" "SELECT name || '|' || description FROM acl_user;" 2>/dev/null || true)

    if [ ! -f "$SNAPSHOT_FILE" ]; then
        echo "$CURRENT_USERS" > "$SNAPSHOT_FILE"
    else
        OLD_USERS=$(cat "$SNAPSHOT_FILE")
        echo "$CURRENT_USERS" | while read -r LINE; do
            if [ -n "$LINE" ] && ! echo "$OLD_USERS" | /usr/bin/grep -qxF "$LINE"; then
                USER_NAME=$(echo "$LINE" | cut -d'|' -f1)
                USER_DESC=$(echo "$LINE" | cut -d'|' -f2)

                MSG="%E2%9A%A0 *ALERTA: NOVO USUARIO WEB CRIADO* %E2%9A%A0%0A%0A"
                MSG="${MSG}%F0%9F%93%8C *Servidor:* ${CLIENTE}%0A"
                MSG="${MSG}%F0%9F%91%A4 *Login:* ${USER_NAME}%0A"
                MSG="${MSG}%F0%9F%93%9D *Descricao:* ${USER_DESC}%0A"
                MSG="${MSG}%F0%9F%93%85 *Data:* $(date '+%d/%m/%Y %H:%M:%S')"
                send_tg "$MSG"
            fi
        done
        echo "$CURRENT_USERS" > "$SNAPSHOT_FILE"
    fi
fi
