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

# Se a pasta do módulo não existir no disco (ex: após um rollback do html), força a reinstalação do pacote
if [ ! -d /var/www/html/modules/easy_vpn ] && [ ! -d /var/www/html/modules/easyvpn ]; then
    log_info "Restaurando arquivos do módulo web do issabel-easyvpn..."
    if command -v dnf &>/dev/null; then
        dnf reinstall -y issabel-easyvpn 2>/dev/null || dnf install -y issabel-easyvpn 2>/dev/null || true
    elif command -v yum &>/dev/null; then
        yum reinstall -y issabel-easyvpn 2>/dev/null || yum install -y issabel-easyvpn 2>/dev/null || true
    fi
fi

# Cria link simbólico de compatibilidade para ambos os nomes (easy_vpn e openvpn)
if [ -d /var/www/html/modules/easy_vpn ] && [ ! -d /var/www/html/modules/openvpn ]; then
    ln -s /var/www/html/modules/easy_vpn /var/www/html/modules/openvpn 2>/dev/null || true
elif [ -d /var/www/html/modules/easyvpn ] && [ ! -d /var/www/html/modules/openvpn ]; then
    ln -s /var/www/html/modules/easyvpn /var/www/html/modules/openvpn 2>/dev/null || true
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

# 5. Habilitação de Roteamento de Pacotes no Kernel
log_info "Garantindo IP Forwarding no kernel..."
mkdir -p /etc/sysctl.d 2>/dev/null || true
cat << 'EOF' > /etc/sysctl.d/99-openvpn.conf
net.ipv4.ip_forward = 1
EOF
sysctl -p /etc/sysctl.d/99-openvpn.conf 2>/dev/null || sysctl -w net.ipv4.ip_forward=1 2>/dev/null || true

# 6. Estrutura de Diretórios, Permissões e Sudoers para o Módulo Web
mkdir -p /etc/openvpn/server /etc/openvpn/client /etc/openvpn/ccd /var/log/openvpn 2>/dev/null || true

# Permissão sudo sem senha ampla para o usuário asterisk conseguir iniciar/reiniciar o OpenVPN pelos botões do front-end
cat << 'EOF' > /etc/sudoers.d/99-openvpn-asterisk
asterisk ALL=(ALL) NOPASSWD: /usr/bin/systemctl, /bin/systemctl, /usr/sbin/openvpn, /usr/bin/openvpn, /etc/init.d/openvpn, /usr/share/issabel/privileged/openvpn, /usr/local/bin/ipbx-openvpn-helper.sh
EOF
chmod 440 /etc/sudoers.d/99-openvpn-asterisk 2>/dev/null || true

# Script Helper de Compatibilidade do Serviço OpenVPN para o Front-End
cat << 'EOFHELP' > /usr/local/bin/ipbx-openvpn-helper.sh
#!/bin/bash
ACTION="$1"
[ -z "$ACTION" ] && ACTION="status"

# Sanitiza máscara no server.conf antes de iniciar
for CFG in /etc/openvpn/server/server.conf /etc/openvpn/server.conf; do
    if [ -f "$CFG" ]; then
        sed -i 's/255\.255\.225\.0/255.255.255.0/g' "$CFG" 2>/dev/null || true
        sed -i -E 's/^server ([0-9]+\.[0-9]+\.[0-9]+\.[0-9]+) 255\.255\.[0-9]+\.0/server \1 255.255.255.0/g' "$CFG" 2>/dev/null || true
    fi
done

case "$ACTION" in
    start|restart|reload)
        systemctl daemon-reload 2>/dev/null || true
        systemctl restart openvpn-server@server.service 2>/dev/null || systemctl restart openvpn@server.service 2>/dev/null || true
        exit 0
        ;;
    stop)
        systemctl stop openvpn-server@server.service 2>/dev/null || systemctl stop openvpn@server.service 2>/dev/null || true
        killall -9 openvpn 2>/dev/null || true
        exit 0
        ;;
    status)
        if systemctl is-active openvpn-server@server.service &>/dev/null || systemctl is-active openvpn@server.service &>/dev/null || pgrep -x openvpn &>/dev/null; then
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
chmod +x /usr/local/bin/ipbx-openvpn-helper.sh 2>/dev/null || true

# Cria ponte em /etc/init.d/openvpn e /usr/share/issabel/privileged/openvpn
mkdir -p /usr/share/issabel/privileged /etc/init.d 2>/dev/null || true
ln -sfn /usr/local/bin/ipbx-openvpn-helper.sh /etc/init.d/openvpn 2>/dev/null || true
ln -sfn /usr/local/bin/ipbx-openvpn-helper.sh /usr/share/issabel/privileged/openvpn 2>/dev/null || true

# Links simbólicos e espelhamento entre caminhos para que qualquer alteração no front funcione em ambos
if [ -f /etc/openvpn/server/server.conf ] && [ ! -L /etc/openvpn/server.conf ]; then
    ln -sfn /etc/openvpn/server/server.conf /etc/openvpn/server.conf 2>/dev/null || true
elif [ -f /etc/openvpn/server.conf ] && [ ! -f /etc/openvpn/server/server.conf ]; then
    ln -sfn /etc/openvpn/server.conf /etc/openvpn/server/server.conf 2>/dev/null || true
fi

# Garante geração e permissões da Lista de Revogação de Certificados (crl.pem)
for EASYRSA_DIR in /etc/openvpn/easy-rsa /etc/openvpn/server/easy-rsa /usr/share/easy-rsa/3.0.8 /usr/share/easy-rsa/3; do
    if [ -d "$EASYRSA_DIR" ] && [ -f "$EASYRSA_DIR/easyrsa" ]; then
        (cd "$EASYRSA_DIR" && ./easyrsa gen-crl 2>/dev/null || true)
        if [ -f "$EASYRSA_DIR/pki/crl.pem" ]; then
            /bin/cp -f "$EASYRSA_DIR/pki/crl.pem" /etc/openvpn/server/crl.pem 2>/dev/null || true
            /bin/cp -f "$EASYRSA_DIR/pki/crl.pem" /etc/openvpn/crl.pem 2>/dev/null || true
            chmod 644 /etc/openvpn/server/crl.pem /etc/openvpn/crl.pem 2>/dev/null || true
        fi
        break
    fi
done

# Sanitização e Correção Automática da Linha 'server' no server.conf
for CFG in /etc/openvpn/server/server.conf /etc/openvpn/server.conf; do
    if [ -f "$CFG" ]; then
        # Corrige máscaras com erro de digitação (ex: 225.0 -> 255.0)
        sed -i 's/255\.255\.225\.0/255.255.255.0/g' "$CFG" 2>/dev/null || true
        # Garante que a linha server tenha formato válido
        sed -i -E 's/^server ([0-9]+\.[0-9]+\.[0-9]+\.[0-9]+) 255\.255\.[0-9]+\.0/server \1 255.255.255.0/g' "$CFG" 2>/dev/null || true
    fi
done

# Garante a diretiva crl-verify no server.conf com caminho absoluto se o crl.pem existir
if [ -f /etc/openvpn/server/crl.pem ] || [ -f /etc/openvpn/crl.pem ]; then
    for CFG in /etc/openvpn/server/server.conf /etc/openvpn/server.conf; do
        if [ -f "$CFG" ]; then
            sed -i '/crl-verify/d' "$CFG" 2>/dev/null || true
            echo "crl-verify /etc/openvpn/server/crl.pem" >> "$CFG"
        fi
    done
fi

for f in ca.crt server.crt server.key dh2048.pem dh.pem ipp.txt openvpn-status.log crl.pem; do
    if [ -f "/etc/openvpn/server/$f" ] && [ ! -f "/etc/openvpn/$f" ]; then
        ln -sfn "/etc/openvpn/server/$f" "/etc/openvpn/$f" 2>/dev/null || true
    elif [ -f "/etc/openvpn/$f" ] && [ ! -f "/etc/openvpn/server/$f" ]; then
        ln -sfn "/etc/openvpn/$f" "/etc/openvpn/server/$f" 2>/dev/null || true
    fi
done

# 7. Sincronizador Automático de Exclusão e Revogação de Certificados
log_info "Configurando sincronizador de exclusão de certificados..."
cat << 'EOFSYNC' > /usr/local/bin/ipbx-openvpn-sync.sh
#!/bin/bash
CLIENTKEYS_DIR="/etc/openvpn/clientkeys"
[ ! -d "$CLIENTKEYS_DIR" ] && CLIENTKEYS_DIR="/var/www/html/modules/easy_vpn/clientkeys"

EASYRSA_DIR="/usr/share/easy-rsa/3.0.8"
[ ! -d "$EASYRSA_DIR" ] && EASYRSA_DIR="/usr/share/easy-rsa/3"
[ ! -d "$EASYRSA_DIR" ] && EASYRSA_DIR="/etc/openvpn/easy-rsa"

ALLCLIENTS_FILE="/etc/openvpn/allclients.txt"
[ ! -f "$ALLCLIENTS_FILE" ] && ALLCLIENTS_FILE="/etc/openvpn/server/allclients.txt"

REVOKED_FILE="/etc/openvpn/revokedclients.txt"
[ ! -f "$REVOKED_FILE" ] && REVOKED_FILE="/etc/openvpn/server/revokedclients.txt"

IPP_FILE="/etc/openvpn/server/ipp.txt"
[ ! -f "$IPP_FILE" ] && IPP_FILE="/etc/openvpn/ipp.txt"

CRL_DEST="/etc/openvpn/server/crl.pem"
CHANGES_MADE=0

if [ -d "$CLIENTKEYS_DIR" ]; then
    if [ -f "$ALLCLIENTS_FILE" ]; then
        TEMP_ALL=$(mktemp)
        > "$TEMP_ALL"
        
        while IFS= read -r CLIENT_NAME || [ -n "$CLIENT_NAME" ]; do
            CLIENT_NAME=$(echo "$CLIENT_NAME" | tr -d '\r' | tr -d '\n' | xargs)
            [ -z "$CLIENT_NAME" ] && continue
            [ "$CLIENT_NAME" = "server" ] && continue
            
            MATCH_FOUND=0
            for EXT in "zip" "ovpn" "tar.gz"; do
                if [ -f "$CLIENTKEYS_DIR/${CLIENT_NAME}.${EXT}" ] || [ -f "$CLIENTKEYS_DIR/${CLIENT_NAME}-client.${EXT}" ]; then
                    MATCH_FOUND=1
                    break
                fi
            done
            
            if [ "$MATCH_FOUND" -eq 0 ]; then
                echo "[SEGURANÇA] Cliente '$CLIENT_NAME' apagado do gerenciador web. Revogando..."
                if [ -d "$EASYRSA_DIR" ] && [ -f "$EASYRSA_DIR/easyrsa" ]; then
                    (cd "$EASYRSA_DIR" && EASYRSA_BATCH=1 ./easyrsa revoke "$CLIENT_NAME" 2>/dev/null || true)
                fi
                if [ -f "$REVOKED_FILE" ]; then
                    if ! grep -q "^${CLIENT_NAME}$" "$REVOKED_FILE"; then
                        echo "$CLIENT_NAME" >> "$REVOKED_FILE"
                    fi
                fi
                if [ -f "$IPP_FILE" ]; then
                    sed -i "/^${CLIENT_NAME},/d" "$IPP_FILE" 2>/dev/null || true
                fi
                CHANGES_MADE=1
            else
                echo "$CLIENT_NAME" >> "$TEMP_ALL"
            fi
        done < "$ALLCLIENTS_FILE"
        
        /bin/cp -f "$TEMP_ALL" "$ALLCLIENTS_FILE" 2>/dev/null || true
        [ -f /etc/openvpn/server/allclients.txt ] && /bin/cp -f "$TEMP_ALL" /etc/openvpn/server/allclients.txt 2>/dev/null || true
        rm -f "$TEMP_ALL"
    fi
fi

if [ "$CHANGES_MADE" -eq 1 ] || [ ! -f "$CRL_DEST" ]; then
    if [ -d "$EASYRSA_DIR" ] && [ -f "$EASYRSA_DIR/easyrsa" ]; then
        (cd "$EASYRSA_DIR" && ./easyrsa gen-crl 2>/dev/null || true)
        if [ -f "$EASYRSA_DIR/pki/crl.pem" ]; then
            /bin/cp -f "$EASYRSA_DIR/pki/crl.pem" /etc/openvpn/server/crl.pem 2>/dev/null || true
            /bin/cp -f "$EASYRSA_DIR/pki/crl.pem" /etc/openvpn/crl.pem 2>/dev/null || true
            chmod 644 /etc/openvpn/server/crl.pem /etc/openvpn/crl.pem 2>/dev/null || true
            chown asterisk:asterisk /etc/openvpn/server/crl.pem /etc/openvpn/crl.pem 2>/dev/null || true
        fi
    fi
    systemctl restart openvpn-server@server.service 2>/dev/null || systemctl restart openvpn@server.service 2>/dev/null || true
fi
EOFSYNC

chmod +x /usr/local/bin/ipbx-openvpn-sync.sh 2>/dev/null || true
bash /usr/local/bin/ipbx-openvpn-sync.sh 2>/dev/null || true

# Configura cronjob para sincronizar exclusões a cada minuto
cat << 'EOFCRON' > /etc/cron.d/ipbx-openvpn-sync
* * * * * root /usr/local/bin/ipbx-openvpn-sync.sh >/dev/null 2>&1
EOFCRON
chmod 644 /etc/cron.d/ipbx-openvpn-sync 2>/dev/null || true

# 8. Registro e Saneamento do Módulo Web no Menu do Issabel
if command -v sqlite3 &>/dev/null && [ -f /var/www/db/menu.db ]; then
    log_info "Registrando e organizando menu do OpenVPN (ovpn2) no Issabel..."

    # Remove entradas antigas e conflitantes
    sqlite3 /var/www/db/menu.db "DELETE FROM menu WHERE id IN ('vpn', 'easyvpn', 'easy_vpn', 'openvpn') OR Link LIKE '%vpn%';" 2>/dev/null || true
    sqlite3 /var/www/db/acl.db "DELETE FROM acl_resource WHERE name IN ('vpn', 'easyvpn', 'easy_vpn', 'openvpn');" 2>/dev/null || true

    # Insere recurso no ACL
    sqlite3 /var/www/db/acl.db "INSERT INTO acl_resource (name, description) VALUES ('openvpn', 'OpenVPN');" 2>/dev/null || true

    # O EasyVPN (ovpn2) é uma aplicação web completa integrada via Framed no Issabel
    sqlite3 /var/www/db/menu.db "INSERT INTO menu (id, IdParent, Link, Name, Type, order_no) VALUES ('openvpn', 'security', 'ovpn2/index.php', 'OpenVPN', 'framed', 12);" 2>/dev/null || true

    # Garante permissão para o grupo de administradores (id_group = 1)
    sqlite3 /var/www/db/acl.db "INSERT OR IGNORE INTO acl_group_permission (id_action, id_group, id_resource) SELECT 1, 1, id FROM acl_resource WHERE name = 'openvpn';" 2>/dev/null || true

    # Ajusta permissões dos arquivos do ovpn2 e dos bancos
    if [ -d "/var/www/html/ovpn2" ]; then
        chown -R asterisk:asterisk /var/www/html/ovpn2 2>/dev/null || true
        chmod -R 755 /var/www/html/ovpn2 2>/dev/null || true
    fi
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
