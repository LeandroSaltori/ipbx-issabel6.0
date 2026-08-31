#!/bin/bash
# ==============================================================================
# CONFIGURADOR DO OPENVPN/EASYVPN - IPBX ISSABEL 6
# ==============================================================================
# Corrige exatamente os dois problemas do Issabel 6 (Rocky Linux 8):
#   1. Nome do serviço mudou: openvpn@server -> openvpn-server@server.service
#   2. Wizard escreve máscara inválida: 255.255.225.0 -> 255.255.255.0
#
# O módulo EasyVPN (issabel-easyvpn) já cria/revoga certificados corretamente,
# igual ao Issabel 4. Não alteramos nada nessa lógica.
#
# Autor: Leandro Saltori / Prisma Telecom
# ==============================================================================

set -e

RED='\033[0;31m'
GREEN='\033[0;32m'
CYAN='\033[0;36m'
NC='\033[0m'

log_info()    { echo -e "${CYAN}[INFO]${NC} $1"; }
log_success() { echo -e "${GREEN}[SUCESSO]${NC} $1"; }
log_error()   { echo -e "${RED}[ERRO]${NC} $1"; }

if [ "$EUID" -ne 0 ]; then
    log_error "Este script precisa ser executado como root!"
    exit 1
fi

log_info "Sistema detectado: $(. /etc/os-release && echo "$NAME $VERSION")"

# ─────────────────────────────────────────────────────────────────────────────
# 1. Instala pacotes necessários
# ─────────────────────────────────────────────────────────────────────────────
log_info "Instalando OpenVPN e EasyVPN..."
if command -v dnf &>/dev/null; then
    dnf install -y epel-release 2>/dev/null || true
    dnf install -y openvpn easy-rsa tar wget curl iptables-services 2>/dev/null || true
    dnf install -y issabel-easyvpn 2>/dev/null || true
elif command -v yum &>/dev/null; then
    yum install -y epel-release 2>/dev/null || true
    yum install -y openvpn easy-rsa tar wget curl iptables-services 2>/dev/null || true
    yum install -y issabel-easyvpn 2>/dev/null || true
fi

# Reinstala o módulo web SEMPRE para garantir arquivos íntegros
log_info "Garantindo módulo web do issabel-easyvpn..."
if command -v dnf &>/dev/null; then
    dnf reinstall -y issabel-easyvpn 2>/dev/null || dnf install -y issabel-easyvpn 2>/dev/null || true
else
    yum reinstall -y issabel-easyvpn 2>/dev/null || yum install -y issabel-easyvpn 2>/dev/null || true
fi

# Corrige permissões dos arquivos do módulo para o Apache/PHP conseguir ler
for MODDIR in /var/www/html/modules/easy_vpn /var/www/html/modules/easyvpn; do
    [ -d "$MODDIR" ] || continue
    chown -R asterisk:asterisk "$MODDIR" 2>/dev/null || true
    find "$MODDIR" -type d -exec chmod 755 {} \; 2>/dev/null || true
    find "$MODDIR" -type f -exec chmod 644 {} \; 2>/dev/null || true
    find "$MODDIR" -name "*.sh" -exec chmod 755 {} \; 2>/dev/null || true
    log_success "Permissões do módulo corrigidas em $MODDIR"
    break
done



# ─────────────────────────────────────────────────────────────────────────────
# 2. Easy-RSA 3.0.8 (versão que o módulo issabel-easyvpn espera)
# ─────────────────────────────────────────────────────────────────────────────
log_info "Configurando Easy-RSA 3.0.8..."
mkdir -p /usr/share/easy-rsa/3.0.8/ /usr/share/easy-rsa/3/ 2>/dev/null || true

if [ ! -f /usr/share/easy-rsa/3.0.8/easyrsa ]; then
    TMP=/tmp/easyrsa_setup
    rm -rf "$TMP" && mkdir -p "$TMP"
    curl -sSL "https://github.com/OpenVPN/easy-rsa/releases/download/v3.0.8/EasyRSA-3.0.8.tgz" \
        -o "$TMP/EasyRSA-3.0.8.tgz" 2>/dev/null || \
    wget -q "https://github.com/OpenVPN/easy-rsa/releases/download/v3.0.8/EasyRSA-3.0.8.tgz" \
        -O "$TMP/EasyRSA-3.0.8.tgz" 2>/dev/null || true
    if [ -f "$TMP/EasyRSA-3.0.8.tgz" ]; then
        tar -xzf "$TMP/EasyRSA-3.0.8.tgz" -C "$TMP" 2>/dev/null || true
        cp -rpf "$TMP/EasyRSA-3.0.8/"* /usr/share/easy-rsa/3.0.8/ 2>/dev/null || true
        cp -rpf "$TMP/EasyRSA-3.0.8/"* /usr/share/easy-rsa/3/ 2>/dev/null || true
    fi
    rm -rf "$TMP"
fi
chmod -R 755 /usr/share/easy-rsa 2>/dev/null || true
log_success "Easy-RSA 3.0.8 OK."

# ─────────────────────────────────────────────────────────────────────────────
# 3. IP Forwarding
# ─────────────────────────────────────────────────────────────────────────────
echo "net.ipv4.ip_forward = 1" > /etc/sysctl.d/99-openvpn.conf
sysctl -p /etc/sysctl.d/99-openvpn.conf 2>/dev/null || sysctl -w net.ipv4.ip_forward=1 2>/dev/null || true

# ─────────────────────────────────────────────────────────────────────────────
# 4. Estrutura de diretórios e permissões
# ─────────────────────────────────────────────────────────────────────────────
mkdir -p /etc/openvpn/server /etc/openvpn/client /etc/openvpn/ccd /var/log/openvpn 2>/dev/null || true

# sudo para o usuário asterisk (servidor web do Issabel) controlar o serviço
cat << 'EOF' > /etc/sudoers.d/99-openvpn-asterisk
asterisk ALL=(ALL) NOPASSWD: /usr/bin/systemctl, /bin/systemctl, /usr/sbin/openvpn, /usr/bin/openvpn, /etc/init.d/openvpn, /usr/share/issabel/privileged/openvpn, /usr/local/bin/ipbx-openvpn-helper.sh
EOF
chmod 440 /etc/sudoers.d/99-openvpn-asterisk 2>/dev/null || true

# ─────────────────────────────────────────────────────────────────────────────
# FIX 1: Bridge de serviço (init.d → openvpn-server@server.service)
# O módulo web chama /etc/init.d/openvpn e /usr/share/issabel/privileged/openvpn.
# No Rocky Linux 8 o serviço se chama openvpn-server@server.service.
# Este helper faz a tradução transparentemente.
# ─────────────────────────────────────────────────────────────────────────────
log_info "Instalando bridge de serviço (FIX 1)..."
cat << 'EOFHELP' > /usr/local/bin/ipbx-openvpn-helper.sh
#!/bin/bash
ACTION="${1:-status}"

# Sanitiza máscara inválida no server.conf antes de iniciar o serviço
for CFG in /etc/openvpn/server/server.conf /etc/openvpn/server.conf; do
    [ -f "$CFG" ] || continue
    sed -i 's/255\.255\.225\.0/255.255.255.0/g' "$CFG" 2>/dev/null || true
    sed -i -E 's/^(server [0-9.]+) 255\.255\.[0-9]+\.0/\1 255.255.255.0/g' "$CFG" 2>/dev/null || true
done

case "$ACTION" in
    start|restart|reload)
        systemctl daemon-reload 2>/dev/null || true
        systemctl restart openvpn-server@server.service 2>/dev/null || \
        systemctl restart openvpn@server.service 2>/dev/null || true
        ;;
    stop)
        systemctl stop openvpn-server@server.service 2>/dev/null || \
        systemctl stop openvpn@server.service 2>/dev/null || true
        ;;
    status)
        if systemctl is-active openvpn-server@server.service &>/dev/null || \
           systemctl is-active openvpn@server.service &>/dev/null || \
           pgrep -x openvpn &>/dev/null; then
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
EOFHELP
chmod +x /usr/local/bin/ipbx-openvpn-helper.sh

# Registra o helper como o ponto de entrada para todos os caminhos que o módulo web usa
mkdir -p /usr/share/issabel/privileged /etc/init.d 2>/dev/null || true
ln -sfn /usr/local/bin/ipbx-openvpn-helper.sh /etc/init.d/openvpn 2>/dev/null || true
ln -sfn /usr/local/bin/ipbx-openvpn-helper.sh /usr/share/issabel/privileged/openvpn 2>/dev/null || true
log_success "Bridge de serviço instalado."

# ─────────────────────────────────────────────────────────────────────────────
# FIX 2: Sanitizador de máscara via Systemd Path (reage instantaneamente)
# O wizard salva 255.255.225.0 no server.conf. O path unit detecta a mudança
# no arquivo e corrige antes que o OpenVPN tente iniciar com conf inválida.
# ─────────────────────────────────────────────────────────────────────────────
log_info "Instalando watchdog de máscara (FIX 2)..."

cat << 'EOFSVC' > /etc/systemd/system/ipbx-openvpn-sanitize.service
[Unit]
Description=IPBX - Corrige server.conf e inicia OpenVPN
After=network.target

[Service]
Type=oneshot
ExecStart=/usr/local/bin/ipbx-openvpn-helper.sh restart
RemainAfterExit=no
EOFSVC

cat << 'EOFPATH' > /etc/systemd/system/ipbx-openvpn-sanitize.path
[Unit]
Description=IPBX - Monitora alterações no server.conf do OpenVPN

[Path]
PathModified=/etc/openvpn/server/server.conf
PathModified=/etc/openvpn/server.conf
Unit=ipbx-openvpn-sanitize.service

[Install]
WantedBy=multi-user.target
EOFPATH

systemctl daemon-reload 2>/dev/null || true
systemctl enable ipbx-openvpn-sanitize.path 2>/dev/null || true
systemctl start  ipbx-openvpn-sanitize.path 2>/dev/null || true
log_success "Watchdog de máscara instalado."

# Cron de fallback a cada 5 minutos (garante CRL atualizada e serviço no ar)
cat << 'EOFCRON' > /etc/cron.d/ipbx-openvpn
*/5 * * * * root /usr/local/bin/ipbx-openvpn-helper.sh status >/dev/null 2>&1 || /usr/local/bin/ipbx-openvpn-helper.sh start >/dev/null 2>&1
EOFCRON
chmod 644 /etc/cron.d/ipbx-openvpn 2>/dev/null || true

# ─────────────────────────────────────────────────────────────────────────────
# 5. Permissões da CRL (crl.pem precisa ser legível pelo daemon openvpn)
# ─────────────────────────────────────────────────────────────────────────────
for EASYRSA_DIR in /usr/share/easy-rsa/3.0.8 /usr/share/easy-rsa/3 /etc/openvpn/easy-rsa; do
    [ -f "$EASYRSA_DIR/easyrsa" ] || continue
    (cd "$EASYRSA_DIR" && ./easyrsa gen-crl 2>/dev/null || true)
    if [ -f "$EASYRSA_DIR/pki/crl.pem" ]; then
        cp -f "$EASYRSA_DIR/pki/crl.pem" /etc/openvpn/server/crl.pem 2>/dev/null || true
        cp -f "$EASYRSA_DIR/pki/crl.pem" /etc/openvpn/crl.pem 2>/dev/null || true
        chmod 644 /etc/openvpn/server/crl.pem /etc/openvpn/crl.pem 2>/dev/null || true
    fi
    break
done

# ─────────────────────────────────────────────────────────────────────────────
# 6. Links simbólicos entre /etc/openvpn e /etc/openvpn/server
# ─────────────────────────────────────────────────────────────────────────────
if [ -f /etc/openvpn/server/server.conf ] && [ ! -e /etc/openvpn/server.conf ]; then
    ln -sfn /etc/openvpn/server/server.conf /etc/openvpn/server.conf 2>/dev/null || true
elif [ -f /etc/openvpn/server.conf ] && [ ! -f /etc/openvpn/server/server.conf ]; then
    ln -sfn /etc/openvpn/server.conf /etc/openvpn/server/server.conf 2>/dev/null || true
fi

for f in ca.crt server.crt server.key dh2048.pem dh.pem ipp.txt openvpn-status.log crl.pem; do
    if [ -f "/etc/openvpn/server/$f" ] && [ ! -e "/etc/openvpn/$f" ]; then
        ln -sfn "/etc/openvpn/server/$f" "/etc/openvpn/$f" 2>/dev/null || true
    elif [ -f "/etc/openvpn/$f" ] && [ ! -e "/etc/openvpn/server/$f" ]; then
        ln -sfn "/etc/openvpn/$f" "/etc/openvpn/server/$f" 2>/dev/null || true
    fi
done

# ─────────────────────────────────────────────────────────────────────────────
# 7. Habilita e inicia o serviço (se server.conf já existir)
# ─────────────────────────────────────────────────────────────────────────────
systemctl daemon-reload 2>/dev/null || true
if systemctl list-unit-files 2>/dev/null | grep -q "openvpn-server@"; then
    systemctl enable openvpn-server@server.service 2>/dev/null || true
    if [ -f /etc/openvpn/server/server.conf ] || [ -f /etc/openvpn/server.conf ]; then
        /usr/local/bin/ipbx-openvpn-helper.sh restart 2>/dev/null || true
    fi
elif systemctl list-unit-files 2>/dev/null | grep -q "openvpn@"; then
    systemctl enable openvpn@server.service 2>/dev/null || true
    if [ -f /etc/openvpn/server.conf ]; then
        /usr/local/bin/ipbx-openvpn-helper.sh restart 2>/dev/null || true
    fi
fi

# ─────────────────────────────────────────────────────────────────────────────
# 8. Link simbólico do módulo web + Menu do Issabel
# O módulo no disco se chama easy_vpn. O menu ID deve ser easy_vpn para que
# a URL ?menu=easy_vpn carregue o módulo corretamente.
# ─────────────────────────────────────────────────────────────────────────────

# O pacote issabel-easyvpn já registra o menu corretamente.
# Apenas garantimos permissão nos bancos e limpamos o cache Smarty.
if command -v sqlite3 &>/dev/null; then
    chown asterisk:asterisk /var/www/db/menu.db /var/www/db/acl.db 2>/dev/null || true
    chmod 666 /var/www/db/menu.db /var/www/db/acl.db 2>/dev/null || true
fi
rm -rf /var/www/html/var/templates_c/* /tmp/smarty* 2>/dev/null || true

echo ""
log_success "=================================================================="
log_success "  OPENVPN CONFIGURADO COM SUCESSO!"
log_success "  Acesse: Sistema → Segurança → OpenVPN"
log_success "=================================================================="
echo ""
