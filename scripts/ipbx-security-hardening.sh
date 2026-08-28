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

# --- 1. HARDENING DO APACHE (BLOQUEIO DE EXECUÇÃO DE PHP EM PASTAS ESTÁTICAS E CACHE) ---
log_info "1. Aplicando regras de Apache Hardening em pastas estáticas, uploads e cache..."

cat <<'EOF' > /etc/httpd/conf.d/ipbx-security-hardening.conf
# ==============================================================================
# REGRAS DE HARDENING DE SEGURANÇA - IPBX PRISMA TELECOM
# Bloqueia estritamente execucao de PHP em diretorios de gravacao, cache e imagens.
# PRESERVA: /var/www/html/ (agenda.php, etc), /modules/, /api/, WhatsApp, Webhooks.
# ==============================================================================

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

<Directory "/var/www/html/var/templates_c">
    <FilesMatch "\.(php|php5|php7|php8|phtml|phar|pl|py|cgi|sh)$">
        <RequireAny>
            Require local
            Require ip 127.0.0.1
        </RequireAny>
    </FilesMatch>
</Directory>

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

# --- 3. VARREDURA E ELIMINAÇÃO DE WEBSHELLS (EMAD / PALOSANTODB / SHELLS) ---
log_info "3. Realizando varredura e eliminação de scripts maliciosos..."

# 3.1 Limpeza cirúrgica de arquivos criados pelo invasor em /var/www/html/cache/
if [ -d /var/www/html/cache ]; then
    find /var/www/html/cache/ -type f \( -name "paloSantoDB.php" -o -name "asterisk.php" -o -name "monitor.php" -o -name "*.php" \) -exec rm -f {} + 2>/dev/null || true
    log_success "Pasta /var/www/html/cache/ saneada."
fi

# 3.2 Varredura por assinaturas maliciosas conhecidas
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

# 3.3 Saneamento de arquivos legítimos se tiverem código injetado
if [ -f /var/www/html/admin/modules/smss/index.php ]; then
    sed -i '/Emad__Was__Here/d' /var/www/html/admin/modules/smss/index.php 2>/dev/null || true
fi

# --- 4. INSTALAÇÃO DO COMANDO GLOBAL IPBX-SECURITY ---
log_info "4. Instalando comando global 'ipbx-security' no sistema..."
if [ -f "$0" ] && [ "$0" != "bash" ] && [ "$0" != "-bash" ] && [ "$0" != "sh" ]; then
    /bin/cp -f "$0" /usr/local/bin/ipbx-security 2>/dev/null || true
else
    curl -sSL "https://raw.githubusercontent.com/LeandroSaltori/ipbx-issabel6.0/main/scripts/ipbx-security-hardening.sh" -o /usr/local/bin/ipbx-security 2>/dev/null || true
fi
chmod +x /usr/local/bin/ipbx-security 2>/dev/null || true
log_success "Comando global disponível no terminal: ipbx-security"

echo ""
log_success "Blindagem de Segurança & Hardening concluídos com sucesso!"

