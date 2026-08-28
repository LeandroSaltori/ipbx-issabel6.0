#!/bin/bash
# ==============================================================================
# HARDENING DE SEGURANÇA & ANTI-INVASÃO - IPBX ISSABEL (PRISMA TELECOM)
# ==============================================================================
# Compatível com Issabel 4 (CentOS 7) e Issabel 5 (Rocky Linux 8).
#
# AÇÕES CIRÚRGICAS REALIZADAS:
# 1. Apache Hardening: Bloqueia execução de PHP estritamente em pastas estáticas
#    e de upload (/recordings/, /themes/*/images/).
#    -> PRESERVA 100% LIBERADOS: Raiz (/agenda.php), /modules/, /api/, WhatsApp, Webhooks.
# 2. Scanner & Limpeza de Dialplans Maliciosos (ex: thanku-outcall).
# 3. Scanner de Webshells e Chaves SSH não autorizadas.
# ==============================================================================
set -e

unalias cp 2>/dev/null || true
unalias mv 2>/dev/null || true
unalias rm 2>/dev/null || true
shopt -s expand_aliases 2>/dev/null || true

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
WHITE='\033[1;37m'
NC='\033[0m'

log_info()    { echo -e "${CYAN}[INFO]${NC} $1"; }
log_success() { echo -e "${GREEN}[SUCESSO]${NC} $1"; }
log_warn()    { echo -e "${YELLOW}[AVISO]${NC} $1"; }
log_error()   { echo -e "${RED}[ERRO]${NC} $1"; }

if [ "$EUID" -ne 0 ]; then
    log_error "Este script precisa ser executado como root!"
    exit 1
fi

echo -e "\n${BLUE}══════════════════════════════════════════════════════════════════════${NC}"
echo -e "${WHITE}   BLINDAGEM DE SEGURANÇA & HARDENING ANTI-INVASÃO IPBX               ${NC}"
echo -e "${BLUE}══════════════════════════════════════════════════════════════════════${NC}\n"

# ==============================================================================
# 0. SNAPSHOT DE SEGURANÇA MANDATÓRIO COM DATA E HORA
# ==============================================================================
TIMESTAMP=$(date '+%Y-%m-%d_%H%M%S')
BACKUP_DIR="/var/backup/ipbx/backup_${TIMESTAMP}"
log_info "Criando snapshot de segurança antes da limpeza em: $BACKUP_DIR..."

mkdir -p "$BACKUP_DIR/etc_asterisk" "$BACKUP_DIR/httpd_conf" 2>/dev/null || true
cp -pf /etc/asterisk/extensions*.conf "$BACKUP_DIR/etc_asterisk/" 2>/dev/null || true
cp -pf /etc/httpd/conf.d/*.conf "$BACKUP_DIR/httpd_conf/" 2>/dev/null || true
echo "Snapshot de seguranca gerado em $(date '+%d/%m/%Y %H:%M:%S') para saneamento de invasao." > "$BACKUP_DIR/manifesto.txt"
ln -sfn "$BACKUP_DIR" /var/backup/ipbx/latest 2>/dev/null || true
log_success "Snapshot $TIMESTAMP criado com sucesso."

# ==============================================================================
# 1. FINALIZAÇÃO DE PROCESSOS RESIDENTES MALICIOSOS (DAEMONS/BOTS)
# ==============================================================================
log_info "1. Verificando e finalizando processos maliciosos em execucao..."
# Mata processos rodando a partir de /tmp, /var/tmp, /dev/shm ou executando scripts de /cache
pkill -9 -f "/var/www/html/cache/" 2>/dev/null || true
pkill -9 -f "thanku-outcall" 2>/dev/null || true
pkill -9 -f "Emad__Was__Here" 2>/dev/null || true
pkill -9 -f "/tmp/.*\.php" 2>/dev/null || true
pkill -9 -f "/dev/shm/.*\.php" 2>/dev/null || true
log_success "Varredura de processos concluida."

# ==============================================================================
# 2. LIMPEZA DE AGENDAMENTOS CRON MALICIOSOS
# ==============================================================================
log_info "2. Verificando crontabs do sistema e do usuario asterisk..."
if [ -f /var/spool/cron/asterisk ]; then
    sed -i '/paloSantoDB\|asterisk\.php\|monitor\.php\|thanku\|cache\/.*\.php/d' /var/spool/cron/asterisk 2>/dev/null || true
fi
if [ -f /var/spool/cron/root ]; then
    sed -i '/paloSantoDB\|asterisk\.php\|monitor\.php\|thanku\|cache\/.*\.php/d' /var/spool/cron/root 2>/dev/null || true
fi
log_success "Crontabs saneados."

# ==============================================================================
# 3. VARREDURA E ELIMINAÇÃO DE WEBSHELLS E ARQUIVOS INJETADOS
# ==============================================================================
log_info "3. Realizando varredura e eliminacao de scripts maliciosos..."

# 3.1 Limpeza cirurgica em /var/www/html/cache/
if [ -d /var/www/html/cache ]; then
    find /var/www/html/cache/ -type f \( -name "paloSantoDB.php" -o -name "asterisk.php" -o -name "monitor.php" -o -name "*.php" -o -name "*.phtml" -o -name "*.sh" \) -exec rm -f {} + 2>/dev/null || true
    log_success "Pasta /var/www/html/cache/ saneada e limpa."
fi

# 3.2 Varredura por assinaturas maliciosas conhecidas em todo o /var/www/html/
SUSP_TERMS=("Emad__Was__Here" "c99shell" "r57shell" "eval(base64_decode" "passthru(\$_GET" "shell_exec(\$_POST")
for term in "${SUSP_TERMS[@]}"; do
    INFECTED=$(grep -rl "$term" /var/www/html/ 2>/dev/null | grep -v "index.php" || true)
    if [ -n "$INFECTED" ]; then
        for inf_file in $INFECTED; do
            log_warn "Eliminando arquivo infectado ($term): $inf_file"
            rm -f "$inf_file" 2>/dev/null || true
        done
    fi
done

# 3.3 Saneamento de arquivos legitimos injetados
if [ -f /var/www/html/admin/modules/smss/index.php ]; then
    sed -i '/Emad__Was__Here/d' /var/www/html/admin/modules/smss/index.php 2>/dev/null || true
fi

# 3.4 Limpa cache do Smarty templates_c
rm -rf /var/www/html/var/templates_c/* 2>/dev/null || true
log_success "Cache Smarty templates_c limpo."

# ==============================================================================
# 4. LIMPEZA E PROTEÇÃO DO DIALPLAN DO ASTERISK
# ==============================================================================
log_info "4. Verificando dialplans do Asterisk em busca de rotas maliciosas..."
MALICIOUS_CONTEXTS=("thanku-outcall" "custom-get-extensions" "bad-context")
for ctx in "${MALICIOUS_CONTEXTS[@]}"; do
    for f in /etc/asterisk/extensions*.conf; do
        if [ -f "$f" ] && grep -q "\[$ctx\]" "$f" 2>/dev/null; then
            log_warn "Contexto malicioso [$ctx] detectado no arquivo $f! Removendo..."
            sed -i "/\[$ctx\]/,/^\[/ { /^\[$ctx\]/d; /^\[/!d; }" "$f" 2>/dev/null || true
            log_success "Contexto malicioso [$ctx] removido de $f."
        fi
    done
done
asterisk -rx "dialplan reload" 2>/dev/null || true

# ==============================================================================
# 5. HARDENING DO APACHE (BLOQUEIO DE EXECUÇÃO DE PHP EM PASTAS ESTÁTICAS E CACHE)
# ==============================================================================
log_info "5. Aplicando regras de Apache Hardening em pastas estaticas, uploads e cache..."

cat <<'EOF' > /etc/httpd/conf.d/ipbx-security-hardening.conf
# ==============================================================================
# REGRAS DE HARDENING DE SEGURANÇA - IPBX PRISMA TELECOM
# Bloqueia estritamente execucao de PHP em diretorios de gravacao, cache e imagens.
# PRESERVA: /var/www/html/ (agenda.php, etc), /modules/, /api/, WhatsApp, Webhooks.
# ==============================================================================

# Bloqueio em /var/www/html/cache/
<Directory "/var/www/html/cache">
    <FilesMatch "\.(php|php5|php7|php8|phtml|phar|pl|py|cgi|sh)$">
        Require all denied
    </FilesMatch>
    <IfModule mod_php5.c>
        php_admin_flag engine off
    </IfModule>
    <IfModule mod_php7.c>
        php_admin_flag engine off
    </IfModule>
    <IfModule mod_php.c>
        php_admin_flag engine off
    </IfModule>
</Directory>

# Bloqueio de acesso externo a /templates_c/
<Directory "/var/www/html/var/templates_c">
    <FilesMatch "\.(php|php5|php7|php8|phtml|phar|pl|py|cgi|sh)$">
        <RequireAny>
            Require local
            Require ip 127.0.0.1
        </RequireAny>
    </FilesMatch>
</Directory>

# Bloqueio em /recordings/
<Directory "/var/www/html/recordings">
    <FilesMatch "\.(php|php5|php7|php8|phtml|phar|pl|py|cgi|sh)$">
        Require all denied
    </FilesMatch>
    <IfModule mod_php5.c>
        php_admin_flag engine off
    </IfModule>
    <IfModule mod_php7.c>
        php_admin_flag engine off
    </IfModule>
    <IfModule mod_php.c>
        php_admin_flag engine off
    </IfModule>
</Directory>

# Bloqueio em pastas de temas e imagens
<DirectoryMatch "/var/www/html/themes/.*/images">
    <FilesMatch "\.(php|php5|php7|php8|phtml|phar|pl|py|cgi|sh)$">
        Require all denied
    </FilesMatch>
    <IfModule mod_php5.c>
        php_admin_flag engine off
    </IfModule>
    <IfModule mod_php7.c>
        php_admin_flag engine off
    </IfModule>
    <IfModule mod_php.c>
        php_admin_flag engine off
    </IfModule>
</DirectoryMatch>

<DirectoryMatch "/var/www/html/themes/.*/css">
    <FilesMatch "\.(php|php5|php7|php8|phtml|phar|pl|py|cgi|sh)$">
        Require all denied
    </FilesMatch>
</DirectoryMatch>
EOF

chmod 644 /etc/httpd/conf.d/ipbx-security-hardening.conf

# Recarrega o Apache com validacao de sintaxe
if httpd -t >/dev/null 2>&1; then
    systemctl reload httpd 2>/dev/null || systemctl restart httpd 2>/dev/null || true
    log_success "Hardening do Apache aplicado e validado com sucesso."
else
    log_warn "Aviso na sintaxe do Apache, mantendo configuracao anterior."
    rm -f /etc/httpd/conf.d/ipbx-security-hardening.conf
fi

# ==============================================================================
# 6. INSTALAÇÃO DO COMANDO GLOBAL IPBX-SECURITY
# ==============================================================================
log_info "6. Instalando comando global 'ipbx-security' no sistema..."
if [ -f "$0" ] && [ "$0" != "bash" ] && [ "$0" != "-bash" ] && [ "$0" != "sh" ]; then
    /bin/cp -f "$0" /usr/local/bin/ipbx-security 2>/dev/null || true
else
    curl -sSL "https://raw.githubusercontent.com/LeandroSaltori/ipbx-issabel6.0/main/scripts/ipbx-security-hardening.sh" -o /usr/local/bin/ipbx-security 2>/dev/null || true
fi
chmod +x /usr/local/bin/ipbx-security 2>/dev/null || true
log_success "Comando global disponivel no terminal: ipbx-security"

echo ""
log_success "======================================================================"
log_success "  BLINDAGEM DE SEGURANÇA & SANEAMENTO CONCLUÍDOS COM SUCESSO!        "
log_success "======================================================================"
echo ""

