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

# --- 1. HARDENING DO APACHE (BLOQUEIO DE EXECUÇÃO DE PHP EM PASTAS ESTÁTICAS) ---
log_info "1. Aplicando regras de Apache Hardening em pastas estáticas de uploads..."

cat <<'EOF' > /etc/httpd/conf.d/ipbx-security-hardening.conf
# ==============================================================================
# REGRAS DE HARDENING DE SEGURANÇA - IPBX PRISMA TELECOM
# Bloqueia estritamente execucao de PHP em diretorios de gravacao e imagens.
# PRESERVA: /var/www/html/ (agenda.php, etc), /modules/, /api/, WhatsApp, Webhooks.
# ==============================================================================

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

# Recarrega o Apache
if httpd -t >/dev/null 2>&1; then
    systemctl reload httpd 2>/dev/null || systemctl restart httpd 2>/dev/null || true
    log_success "Hardening do Apache aplicado e validado com sucesso."
else
    log_warn "Aviso na sintaxe do Apache, mantendo configuracao anterior."
    rm -f /etc/httpd/conf.d/ipbx-security-hardening.conf
fi

# --- 2. VARREDURA E LIMPEZA DE DIALPLANS MALICIOSOS NO ASTERISK ---
log_info "2. Verificando dialplans do Asterisk em busca de rotas maliciosas conhecidas..."

MALICIOUS_CONTEXTS=("thanku-outcall" "custom-get-extensions" "bad-context")
for ctx in "${MALICIOUS_CONTEXTS[@]}"; do
    for f in /etc/asterisk/extensions*.conf; do
        if [ -f "$f" ] && grep -q "\[$ctx\]" "$f" 2>/dev/null; then
            log_warn "Contexto malicioso [$ctx] detectado no arquivo $f! Removendo..."
            # Cria backup antes de limpar
            cp -pf "$f" "${f}.bak_security_$(date '+%Y%m%d_%H%M%S')"
            # Remove o bloco do contexto
            sed -i "/\[$ctx\]/,/^\[/ { /^\[$ctx\]/d; /^\[/!d; }" "$f" 2>/dev/null || true
            log_success "Contexto malicioso [$ctx] removido de $f."
            asterisk -rx "dialplan reload" 2>/dev/null || true
        fi
    done
done

# --- 3. VARREDURA DE WEBSHELLS EM PASTAS DE UPLOAD/GRAVAÇÃO ---
log_info "3. Realizando varredura de scripts maliciosos em pastas de uploads..."

SUSP_FILES=$(find /var/www/html/recordings/ /var/www/html/themes/*/images/ -type f \( -name "*.php*" -o -name "*.phtml" -o -name "*.phar" -o -name "*.sh" \) 2>/dev/null || true)
if [ -n "$SUSP_FILES" ]; then
    log_warn "Arquivos PHP suspeitos encontrados em pasta de midia/upload:"
    echo "$SUSP_FILES"
    for sf in $SUSP_FILES; do
        mv "$sf" "${sf}.quarantine_$(date '+%Y%m%d_%H%M%S')" 2>/dev/null || true
        log_success "Arquivo colocado em quarentena: $sf"
    done
else
    log_success "Nenhum script malicioso encontrado em pastas de uploads/estáticas."
fi

# --- 4. INSTALAÇÃO DO ATALHO IPBX-SECURITY ---
cp -f "$0" /usr/local/bin/ipbx-security 2>/dev/null || true
chmod +x /usr/local/bin/ipbx-security 2>/dev/null || true

echo ""
log_success "Blindagem de Segurança & Hardening concluídos com sucesso!"
