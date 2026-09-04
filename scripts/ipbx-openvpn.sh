#!/bin/bash
# ==============================================================================
# OPENVPN / EASYVPN PARA ISSABEL 5 (ROCKY LINUX 8)
# Baseado 100% no procedimento oficial da Comunidade Issabel:
# https://forum.issabel.org/d/16950-issabel-easyvpn
# ==============================================================================

set -e

RED='\033[0;31m'
GREEN='\033[0;32m'
CYAN='\033[0;36m'
YELLOW='\033[1;33m'
NC='\033[0m'

log_info()    { echo -e "${CYAN}[INFO]${NC} $1"; }
log_success() { echo -e "${GREEN}[SUCESSO]${NC} $1"; }
log_warn()    { echo -e "${YELLOW}[AVISO]${NC} $1"; }
log_error()   { echo -e "${RED}[ERRO]${NC} $1"; }

if [ "$EUID" -ne 0 ]; then
    log_error "Este script precisa ser executado como root!"
    exit 1
fi

echo ""
log_info "Iniciando configuração limpa do OpenVPN (Base Fórum Issabel)..."

# 0. Limpeza de serviços e customizações anteriores (Reset limpo)
log_info "Removendo configurações customizadas anteriores..."
systemctl stop ipbx-openvpn-sanitize.service ipbx-openvpn-sanitize.path 2>/dev/null || true
systemctl disable ipbx-openvpn-sanitize.service ipbx-openvpn-sanitize.path 2>/dev/null || true
rm -f /etc/systemd/system/ipbx-openvpn-sanitize.* 2>/dev/null || true
rm -f /etc/cron.d/ipbx-openvpn 2>/dev/null || true
rm -f /usr/local/bin/ipbx-openvpn-helper.sh 2>/dev/null || true
systemctl daemon-reload 2>/dev/null || true

# 1. Passos 1 e 2 do Fórum: Easy-RSA 3.0.8
log_info "1. Configurando Easy-RSA 3.0.8..."
mkdir -p /usr/share/easy-rsa/3.0.8/

if [ ! -f /usr/share/easy-rsa/3.0.8/easyrsa ]; then
    TMP="/tmp/easyrsa_setup_$$"
    rm -rf "$TMP" && mkdir -p "$TMP"
    cd "$TMP"
    wget -q https://github.com/OpenVPN/easy-rsa/releases/download/v3.0.8/EasyRSA-3.0.8.tgz || \
    curl -sSL -o EasyRSA-3.0.8.tgz https://github.com/OpenVPN/easy-rsa/releases/download/v3.0.8/EasyRSA-3.0.8.tgz
    
    tar -xzf EasyRSA-3.0.8.tgz
    cp -rpf EasyRSA-3.0.8/* /usr/share/easy-rsa/3.0.8/
    cd /root
    rm -rf "$TMP"
fi
chmod -R 755 /usr/share/easy-rsa/3.0.8/
log_success "Easy-RSA 3.0.8 OK em /usr/share/easy-rsa/3.0.8/"

# 2. Passo 3 do Fórum: Instalar issabel-easyvpn
log_info "2. Instalando pacote issabel-easyvpn..."
if command -v dnf &>/dev/null; then
    dnf install -y issabel-easyvpn openvpn
else
    yum install -y issabel-easyvpn openvpn
fi
log_success "Pacote issabel-easyvpn instalado."

# 3. IP Forwarding
echo "net.ipv4.ip_forward = 1" > /etc/sysctl.d/99-openvpn.conf
sysctl -p /etc/sysctl.d/99-openvpn.conf 2>/dev/null || sysctl -w net.ipv4.ip_forward=1 2>/dev/null || true

# 4. Estrutura de diretórios do Rocky Linux 8
mkdir -p /etc/openvpn/server /etc/openvpn/client /etc/openvpn/ccd /var/log/openvpn

# 5. Resolução do erro '--crl-verify fails with crl.pem' e sincronização de certificados
log_info "3. Verificando certificados e gerando crl.pem..."

# Se crl.pem não existe na PKI, gera com o easyrsa
for EASYDIR in /usr/share/easy-rsa/3.0.8 /etc/openvpn/easy-rsa; do
    if [ -d "$EASYDIR/pki" ] && [ -f "$EASYDIR/easyrsa" ]; then
        if [ ! -f "$EASYDIR/pki/crl.pem" ]; then
            log_info "Gerando crl.pem via easyrsa em $EASYDIR..."
            (cd "$EASYDIR" && ./easyrsa gen-crl 2>/dev/null || true)
        fi
        # Copia da PKI para /etc/openvpn e /etc/openvpn/server
        [ -f "$EASYDIR/pki/crl.pem" ] && cp -f "$EASYDIR/pki/crl.pem" /etc/openvpn/crl.pem && cp -f "$EASYDIR/pki/crl.pem" /etc/openvpn/server/crl.pem
        [ -f "$EASYDIR/pki/ca.crt" ] && cp -f "$EASYDIR/pki/ca.crt" /etc/openvpn/ca.crt && cp -f "$EASYDIR/pki/ca.crt" /etc/openvpn/server/ca.crt
        [ -f "$EASYDIR/pki/issued/server.crt" ] && cp -f "$EASYDIR/pki/issued/server.crt" /etc/openvpn/server.crt && cp -f "$EASYDIR/pki/issued/server.crt" /etc/openvpn/server/server.crt
        [ -f "$EASYDIR/pki/private/server.key" ] && cp -f "$EASYDIR/pki/private/server.key" /etc/openvpn/server.key && cp -f "$EASYDIR/pki/private/server.key" /etc/openvpn/server/server.key
        [ -f "$EASYDIR/pki/dh.pem" ] && cp -f "$EASYDIR/pki/dh.pem" /etc/openvpn/dh.pem && cp -f "$EASYDIR/pki/dh.pem" /etc/openvpn/server/dh.pem
        break
    fi
done

# Garante que crl.pem exista em ambas as pastas
if [ -f /etc/openvpn/crl.pem ] && [ ! -f /etc/openvpn/server/crl.pem ]; then
    cp -f /etc/openvpn/crl.pem /etc/openvpn/server/crl.pem
elif [ -f /etc/openvpn/server/crl.pem ] && [ ! -f /etc/openvpn/crl.pem ]; then
    cp -f /etc/openvpn/server/crl.pem /etc/openvpn/crl.pem
fi

# Se server.conf existir em /etc/openvpn, copia para /etc/openvpn/server/ (diretório de trabalho do Rocky 8)
if [ -f /etc/openvpn/server.conf ]; then
    cp -f /etc/openvpn/server.conf /etc/openvpn/server/server.conf
fi
if [ -f /etc/openvpn/server/server.conf ]; then
    cp -f /etc/openvpn/server/server.conf /etc/openvpn/server.conf
fi

# Copia todos os certificados e chaves para /etc/openvpn/server/
for f in ca.crt server.crt server.key dh.pem dh2048.pem crl.pem; do
    if [ -f "/etc/openvpn/$f" ] && [ ! -f "/etc/openvpn/server/$f" ]; then
        cp -f "/etc/openvpn/$f" "/etc/openvpn/server/$f"
    fi
    if [ -f "/etc/openvpn/server/$f" ] && [ ! -f "/etc/openvpn/$f" ]; then
        cp -f "/etc/openvpn/server/$f" "/etc/openvpn/$f"
    fi
done

# Compatibilidade dh.pem e dh2048.pem
for D in /etc/openvpn /etc/openvpn/server; do
    if [ -f "$D/dh2048.pem" ] && [ ! -f "$D/dh.pem" ]; then
        cp -f "$D/dh2048.pem" "$D/dh.pem"
    elif [ -f "$D/dh.pem" ] && [ ! -f "$D/dh2048.pem" ]; then
        cp -f "$D/dh.pem" "$D/dh2048.pem"
    fi
done

# Permissões seguras
chmod 600 /etc/openvpn/server.key /etc/openvpn/server/server.key 2>/dev/null || true
chmod 644 /etc/openvpn/*.crt /etc/openvpn/*.pem /etc/openvpn/server/*.crt /etc/openvpn/server/*.pem 2>/dev/null || true

# 6. Passo 4 do Fórum: Habilitar e iniciar o serviço
log_info "4. Habilitando e iniciando openvpn-server@server.service..."
systemctl daemon-reload
systemctl -f enable openvpn-server@server.service 2>/dev/null || true
systemctl restart openvpn-server@server.service 2>/dev/null || true

# 7. Sudo para interface web controlar o serviço sem senha
cat << 'EOF' > /etc/sudoers.d/99-openvpn-asterisk
asterisk ALL=(ALL) NOPASSWD: /usr/bin/systemctl * openvpn-server@server.service, /usr/bin/systemctl * openvpn-server@server
EOF
chmod 440 /etc/sudoers.d/99-openvpn-asterisk 2>/dev/null || true

echo ""
log_info "Verificando status do serviço..."
if systemctl is-active openvpn-server@server.service &>/dev/null; then
    log_success "Servidor OpenVPN está ATIVO e RODANDO com sucesso!"
    ss -tulpn | grep openvpn || true
else
    log_warn "O serviço ainda não subiu. Verificando log recente:"
    journalctl -u openvpn-server@server.service -n 10 --no-pager 2>/dev/null || true
fi

echo ""
log_success "Concluído conforme guia oficial do Fórum Issabel."
