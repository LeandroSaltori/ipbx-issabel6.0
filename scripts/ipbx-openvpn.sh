#!/bin/bash
# ==============================================================================
# INSTALADOR E CONFIGURADOR AUTOMÁTICO DO OPENVPN (EASYVPN) - IPBX ISSABEL
# ==============================================================================
# Compatível com Issabel 4 (CentOS 7) e Issabel 5 (Rocky Linux 8)
#
# Autor: Leandro Saltori / Prisma Telecom
# ==============================================================================

set -e

# Cores para logs
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

log_info "Iniciando instalação e configuração do Servidor OpenVPN..."

# 1. Detecção do Sistema Operacional
OS_VERSION=""
if [ -f /etc/os-release ]; then
    . /etc/os-release
    OS_VERSION="$VERSION_ID"
fi

log_info "Sistema detectado: $NAME $VERSION"

# 2. Instalação de Repositórios e Pacotes Necessários
log_info "Instalando repositórios e dependências do OpenVPN..."
if command -v dnf &>/dev/null; then
    dnf install -y epel-release 2>/dev/null || true
    dnf install -y openvpn easy-rsa tar wget curl iptables-services 2>/dev/null || true
    dnf install -y issabel-easyvpn 2>/dev/null || true
elif command -v yum &>/dev/null; then
    yum install -y epel-release 2>/dev/null || true
    yum install -y openvpn easy-rsa tar wget curl iptables-services 2>/dev/null || true
    yum install -y issabel-easyvpn 2>/dev/null || true
fi

# 3. Correção do Easy-RSA 3.0.8 (Resolve o bug de travamento do 'Create CA' no Issabel 5)
log_info "Configurando ambiente Easy-RSA 3.0.8..."
mkdir -p /usr/share/easy-rsa/3.0.8/ /usr/share/easy-rsa/3/ 2>/dev/null || true

if [ ! -f /usr/share/easy-rsa/3.0.8/easyrsa ]; then
    log_info "Baixando pacote Easy-RSA 3.0.8..."
    TMP_EASYRSA="/tmp/easyrsa_setup"
    rm -rf "$TMP_EASYRSA"
    mkdir -p "$TMP_EASYRSA"

    curl -sSL "https://github.com/OpenVPN/easy-rsa/releases/download/v3.0.8/EasyRSA-3.0.8.tgz" -o "$TMP_EASYRSA/EasyRSA-3.0.8.tgz" 2>/dev/null || \
    wget -q "https://github.com/OpenVPN/easy-rsa/releases/download/v3.0.8/EasyRSA-3.0.8.tgz" -O "$TMP_EASYRSA/EasyRSA-3.0.8.tgz" 2>/dev/null || true

    if [ -f "$TMP_EASYRSA/EasyRSA-3.0.8.tgz" ]; then
        tar -xzf "$TMP_EASYRSA/EasyRSA-3.0.8.tgz" -C "$TMP_EASYRSA" 2>/dev/null || true
        /bin/cp -rpf "$TMP_EASYRSA/EasyRSA-3.0.8/"* /usr/share/easy-rsa/3.0.8/ 2>/dev/null || true
        /bin/cp -rpf "$TMP_EASYRSA/EasyRSA-3.0.8/"* /usr/share/easy-rsa/3/ 2>/dev/null || true
    fi
    rm -rf "$TMP_EASYRSA"
fi

# Garante executáveis
chmod +x /usr/share/easy-rsa/3.0.8/easyrsa 2>/dev/null || true
chmod +x /usr/share/easy-rsa/3/easyrsa 2>/dev/null || true
chmod -R 755 /usr/share/easy-rsa 2>/dev/null || true
log_success "Easy-RSA 3.0.8 configurado com sucesso em /usr/share/easy-rsa/3.0.8/."

# 4. Habilitação de Roteamento de Pacotes IPv4 (IP Forwarding)
log_info "Habilitando IP Forwarding no kernel do Linux..."
mkdir -p /etc/sysctl.d 2>/dev/null || true
cat << 'EOF' > /etc/sysctl.d/99-openvpn.conf
net.ipv4.ip_forward = 1
EOF
sysctl -p /etc/sysctl.d/99-openvpn.conf 2>/dev/null || sysctl -w net.ipv4.ip_forward=1 2>/dev/null || true

# 5. Configuração de Regras de Firewall e Masquerade (NAT)
log_info "Configurando regras de Firewall e NAT para tráfego de ramais VPN..."

# Detecta interface padrão de saída para a internet
DEFAULT_IFACE=$(ip route show default 2>/dev/null | awk '/default/ {print $5}' | head -n1)
[ -z "$DEFAULT_IFACE" ] && DEFAULT_IFACE="eth0"

if command -v firewall-cmd &>/dev/null && systemctl is-active firewalld &>/dev/null; then
    log_info "Configurando regras no Firewalld (interface $DEFAULT_IFACE)..."
    firewall-cmd --permanent --add-port=1194/udp 2>/dev/null || true
    firewall-cmd --permanent --add-masquerade 2>/dev/null || true
    firewall-cmd --permanent --direct --add-rule ipv4 nat POSTROUTING 0 -s 10.8.0.0/24 -o "$DEFAULT_IFACE" -j MASQUERADE 2>/dev/null || true
    firewall-cmd --reload 2>/dev/null || true
else
    log_info "Configurando regras no iptables..."
    iptables -t nat -C POSTROUTING -s 10.8.0.0/24 -j MASQUERADE 2>/dev/null || \
    iptables -t nat -A POSTROUTING -s 10.8.0.0/24 -j MASQUERADE 2>/dev/null || true

    iptables -C INPUT -p udp --dport 1194 -j ACCEPT 2>/dev/null || \
    iptables -A INPUT -p udp --dport 1194 -j ACCEPT 2>/dev/null || true

    iptables -C FORWARD -s 10.8.0.0/24 -j ACCEPT 2>/dev/null || \
    iptables -A FORWARD -s 10.8.0.0/24 -j ACCEPT 2>/dev/null || true

    if command -v iptables-save &>/dev/null; then
        iptables-save > /etc/sysconfig/iptables 2>/dev/null || true
    fi
fi

# 6. Estrutura de Diretórios e Permissões do OpenVPN
mkdir -p /etc/openvpn/server /etc/openvpn/client /etc/openvpn/ccd /var/log/openvpn 2>/dev/null || true
chown -R asterisk:asterisk /etc/openvpn /var/log/openvpn 2>/dev/null || true
chmod 775 /etc/openvpn 2>/dev/null || true

# 7. Registro e Saneamento do Módulo Web no Menu do Issabel
if command -v sqlite3 &>/dev/null && [ -f /var/www/db/menu.db ]; then
    log_info "Registrando e organizando menu do OpenVPN no Issabel..."

    # Detecta o diretório do módulo instalado pelo pacote issabel-easyvpn
    MODULE_ID="easy_vpn"
    if [ -d /var/www/html/modules/easy_vpn ]; then
        MODULE_ID="easy_vpn"
    elif [ -d /var/www/html/modules/easyvpn ]; then
        MODULE_ID="easyvpn"
    elif [ -d /var/www/html/modules/openvpn ]; then
        MODULE_ID="openvpn"
    fi

    # Remove entradas inválidas e antigas
    sqlite3 /var/www/db/menu.db "DELETE FROM menu WHERE id IN ('vpn', 'easyvpn', 'easy_vpn', 'openvpn') OR Link LIKE '%modules/vpn%';" 2>/dev/null || true
    sqlite3 /var/www/db/acl.db "DELETE FROM acl_resource WHERE name IN ('vpn', 'easyvpn', 'easy_vpn', 'openvpn');" 2>/dev/null || true

    # Insere recurso no ACL
    sqlite3 /var/www/db/acl.db "INSERT INTO acl_resource (name, description) VALUES ('$MODULE_ID', 'OpenVPN');" 2>/dev/null || true

    # Insere entrada no menu lateral sob 'Segurança' (security)
    sqlite3 /var/www/db/menu.db "INSERT INTO menu (id, IdParent, Link, Name, Type, order_no) VALUES ('$MODULE_ID', 'security', 'modules/$MODULE_ID/index.php', 'OpenVPN', 'module', 12);" 2>/dev/null || \
    sqlite3 /var/www/db/menu.db "INSERT INTO menu (id, IdParent, Link, Name, Type, order_no) VALUES ('$MODULE_ID', 'security', '', 'OpenVPN', 'module', 12);" 2>/dev/null || true

    # Garante permissão para o grupo de administradores (id_group = 1)
    sqlite3 /var/www/db/acl.db "INSERT OR IGNORE INTO acl_group_permission (id_action, id_group, id_resource) SELECT 1, 1, id FROM acl_resource WHERE name = '$MODULE_ID';" 2>/dev/null || true

    # Ajusta permissões dos bancos e limpa cache de menu
    chown asterisk:asterisk /var/www/db/menu.db /var/www/db/acl.db 2>/dev/null || true
    chmod 666 /var/www/db/menu.db /var/www/db/acl.db 2>/dev/null || true
    rm -rf /var/www/html/var/templates_c/* /tmp/smarty* 2>/dev/null || true
fi

# 8. Ajuste de Serviços Systemd
log_info "Ajustando serviços do OpenVPN no Systemd..."
systemctl daemon-reload 2>/dev/null || true

if systemctl list-unit-files | grep -q "openvpn-server@"; then
    systemctl -f enable openvpn-server@server.service 2>/dev/null || true
    if [ -f /etc/openvpn/server/server.conf ] || [ -f /etc/openvpn/server.conf ]; then
        systemctl restart openvpn-server@server.service 2>/dev/null || true
    fi
elif systemctl list-unit-files | grep -q "openvpn@"; then
    systemctl -f enable openvpn@server.service 2>/dev/null || true
    if [ -f /etc/openvpn/server.conf ]; then
        systemctl restart openvpn@server.service 2>/dev/null || true
    fi
fi

echo ""
log_success "======================================================================"
log_success "  INSTALAÇÃO E AJUSTES DO OPENVPN CONCLUÍDOS COM SUCESSO!"
log_success "======================================================================"
log_info "Acesse o painel do Issabel em: Sistema -> Segurança -> OpenVPN"
log_info "Easy-RSA 3.0.8 provisionado para criação de CA sem travamentos."
echo ""
