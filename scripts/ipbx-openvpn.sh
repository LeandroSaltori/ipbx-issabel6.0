#!/bin/bash
# ==============================================================================
# OPENVPN / EASYVPN PARA ISSABEL 5 (ROCKY LINUX 8)
# Instalação Limpa do Zero baseada 100% no Fórum Oficial Issabel:
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
log_info "=================================================================="
log_info "  RESET TOTAL E REINSTALAÇÃO LIMPA DO OPENVPN (ISSABEL 5)"
log_info "=================================================================="
echo ""

# ─────────────────────────────────────────────────────────────────────────────
# 1. REMOVE TUDO DO OPENVPN / CERTIFICADOS ANTERIORES (RESET TOTAL DO ZERO)
# ─────────────────────────────────────────────────────────────────────────────
log_warn "Parando todos os serviços OpenVPN..."
systemctl stop openvpn-server@server.service openvpn@server.service openvpn 2>/dev/null || true
systemctl disable openvpn-server@server.service openvpn@server.service 2>/dev/null || true
systemctl stop ipbx-openvpn-sanitize.service ipbx-openvpn-sanitize.path 2>/dev/null || true
systemctl disable ipbx-openvpn-sanitize.service ipbx-openvpn-sanitize.path 2>/dev/null || true

log_warn "Removendo certificados antigos, chaves de clientes, bancos e módulos..."
rm -rf /etc/openvpn
rm -rf /usr/share/easy-rsa/3.0.8
rm -rf /var/log/openvpn
rm -rf /var/www/html/modules/easy_vpn/clientkeys/* 2>/dev/null || true
rm -rf /var/www/html/modules/easyvpn/clientkeys/* 2>/dev/null || true
rm -f /var/www/db/*vpn*.db /var/www/db/*easy*.db 2>/dev/null || true
rm -rf /var/www/html/var/templates_c/* /tmp/smarty* 2>/dev/null || true
rm -f /etc/sudoers.d/*openvpn*
rm -f /etc/systemd/system/ipbx-openvpn*
rm -f /etc/cron.d/ipbx-openvpn
rm -f /usr/local/bin/ipbx-openvpn*
systemctl daemon-reload 2>/dev/null || true

log_warn "Desinstalando pacote issabel-easyvpn para reinstalar do zero..."
if command -v dnf &>/dev/null; then
    dnf remove -y issabel-easyvpn 2>/dev/null || true
else
    yum remove -y issabel-easyvpn 2>/dev/null || true
fi
rm -rf /var/www/html/modules/easy_vpn /var/www/html/modules/easyvpn 2>/dev/null || true
log_success "Ambiente OpenVPN anterior 100% limpo e zerado."

# ─────────────────────────────────────────────────────────────────────────────
# 2. PASSO 1 E 2 DO FÓRUM: EASY-RSA 3.0.8
# ─────────────────────────────────────────────────────────────────────────────
log_info "Passo 1 do Fórum: Criando diretório /usr/share/easy-rsa/3.0.8/..."
mkdir -p /usr/share/easy-rsa/3.0.8/

log_info "Passo 2 do Fórum: Baixando e extraindo EasyRSA-3.0.8.tgz..."
TMP="/tmp/easyrsa_setup_$$"
mkdir -p "$TMP"
cd "$TMP"

if ! wget -q https://github.com/OpenVPN/easy-rsa/releases/download/v3.0.8/EasyRSA-3.0.8.tgz; then
    curl -sSL -o EasyRSA-3.0.8.tgz https://github.com/OpenVPN/easy-rsa/releases/download/v3.0.8/EasyRSA-3.0.8.tgz
fi

tar -xvzf EasyRSA-3.0.8.tgz >/dev/null 2>&1
mv EasyRSA-3.0.8/* /usr/share/easy-rsa/3.0.8/
cd /root
rm -rf "$TMP"
chmod -R 755 /usr/share/easy-rsa/3.0.8/
log_success "Easy-RSA 3.0.8 instalado com sucesso em /usr/share/easy-rsa/3.0.8/"

# ─────────────────────────────────────────────────────────────────────────────
# 3. PASSO 3 DO FÓRUM: INSTALAR MÓDULO ISSABEL EASYVPN
# ─────────────────────────────────────────────────────────────────────────────
log_info "Passo 3 do Fórum: Instalando módulo issabel-easyvpn..."
if command -v dnf &>/dev/null; then
    dnf install -y issabel-easyvpn openvpn
else
    yum install -y issabel-easyvpn openvpn
fi
log_success "issabel-easyvpn instalado com sucesso."

# ─────────────────────────────────────────────────────────────────────────────
# 4. COMPATIBILIDADE ROCKY LINUX 8 (DIRETÓRIOS E PERMISSÃO)
# No Issabel 5 (Rocky 8), openvpn-server@server roda em /etc/openvpn/server/.
# A interface web grava em /etc/openvpn/.
# Criamos a estrutura para que ambos enxerguem os mesmos arquivos.
# ─────────────────────────────────────────────────────────────────────────────
log_info "Configurando compatibilidade de diretórios para Rocky Linux 8..."
mkdir -p /etc/openvpn/server /etc/openvpn/client /etc/openvpn/ccd /var/log/openvpn

# IP Forwarding
echo "net.ipv4.ip_forward = 1" > /etc/sysctl.d/99-openvpn.conf
sysctl -p /etc/sysctl.d/99-openvpn.conf 2>/dev/null || sysctl -w net.ipv4.ip_forward=1 2>/dev/null || true

# Atualiza o script privileged para controlar o serviço do Rocky Linux 8
cat << 'EOFPRIV' > /usr/share/issabel/privileged/openvpn
#!/bin/bash
ACTION="${1:-status}"
case "$ACTION" in
    start)
        # Sincroniza arquivos de /etc/openvpn para /etc/openvpn/server se existirem
        for f in /etc/openvpn/*; do
            [ -f "$f" ] && cp -f "$f" /etc/openvpn/server/ 2>/dev/null || true
        done
        # Garante crl.pem se ainda não existir
        if [ ! -f /etc/openvpn/server/crl.pem ] && [ -f /etc/openvpn/crl.pem ]; then
            cp -f /etc/openvpn/crl.pem /etc/openvpn/server/crl.pem 2>/dev/null || true
        elif [ ! -f /etc/openvpn/server/crl.pem ] && [ -f /usr/share/easy-rsa/3.0.8/pki/crl.pem ]; then
            cp -f /usr/share/easy-rsa/3.0.8/pki/crl.pem /etc/openvpn/server/crl.pem 2>/dev/null || true
        elif [ ! -f /etc/openvpn/server/crl.pem ] && [ -x /usr/share/easy-rsa/3.0.8/easyrsa ]; then
            (cd /usr/share/easy-rsa/3.0.8 && ./easyrsa gen-crl 2>/dev/null || true)
            [ -f /usr/share/easy-rsa/3.0.8/pki/crl.pem ] && cp -f /usr/share/easy-rsa/3.0.8/pki/crl.pem /etc/openvpn/server/crl.pem 2>/dev/null || true
        fi
        systemctl restart openvpn-server@server.service
        ;;
    stop)
        systemctl stop openvpn-server@server.service
        ;;
    status)
        if systemctl is-active openvpn-server@server.service &>/dev/null; then
            echo "OpenVPN is running"
            exit 0
        else
            echo "OpenVPN is stopped"
            exit 1
        fi
        ;;
    *)
        systemctl "$ACTION" openvpn-server@server.service 2>/dev/null || true
        ;;
esac
EOFPRIV
chmod 755 /usr/share/issabel/privileged/openvpn
mkdir -p /etc/init.d
ln -sfn /usr/share/issabel/privileged/openvpn /etc/init.d/openvpn

# Permissão sudo para o usuário web do Issabel (asterisk) controlar o serviço
cat << 'EOFSUDO' > /etc/sudoers.d/99-openvpn-asterisk
asterisk ALL=(ALL) NOPASSWD: /usr/share/issabel/privileged/openvpn, /usr/bin/systemctl * openvpn-server@server.service, /usr/bin/systemctl * openvpn-server@server
EOFSUDO
chmod 440 /etc/sudoers.d/99-openvpn-asterisk

# Habilitar o serviço no systemd (conforme orientado no fórum)
systemctl daemon-reload
systemctl -f enable openvpn-server@server.service 2>/dev/null || true

# Limpa cache do Smarty da interface web
rm -rf /var/www/html/var/templates_c/* /tmp/smarty* 2>/dev/null || true

echo ""
log_success "=================================================================="
log_success "  OPENVPN INSTALADO DO ZERO COM SUCESSO (BASE FÓRUM ISSABEL)!"
log_success "=================================================================="
echo ""
log_info "Próximos passos na interface web (conforme fórum):"
log_info "1. Acesse no navegador: Sistema → Segurança → OpenVPN"
log_info "2. Na aba 'OpenVPN Configuration', preencha suas configurações e salve."
log_info "3. Crie a Autoridade Certificadora (CA) e seus Certificados de Cliente."
log_info "4. Clique no botão verde 'Start OpenVPN Service' (ou 'systemctl start openvpn-server@server')."
echo ""
