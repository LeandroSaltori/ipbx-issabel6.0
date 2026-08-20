#!/bin/bash
# ==============================================================================
# CONFIGURAÇÃO AUTOMÁTICA DE DOMÍNIO E SSL (LET'S ENCRYPT) - ISSABEL PBX
# ==============================================================================
# Prisma Telecom - IPBX Issabel
# Configura VirtualHost no Apache, emite certificado SSL gratuito via Certbot
# e integra os certificados com o Webphone / WebRTC (WSS).
# ==============================================================================

set -e

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
log_success() { echo -e "${GREEN}[SUCESSO]${NC} $1"; }
log_warn()    { echo -e "${YELLOW}[AVISO]${NC} $1"; }
log_error()   { echo -e "${RED}[ERRO]${NC} $1"; }

if [ "$EUID" -ne 0 ]; then
    log_error "Este script precisa ser executado como root!"
    exit 1
fi

echo -e "\n${BLUE}══════════════════════════════════════════════════════════════════════${NC}"
echo -e "${WHITE}   CONFIGURAÇÃO DE DOMÍNIO E CERTIFICADO SSL (LET'S ENCRYPT)          ${NC}"
echo -e "${BLUE}══════════════════════════════════════════════════════════════════════${NC}\n"

# 1. Obter informações de Domínio e E-mail
DOMINIO="$1"
EMAIL="$2"

prompt_read() {
    if [ -t 0 ]; then
        read -rp "$1" "$2"
    elif [ -e /dev/tty ]; then
        read -rp "$1" "$2" < /dev/tty
    else
        log_error "Terminal interativo não disponível."
        exit 1
    fi
}

if [ -z "$DOMINIO" ]; then
    prompt_read "$(echo -e "${CYAN}Digite o domínio completo (ex: jaguimar.ipbxprisma.cloud): ${NC}")" DOMINIO
fi

if [ -z "$EMAIL" ]; then
    prompt_read "$(echo -e "${CYAN}Digite seu e-mail [padrão: leandro@prismatelecom.com]: ${NC}")" EMAIL
    EMAIL="${EMAIL:-leandro@prismatelecom.com}"
fi

# Validação dos parâmetros
if [ -z "$DOMINIO" ]; then
    log_error "O domínio é obrigatório para emissão do SSL!"
    exit 1
fi

# Remove espaços
DOMINIO=$(echo "$DOMINIO" | tr -d ' ')
EMAIL=$(echo "$EMAIL" | tr -d ' ')

log_info "Iniciando configuração para o domínio: ${WHITE}$DOMINIO${NC} (E-mail: $EMAIL)..."

# 2. Instalar repositório EPEL e Certbot (compatível com CentOS 7 e Rocky Linux 8/9)
log_info "Garantindo instalação e atualização do Certbot..."
if command -v dnf &>/dev/null; then
    dnf install -y epel-release 2>/dev/null || true
    dnf install -y certbot python3-certbot-apache 2>/dev/null || dnf install -y certbot 2>/dev/null || true
elif command -v yum &>/dev/null; then
    yum install -y epel-release 2>/dev/null || true
    yum install -y certbot python3-certbot-apache 2>/dev/null || yum install -y certbot 2>/dev/null || true
fi

if ! command -v certbot &>/dev/null; then
    log_error "Falha ao instalar o pacote certbot. Verifique a conexão com a internet ou repositórios."
    exit 1
fi

# 3. Criar arquivo de VirtualHost HTTP (Porta 80) no Apache
CONF_FILE="/etc/httpd/conf.d/$DOMINIO.conf"
log_info "Criando arquivo de configuração do Apache em $CONF_FILE..."

cat <<EOF > "$CONF_FILE"
<VirtualHost *:80>
    ServerName $DOMINIO
    ServerAlias $DOMINIO
    DocumentRoot /var/www/html
    ErrorLog logs/${DOMINIO}_error.log
    CustomLog logs/${DOMINIO}_access.log combined
</VirtualHost>
EOF

# 4. Validar sintaxe do Apache
log_info "Validando sintaxe do Apache (configtest)..."
if command -v apachectl &>/dev/null; then
    if ! apachectl configtest &>/dev/null; then
        log_error "Erro na sintaxe do Apache. Revertendo arquivo $CONF_FILE..."
        rm -f "$CONF_FILE"
        exit 1
    fi
fi

systemctl restart httpd 2>/dev/null || systemctl restart apache2 2>/dev/null || true
log_success "VirtualHost HTTP criado e Apache reiniciado."

# 5. Executar Certbot
log_info "Solicitando certificado SSL junto ao Let's Encrypt..."
if certbot --apache -d "$DOMINIO" --non-interactive --agree-tos --email "$EMAIL" --no-eff-email; then
    log_success "Certificado SSL emitido com sucesso para https://$DOMINIO"

    # 6. Integração do Certificado com Asterisk / WebRTC (WSS)
    CERT_DIR="/etc/letsencrypt/live/$DOMINIO"
    if [ -d "$CERT_DIR" ]; then
        log_info "Integrando certificados SSL com o Asterisk WebRTC / WSS..."
        mkdir -p /etc/asterisk/keys 2>/dev/null || true
        cp -f "$CERT_DIR/privkey.pem" /etc/asterisk/keys/webrtc.key 2>/dev/null || true
        cp -f "$CERT_DIR/fullchain.pem" /etc/asterisk/keys/webrtc.crt 2>/dev/null || true
        cat "$CERT_DIR/privkey.pem" "$CERT_DIR/fullchain.pem" > /etc/asterisk/keys/webrtc.pem 2>/dev/null || true
        chown -R asterisk:asterisk /etc/asterisk/keys 2>/dev/null || true
        chmod 600 /etc/asterisk/keys/* 2>/dev/null || true
        asterisk -rx "http reload" 2>/dev/null || true
        log_success "Certificados Asterisk WebRTC atualizados em /etc/asterisk/keys/"
    fi

    # 7. Configuração de Renovação Automática (Crontab / Systemd)
    if ! crontab -l 2>/dev/null | grep -q "certbot renew"; then
        log_info "Agendando renovação automática diária do certificado no crontab..."
        (crontab -l 2>/dev/null; echo "0 3 * * * certbot renew --quiet --post-hook 'systemctl reload httpd; cp -f /etc/letsencrypt/live/$DOMINIO/privkey.pem /etc/asterisk/keys/webrtc.key 2>/dev/null; cp -f /etc/letsencrypt/live/$DOMINIO/fullchain.pem /etc/asterisk/keys/webrtc.crt 2>/dev/null; cat /etc/letsencrypt/live/$DOMINIO/privkey.pem /etc/letsencrypt/live/$DOMINIO/fullchain.pem > /etc/asterisk/keys/webrtc.pem 2>/dev/null; chown -R asterisk:asterisk /etc/asterisk/keys 2>/dev/null; chmod 600 /etc/asterisk/keys/* 2>/dev/null; asterisk -rx \"http reload\" 2>/dev/null'") | crontab -
        log_success "Renovação automática configurada."
    fi

    echo -e "\n${GREEN}══════════════════════════════════════════════════════════════════════${NC}"
    echo -e "${GREEN}  DOMÍNIO E SSL CONFIGURADOS COM SUCESSO!                             ${NC}"
    echo -e "${GREEN}  Acesse o seu PBX em: https://$DOMINIO                              ${NC}"
    echo -e "${GREEN}══════════════════════════════════════════════════════════════════════${NC}\n"
else
    log_error "Ocorreu um erro ao emitir o certificado SSL."
    echo -e "${YELLOW}Verifique se o domínio '$DOMINIO' está apontado corretamente (DNS tipo A) para o IP deste servidor.${NC}"
    exit 1
fi
