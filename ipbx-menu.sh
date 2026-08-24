#!/bin/bash
# ==============================================================================
# MENU INTERATIVO DE ATUALIZAÇÃO MODULAR - IPBX ISSABEL (PRISMA TELECOM)
# ==============================================================================
# Este script permite atualizar módulos INDIVIDUAIS do IPBX, um a um,
# sem precisar executar o install.sh completo.
#
# Uso:
#   ipbx-update                → Menu interativo
#   bash ipbx-menu.sh          → Menu interativo
#   curl -sSL <url> | bash     → Menu interativo via curl
#
# IMPORTANTE: Execute como root.
# ==============================================================================

# Desativa aliases do root (como cp -i, mv -i, rm -i) para rodar sem perguntas
unalias cp 2>/dev/null || true
unalias mv 2>/dev/null || true
unalias rm 2>/dev/null || true
shopt -s expand_aliases 2>/dev/null || true

# --- CORES PARA LOGS ---
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
MAGENTA='\033[0;35m'
WHITE='\033[1;37m'
NC='\033[0m' # No Color

log_info()    { echo -e "${CYAN}[INFO]${NC} $1"; }
log_success() { echo -e "${GREEN}[SUCESSO]${NC} $1"; }
log_warn()    { echo -e "${YELLOW}[AVISO]${NC} $1"; }
log_error()   { echo -e "${RED}[ERRO]${NC} $1"; }

# --- VERIFICAÇÃO DE PERMISSÃO ROOT ---
if [ "$EUID" -ne 0 ]; then
    log_error "Este script precisa ser executado como root!"
    exit 1
fi

# --- DETECÇÃO DE INPUT (curl | bash vs terminal) ---
if [ -t 0 ]; then
    INPUT_FD=0
elif [ -e /dev/tty ]; then
    INPUT_FD="/dev/tty"
else
    log_error "Nenhum terminal interativo disponível para o menu."
    exit 1
fi

menu_read() {
    if [ "$INPUT_FD" = "0" ]; then
        read "$@"
    else
        read "$@" < /dev/tty
    fi
}

# --- DIRETÓRIO DO REPOSITÓRIO ---
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" 2>/dev/null && pwd || echo "")"
REPO_DIR="$SCRIPT_DIR"
TMP_REPO="/tmp/ipbx-issabel-repo"

# Se o repositório local for um clone git, força a atualização
if [ -d "$REPO_DIR/.git" ]; then
    log_info "Sincronizando repositório local com o GitHub..."
    (cd "$REPO_DIR" && git fetch origin 2>/dev/null && (git pull origin main 2>/dev/null || git pull origin master 2>/dev/null)) || true
fi

# Se o script estiver fora do repositório, baixa o código mais recente
if [ ! -f "$REPO_DIR/install.sh" ] || [ ! -d "$REPO_DIR/src" ]; then
    log_info "Baixando última versão do repositório em $TMP_REPO..."
    rm -rf "$TMP_REPO"
    mkdir -p "$TMP_REPO"

    if ! command -v git &>/dev/null; then
        yum install -y git 2>/dev/null || dnf install -y git 2>/dev/null || true
    fi

    if command -v git &>/dev/null; then
        git clone --depth 1 https://github.com/LeandroSaltori/ipbx-issabel6.0.git "$TMP_REPO" 2>/dev/null || true
    fi

    if [ ! -d "$TMP_REPO/src" ]; then
        curl -sSL "https://github.com/LeandroSaltori/ipbx-issabel6.0/archive/refs/heads/main.tar.gz" | tar -xz -C "$TMP_REPO" --strip-components=1 2>/dev/null || true
    fi

    REPO_DIR="$TMP_REPO"
fi

# --- SISTEMA DE SNAPSHOT VERSIONADO POR DATA/HORA ---
CURRENT_BACKUP_DIR=""

create_snapshot() {
    local module_name="$1"
    local timestamp=$(date '+%Y-%m-%d_%H%M%S')
    CURRENT_BACKUP_DIR="/var/backup/ipbx/backup_${timestamp}"

    mkdir -p "$CURRENT_BACKUP_DIR/html" "$CURRENT_BACKUP_DIR/db" "$CURRENT_BACKUP_DIR/asterisk" 2>/dev/null || true

    echo "$module_name - $(date '+%d/%m/%Y às %H:%M:%S')" > "$CURRENT_BACKUP_DIR/manifesto.txt"

    # Snapshot dos bancos SQLite de menu e permissões
    [ -f /var/www/db/menu.db ] && cp -pf /var/www/db/menu.db "$CURRENT_BACKUP_DIR/db/" 2>/dev/null || true
    [ -f /var/www/db/acl.db ] && cp -pf /var/www/db/acl.db "$CURRENT_BACKUP_DIR/db/" 2>/dev/null || true

    # Snapshot das configurações do Asterisk
    for f in extensions_custom.conf extensions_override_issabelpbx.conf features_general_custom.conf pjsip.conf pjsip_custom.conf; do
        [ -f "/etc/asterisk/$f" ] && cp -pf "/etc/asterisk/$f" "$CURRENT_BACKUP_DIR/asterisk/" 2>/dev/null || true
    done

    # Snapshot do Crontab
    crontab -l > "$CURRENT_BACKUP_DIR/crontab.txt" 2>/dev/null || true

    # Link simbólico para o snapshot mais recente
    ln -sfn "$CURRENT_BACKUP_DIR" /var/backup/ipbx/latest 2>/dev/null || true

    log_info "Ponto de restauração gravado em: $CURRENT_BACKUP_DIR"
}

# --- FUNÇÃO DE BACKUP COM PRESERVAÇÃO DE DADOS ---
backup_and_deploy() {
    local src="$1"
    local dest="$2"

    # Salva cópia exata no snapshot versionado com data
    if [ -e "$dest" ] && [ -n "$CURRENT_BACKUP_DIR" ]; then
        local rel_path="${dest#/var/www/html/}"
        if [ "$rel_path" != "$dest" ]; then
            mkdir -p "$CURRENT_BACKUP_DIR/html/$(dirname "$rel_path")" 2>/dev/null || true
            cp -rpf "$dest" "$CURRENT_BACKUP_DIR/html/$rel_path" 2>/dev/null || true
        fi
    fi

    # Mantém também backup _old legado
    local backup="${dest}_old"
    if [ -e "$dest" ]; then
        if [ ! -e "$backup" ]; then
            cp -rpf "$dest" "$backup" 2>/dev/null || true
        fi
    fi

    log_info "Implantando: $src -> $dest"
    /bin/cp -rf "$src" "$dest"
}

# --- RECARGA DE SERVIÇOS APÓS ATUALIZAÇÃO ---
reload_services() {
    log_info "Recarregando serviços..."
    mkdir -p /var/log/asterisk/cdr-csv /var/log/asterisk/cdr-custom 2>/dev/null || true
    touch /var/log/asterisk/cdr-csv/Master.csv 2>/dev/null || true
    chown -R asterisk:asterisk /var/log/asterisk/cdr-csv /var/log/asterisk/cdr-custom 2>/dev/null || true
    chmod 755 /var/log/asterisk/cdr-csv /var/log/asterisk/cdr-custom 2>/dev/null || true
    chmod 664 /var/log/asterisk/cdr-csv/Master.csv 2>/dev/null || true

    rm -rf /var/www/html/var/templates_c/* 2>/dev/null || true
    rm -rf /tmp/smarty* 2>/dev/null || true
    asterisk -rx "module reload" 2>/dev/null || asterisk -rx "core reload" 2>/dev/null || true
    if systemctl is-active httpd &>/dev/null; then
        systemctl restart httpd 2>/dev/null || true
    elif systemctl is-active apache2 &>/dev/null; then
        systemctl restart apache2 2>/dev/null || true
    fi
    log_success "Serviços recarregados."
}

# --- DETECÇÃO DE SENHA MYSQL ---
get_mysql_pwd() {
    local pwd=""
    if [ -f /etc/issabel.conf ]; then
        pwd=$(grep -i mysqlrootpwd /etc/issabel.conf 2>/dev/null | cut -d'=' -f2 | tr -d ' ')
    fi
    echo "$pwd"
}

# ==============================================================================
# FUNÇÕES DE ATUALIZAÇÃO INDIVIDUAL (1 por módulo)
# ==============================================================================

# --- 1. TERMINAL (MOTD) ---
update_motd() {
    log_info "Atualizando tela personalizada do terminal (MOTD)..."
    if [ -f "$REPO_DIR/scripts/motd.sh" ]; then
        MOTD_DEST="/usr/local/sbin/motd.sh"
        backup_and_deploy "$REPO_DIR/scripts/motd.sh" "$MOTD_DEST"
        chmod +x "$MOTD_DEST"
        log_success "Tela do terminal (MOTD) configurada."
    else
        log_error "Arquivo scripts/motd.sh não encontrado no repositório."
    fi
}

# --- 2. TEMA E FAVICON ---
update_tema() {
    log_info "Instalando Favicon e Tema Prisma v5..."
    if [ -f "$REPO_DIR/src/favicon.ico" ]; then
        cp -f "$REPO_DIR/src/favicon.ico" /var/www/html/favicon.ico
        mkdir -p /var/www/html/themes/tenant/images /var/www/html/themes/prisma_v5/images 2>/dev/null || true
        cp -f "$REPO_DIR/src/favicon.ico" /var/www/html/themes/tenant/images/favicon.ico 2>/dev/null || true
        cp -f "$REPO_DIR/src/favicon.ico" /var/www/html/themes/prisma_v5/images/favicon.ico 2>/dev/null || true
    fi

    if [ -d "$REPO_DIR/src/themes/prisma_v5" ]; then
        /bin/cp -rf "$REPO_DIR/src/themes/prisma_v5" /var/www/html/themes/
        chown -R asterisk:asterisk /var/www/html/themes
        log_success "Tema Prisma v5 e Favicon aplicados."
    else
        log_error "Pasta src/themes/prisma_v5 não encontrada no repositório."
    fi
}

# --- 3. PAINEL ADMIN ---
update_admin() {
    log_info "Atualizando pasta /var/www/html/admin..."
    if [ -d "$REPO_DIR/src/admin" ]; then
        backup_and_deploy "$REPO_DIR/src/admin" "/var/www/html/admin"
        chown -R asterisk:asterisk /var/www/html/admin

        # Sincroniza logos transparentes do Asternic CDR e Admin
        if [ -f "$REPO_DIR/src/admin/modules/asternic_cdr/images/asternic_cdr_logo.jpg" ]; then
            mkdir -p /var/www/html/admin/modules/asternic_cdr/images /var/www/html/modules/asternic_cdr/images /var/www/html/admin/images 2>/dev/null || true
            cp -f "$REPO_DIR/src/admin/modules/asternic_cdr/images/asternic_cdr_logo.jpg" /var/www/html/admin/modules/asternic_cdr/images/asternic_cdr_logo.jpg 2>/dev/null || true
            cp -f "$REPO_DIR/src/admin/modules/asternic_cdr/images/asternic_cdr_logo.jpg" /var/www/html/modules/asternic_cdr/images/asternic_cdr_logo.jpg 2>/dev/null || true
            cp -f "$REPO_DIR/src/admin/images/issabel_logo.png" /var/www/html/admin/images/issabel_logo.png 2>/dev/null || true
            cp -f "$REPO_DIR/src/admin/images/issabelpbx_small.png" /var/www/html/admin/images/issabelpbx_small.png 2>/dev/null || true
        fi
        log_success "Pasta admin e logos atualizadas."
    else
        log_error "Pasta src/admin não encontrada no repositório."
    fi
}

# --- 4. TRADUÇÕES (LANG) ---
update_lang() {
    log_info "Atualizando pastas de tradução (lang)..."
    if [ -d "$REPO_DIR/src/lang" ]; then
        backup_and_deploy "$REPO_DIR/src/lang" "/var/www/html/lang"
        chown -R asterisk:asterisk /var/www/html/lang
        log_success "Traduções atualizadas."
    else
        log_error "Pasta src/lang não encontrada no repositório."
    fi
}

# --- 5. MÓDULOS WEB ---
update_modules() {
    log_info "Atualizando módulos web em /var/www/html/modules..."
    if [ -d "$REPO_DIR/src/modules" ]; then
        if [ -d "/var/www/html/modules" ] && [ ! -d "/var/www/html/modules_old" ]; then
            log_info "Backup criado: /var/www/html/modules -> /var/www/html/modules_old"
            /bin/cp -rf /var/www/html/modules /var/www/html/modules_old
        fi
        /bin/cp -rf "$REPO_DIR/src/modules/"* /var/www/html/modules/
        if [ -d "$REPO_DIR/src/modules/asternic_cdr" ]; then
            mkdir -p /var/www/html/admin/modules/asternic_cdr
            /bin/cp -rf "$REPO_DIR/src/modules/asternic_cdr/"* /var/www/html/admin/modules/asternic_cdr/ 2>/dev/null || true
            chown -R asterisk:asterisk /var/www/html/admin/modules/asternic_cdr
        fi
        chown -R asterisk:asterisk /var/www/html/modules
        log_success "Módulos sincronizados."
    else
        log_error "Pasta src/modules não encontrada no repositório."
    fi
}

# --- 6. AGENDA TELEFÔNICA ---
update_agenda() {
    log_info "Instalando Agenda.php..."
    local agenda_src=""
    if [ -f "$REPO_DIR/src/Agenda.php" ]; then
        agenda_src="$REPO_DIR/src/Agenda.php"
    elif [ -f "$REPO_DIR/src/agenda.php" ]; then
        agenda_src="$REPO_DIR/src/agenda.php"
    fi

    if [ -n "$agenda_src" ]; then
        cp -f "$agenda_src" /var/www/html/Agenda.php
        cp -f "$agenda_src" /var/www/html/agenda.php
        chown asterisk:asterisk /var/www/html/Agenda.php /var/www/html/agenda.php
        chmod 644 /var/www/html/Agenda.php /var/www/html/agenda.php
        log_success "Agenda.php instalada."
    else
        log_error "Arquivo Agenda.php não encontrado no repositório."
    fi
}

# --- 7. WEBPHONE WEBRTC ---
update_webphone() {
    log_info "Instalando Webphone WebRTC..."
    if [ -d "$REPO_DIR/src/webphone" ]; then
        mkdir -p /var/www/html/webphone
        /bin/cp -rf "$REPO_DIR/src/webphone/"* /var/www/html/webphone/
        chown -R asterisk:asterisk /var/www/html/webphone
        chmod -R 755 /var/www/html/webphone
        log_success "Webphone instalado em /var/www/html/webphone."
    else
        log_error "Pasta src/webphone não encontrada no repositório."
    fi
}

# --- 8. CLICK-TO-DIAL ---
update_clicktodial() {
    log_info "Instalando Click-to-Dial (call.php)..."
    if [ -f "$REPO_DIR/src/extensions/chrome-click-to-dial/call.php" ]; then
        cp -f "$REPO_DIR/src/extensions/chrome-click-to-dial/call.php" /var/www/html/call.php
        chown asterisk:asterisk /var/www/html/call.php
        chmod 644 /var/www/html/call.php
        log_success "call.php instalado em /var/www/html/call.php."
    else
        log_error "Arquivo call.php não encontrado no repositório."
    fi
}

# --- 9. PAINEL IPBX (control_panel) ---
update_painel() {
    log_info "Instalando Painel IPbx (control_panel)..."
    PANEL_SRC="$REPO_DIR/src/modules/control_panel"
    if [ -d "$PANEL_SRC" ]; then
        /bin/cp -rf "$PANEL_SRC" /var/www/html/modules/control_panel
        chown -R asterisk:asterisk /var/www/html/modules/control_panel

        if command -v sqlite3 &>/dev/null; then
            sqlite3 /var/www/db/acl.db "INSERT OR IGNORE INTO acl_resource (name, description) VALUES ('control_panel', 'Painel IPbx');" 2>/dev/null || true
            sqlite3 /var/www/db/menu.db "INSERT OR IGNORE INTO menu (id, IdParent, Link, Name, Type, order_no) VALUES ('control_panel', 'pbxconfig', '', 'Painel IPbx', 'module', 8);" 2>/dev/null || true
            sqlite3 /var/www/db/acl.db "INSERT OR IGNORE INTO acl_group_permission (id_action, id_group, id_resource) SELECT 1, 1, id FROM acl_resource WHERE name = 'control_panel';" 2>/dev/null || true
        fi
        log_success "Painel IPbx instalado e registrado."
    else
        log_error "Pasta src/modules/control_panel não encontrada no repositório."
    fi
}

# --- 10. NOME DOS RAMAIS ---
update_nome_ramais() {
    log_info "Instalando Gerenciador de Nome dos Ramais..."
    mkdir -p /var/www/html/nome_ramais /var/www/html/ramais /var/www/html/modules/nome_ramais /var/www/html/modules/ramais

    if [ -d "$REPO_DIR/src/nome_ramais" ]; then
        /bin/cp -rf "$REPO_DIR/src/nome_ramais/"* /var/www/html/nome_ramais/
        /bin/cp -rf "$REPO_DIR/src/nome_ramais/"* /var/www/html/modules/nome_ramais/ 2>/dev/null || true
    elif [ -d "$REPO_DIR/src/modules/nome_ramais" ]; then
        /bin/cp -rf "$REPO_DIR/src/modules/nome_ramais/"* /var/www/html/nome_ramais/
        /bin/cp -rf "$REPO_DIR/src/modules/nome_ramais/"* /var/www/html/modules/nome_ramais/ 2>/dev/null || true
    fi

    if [ -d "$REPO_DIR/src/ramais" ]; then
        /bin/cp -rf "$REPO_DIR/src/ramais/"* /var/www/html/ramais/
        /bin/cp -rf "$REPO_DIR/src/ramais/"* /var/www/html/modules/ramais/ 2>/dev/null || true
    fi

    # Sudoers para o asterisk executar comandos de reload
    echo "asterisk ALL=(ALL) NOPASSWD: ALL" > /etc/sudoers.d/asterisk 2>/dev/null || true
    chmod 440 /etc/sudoers.d/asterisk 2>/dev/null || true

    chown -R asterisk:asterisk /var/www/html/ramais /var/www/html/nome_ramais /var/www/html/modules/nome_ramais /var/www/html/modules/ramais 2>/dev/null || true
    chmod -R 755 /var/www/html/ramais /var/www/html/nome_ramais /var/www/html/modules/nome_ramais /var/www/html/modules/ramais 2>/dev/null || true

    if command -v sqlite3 &>/dev/null; then
        sqlite3 /var/www/db/menu.db "DELETE FROM menu WHERE id = 'nome_ramais';" 2>/dev/null || true
        sqlite3 /var/www/db/menu.db "INSERT INTO menu (id, IdParent, Link, Name, Type, order_no) VALUES ('nome_ramais', 'pbxconfig', 'nome_ramais/', 'Nome Ramais', 'framed', 15);" 2>/dev/null || true
        sqlite3 /var/www/db/acl.db "DELETE FROM acl_resource WHERE name = 'nome_ramais';" 2>/dev/null || true
        sqlite3 /var/www/db/acl.db "INSERT INTO acl_resource (name, description) VALUES ('nome_ramais', 'Nome Ramais');" 2>/dev/null || true
        sqlite3 /var/www/db/acl.db "DELETE FROM acl_group_permission WHERE id_resource IN (SELECT id FROM acl_resource WHERE name = 'nome_ramais');" 2>/dev/null || true
        sqlite3 /var/www/db/acl.db "INSERT INTO acl_group_permission (id_action, id_group, id_resource) SELECT 1, g.id, r.id FROM acl_group g CROSS JOIN acl_resource r WHERE r.name = 'nome_ramais';" 2>/dev/null || true
    fi
    log_success "Gerenciador de Nome dos Ramais instalado."
}

# --- 11. RELATÓRIO GERAL (ASTERNIC CDR) ---
update_asternic_cdr() {
    log_info "Instalando/Atualizando Asternic CDR..."
    ASTERNIC_DEST="/var/www/html/admin/modules/asternic_cdr"
    if [ -d "$REPO_DIR/src/modules/asternic_cdr" ]; then
        if [ -d "$ASTERNIC_DEST" ]; then
            if [ ! -d "/var/www/html/admin/modules/asternic_cdr_OLD" ]; then
                mv "$ASTERNIC_DEST" "/var/www/html/admin/modules/asternic_cdr_OLD"
            else
                rm -rf "$ASTERNIC_DEST"
            fi
        fi
        /bin/cp -rf "$REPO_DIR/src/modules/asternic_cdr" "$ASTERNIC_DEST"
        chown -R asterisk:asterisk "$ASTERNIC_DEST"
        chmod -R 755 "$ASTERNIC_DEST"

        if command -v fwconsole &>/dev/null; then
            fwconsole ma install asternic_cdr 2>/dev/null || true
            fwconsole ma enable asternic_cdr 2>/dev/null || true
        elif command -v amportal &>/dev/null; then
            amportal a ma install asternic_cdr 2>/dev/null || true
            amportal a ma enable asternic_cdr 2>/dev/null || true
        fi

        if command -v sqlite3 &>/dev/null; then
            sqlite3 /var/www/db/acl.db "INSERT OR IGNORE INTO acl_resource (name, description) VALUES ('relatorio_geral', 'Relatorio Geral');" 2>/dev/null || true
            sqlite3 /var/www/db/menu.db "DELETE FROM menu WHERE id = 'relatorio_geral' OR id = 'relatorio_cdr' OR id = 'asternic_cdr';" 2>/dev/null || true
            sqlite3 /var/www/db/menu.db "INSERT INTO menu (id, IdParent, Link, Name, Type, order_no) VALUES ('relatorio_geral', 'reports', 'admin/config.php?display=asternic_cdr', 'Relatorio Geral', 'framed', 10);" 2>/dev/null || true
            sqlite3 /var/www/db/acl.db "INSERT OR IGNORE INTO acl_group_permission (id_action, id_group, id_resource) SELECT 1, 1, id FROM acl_resource WHERE name = 'relatorio_geral';" 2>/dev/null || true
        fi
        log_success "Asternic CDR instalado e adicionado ao menu Relatórios."
    else
        log_error "Pasta src/modules/asternic_cdr não encontrada no repositório."
    fi
}

# --- 12. RELATÓRIO DE FILAS ---
update_relatorio_filas() {
    log_info "Instalando Relatório de Filas e Asternic Stats Lite..."
    QUEUE_SRC="$REPO_DIR/src/modules/relatorio_de_filas"
    MYSQL_PWD=$(get_mysql_pwd)

    # Criação do banco qstatslite
    mysql -u root ${MYSQL_PWD:+-p"$MYSQL_PWD"} -e "CREATE DATABASE IF NOT EXISTS qstatslite DEFAULT CHARACTER SET utf8 COLLATE utf8_general_ci;" 2>/dev/null || \
    mysql -u root -e "CREATE DATABASE IF NOT EXISTS qstatslite DEFAULT CHARACTER SET utf8 COLLATE utf8_general_ci;" 2>/dev/null || true

    if [ -n "$MYSQL_PWD" ]; then
        MYSQL_CMD="mysql -u root -p$MYSQL_PWD qstatslite"
    else
        MYSQL_CMD="mysql -u root qstatslite"
    fi

    $MYSQL_CMD 2>/dev/null <<'EOF' || true
CREATE TABLE IF NOT EXISTS `qname` (
  `qname_id` int(11) NOT NULL AUTO_INCREMENT,
  `queue` varchar(50) NOT NULL DEFAULT '',
  PRIMARY KEY (`qname_id`),
  KEY `queue` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `qagent` (
  `agent_id` int(11) NOT NULL AUTO_INCREMENT,
  `agent` varchar(50) NOT NULL DEFAULT '',
  PRIMARY KEY (`agent_id`),
  KEY `agent` (`agent`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `qevent` (
  `event_id` int(11) NOT NULL AUTO_INCREMENT,
  `event` varchar(50) NOT NULL DEFAULT '',
  PRIMARY KEY (`event_id`),
  KEY `event` (`event`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `queue_stats` (
  `datetime` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `qname` int(11) NOT NULL DEFAULT '0',
  `qagent` int(11) NOT NULL DEFAULT '0',
  `qevent` int(11) NOT NULL DEFAULT '0',
  `info1` varchar(100) NOT NULL DEFAULT '',
  `info2` varchar(100) NOT NULL DEFAULT '',
  `info3` varchar(100) NOT NULL DEFAULT '',
  `info4` varchar(100) NOT NULL DEFAULT '',
  `info5` varchar(100) NOT NULL DEFAULT '',
  `uniqueid` varchar(32) NOT NULL DEFAULT '',
  KEY `datetime` (`datetime`),
  KEY `qname` (`qname`),
  KEY `qagent` (`qagent`),
  KEY `qevent` (`qevent`),
  KEY `uniqueid` (`uniqueid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
EOF

    # Parselog do Asternic
    if [ ! -f /usr/local/parselog/parselog.php ] || [ ! -d /var/www/html/stats ]; then
        log_info "Baixando e configurando Asternic Stats Lite..."
        TMP_ASTERNIC="/tmp/asternic-stats-install"
        rm -rf "$TMP_ASTERNIC"
        mkdir -p "$TMP_ASTERNIC"

        curl -sSL "http://download.asternic.net/asternic-stats-1.5.tar.gz" -o "$TMP_ASTERNIC/asternic-stats-1.5.tar.gz" 2>/dev/null || \
        wget -q "http://download.asternic.net/asternic-stats-1.5.tar.gz" -O "$TMP_ASTERNIC/asternic-stats-1.5.tar.gz" 2>/dev/null || true

        if [ -f "$TMP_ASTERNIC/asternic-stats-1.5.tar.gz" ]; then
            tar -xzf "$TMP_ASTERNIC/asternic-stats-1.5.tar.gz" -C "$TMP_ASTERNIC" 2>/dev/null || true
            mkdir -p /usr/local/parselog
            if [ -f "$TMP_ASTERNIC/asternic-stats/parselog.php" ]; then
                cp -f "$TMP_ASTERNIC/asternic-stats/parselog.php" /usr/local/parselog/
            elif [ -f "$TMP_ASTERNIC/asternic-stats/html/parselog.php" ]; then
                cp -f "$TMP_ASTERNIC/asternic-stats/html/parselog.php" /usr/local/parselog/
            fi
            if [ -f /usr/local/parselog/parselog.php ]; then
                sed -i "s/\$dbuser = .*/\$dbuser = 'root';/" /usr/local/parselog/parselog.php
                sed -i "s/\$dbpass = .*/\$dbpass = '$MYSQL_PWD';/" /usr/local/parselog/parselog.php
            fi
            if [ -d "$TMP_ASTERNIC/asternic-stats/html" ]; then
                mkdir -p /var/www/html/stats
                /bin/cp -rf "$TMP_ASTERNIC/asternic-stats/html/"* /var/www/html/stats/
                if [ -f /var/www/html/stats/config.php ]; then
                    sed -i "s/\$dbuser = .*/\$dbuser = 'root';/" /var/www/html/stats/config.php
                    sed -i "s/\$dbpass = .*/\$dbpass = '$MYSQL_PWD';/" /var/www/html/stats/config.php
                fi
                chown -R asterisk:asterisk /var/www/html/stats
                chmod -R 755 /var/www/html/stats
            fi
            if ! crontab -l 2>/dev/null | grep -q "parselog.php"; then
                (crontab -l 2>/dev/null; echo "* * * * * php /usr/local/parselog/parselog.php > /dev/null 2>&1") | crontab -
            fi
            php /usr/local/parselog/parselog.php &>/dev/null || true
        fi
        rm -rf "$TMP_ASTERNIC"
    fi

    # Relatório de Filas customizado
    if [ -d "$QUEUE_SRC" ]; then
        mkdir -p /var/www/html/modules/relatorio_de_filas /var/www/html/Relatorio_de_filas /var/www/html/relatorio_de_filas /var/www/html/stats
        /bin/cp -rf "$QUEUE_SRC/"* /var/www/html/modules/relatorio_de_filas/
        /bin/cp -rf "$QUEUE_SRC/"* /var/www/html/Relatorio_de_filas/ 2>/dev/null || true
        /bin/cp -rf "$QUEUE_SRC/"* /var/www/html/relatorio_de_filas/ 2>/dev/null || true
        /bin/cp -rf "$QUEUE_SRC/"* /var/www/html/stats/ 2>/dev/null || true
        chown -R asterisk:asterisk /var/www/html/modules/relatorio_de_filas /var/www/html/Relatorio_de_filas /var/www/html/relatorio_de_filas /var/www/html/stats
        chmod -R 755 /var/www/html/modules/relatorio_de_filas /var/www/html/Relatorio_de_filas /var/www/html/relatorio_de_filas /var/www/html/stats

        if command -v sqlite3 &>/dev/null; then
            sqlite3 /var/www/db/acl.db "INSERT OR IGNORE INTO acl_resource (name, description) VALUES ('relatorio_de_filas', 'Relatório de Filas');" 2>/dev/null || true
            sqlite3 /var/www/db/menu.db "DELETE FROM menu WHERE id = 'relatorio_de_filas';" 2>/dev/null || true
            sqlite3 /var/www/db/menu.db "INSERT INTO menu (id, IdParent, Link, Name, Type, order_no) VALUES ('relatorio_de_filas', 'reports', 'Relatorio_de_filas/', 'Relatório de Filas', 'framed', 9);" 2>/dev/null || true
            sqlite3 /var/www/db/acl.db "INSERT OR IGNORE INTO acl_group_permission (id_action, id_group, id_resource) SELECT 1, 1, id FROM acl_resource WHERE name = 'relatorio_de_filas';" 2>/dev/null || true
        fi
    fi

    if [ -d "$REPO_DIR/src/ramais" ]; then
        mkdir -p /var/www/html/ramais
        /bin/cp -rf "$REPO_DIR/src/ramais/"* /var/www/html/ramais/
        chown -R asterisk:asterisk /var/www/html/ramais
    fi
    log_success "Relatório de Filas e Stats Lite configurados."
}

# --- 13. RELATÓRIOS EXTRAS ---
update_relatorios_extras() {
    log_info "Instalando Relatórios Extras (CDR, Gráfico, Resumo, Perdidas, Canais)..."
    local mods=("cdrreport" "graphic_report" "summary_by_extension" "missed_calls" "channelusage")
    local names=("CDR Report" "Relatório Gráfico" "Resumo por Ramal" "Chamadas Perdidas" "Uso de Canais")
    local i=0

    for mod in "${mods[@]}"; do
        if [ -d "$REPO_DIR/src/modules/$mod" ]; then
            mkdir -p "/var/www/html/modules/$mod"
            /bin/cp -rf "$REPO_DIR/src/modules/$mod/"* "/var/www/html/modules/$mod/"
            chown -R asterisk:asterisk "/var/www/html/modules/$mod"
            chmod -R 755 "/var/www/html/modules/$mod"
            log_success "${names[$i]} instalado."
        else
            log_warn "${names[$i]} não encontrado no repositório."
        fi
        ((i++))
    done
    systemctl reload httpd 2>/dev/null || systemctl restart httpd 2>/dev/null || true
}

# --- 14. PESQUISA DE SATISFAÇÃO ---
update_pesquisa() {
    log_info "Instalando Pesquisa de Satisfação (URA + Módulo Web)..."
    SOUNDS_CUSTOM="/var/lib/asterisk/sounds/custom"
    PESQUISA_SOUNDS="$REPO_DIR/src/sounds/custom"
    CUSTOM_EXT="/etc/asterisk/extensions_custom.conf"
    MYSQL_PWD=$(get_mysql_pwd)

    # Áudios
    if [ -d "$PESQUISA_SOUNDS" ]; then
        mkdir -p "$SOUNDS_CUSTOM"
        cp -f "$PESQUISA_SOUNDS"/*.wav "$SOUNDS_CUSTOM/" 2>/dev/null || true
        chown -R asterisk:asterisk "$SOUNDS_CUSTOM"
    fi

    # Dialplan
    if ! grep -q "\[pesquisa-satisfação\]" "$CUSTOM_EXT" 2>/dev/null; then
        if [ -f "$REPO_DIR/src/dialplan/pesquisa_satisfacao.conf" ]; then
            cat "$REPO_DIR/src/dialplan/pesquisa_satisfacao.conf" >> "$CUSTOM_EXT"
        else
            cat <<'EOF' >> "$CUSTOM_EXT"

; --- PESQUISA DE SATISFAÇÃO (PRISMA TELECOM) ---
[pesquisa-satisfação]
exten => 8996,1,Goto(pesquisa,s,1)

[pesquisa]
exten => s,1,NooP(-----INICIO DA PESQUISA---)
same => n,Answer
same => n,Playback(custom/audio1,nm)
same => n,Goto(menu,s,1)

[menu]
include => menu1a5
exten => s,1,NooP(-----inicio da pesquisa menu 1---)
same => n,Answer
same => n,Playback(custom/audio2,nm)
same => n,WaitExten(5,1)

[menu1a5]
exten => 1,1,Set(avaliacao=RUIM)
exten => 1,n,Goto(menu2,s,1)
exten => 2,1,Set(avaliacao=BOM)
exten => 2,n,Goto(menu2,s,1)
exten => 3,1,Set(avaliacao=MEDIO)
exten => 3,n,Goto(menu2,s,1)
exten => 4,1,Set(avaliacao=MUITO BOM)
exten => 4,n,Goto(menu2,s,1)
exten => 5,1,Set(avaliacao=OTIMO)
exten => 5,n,Goto(menu2,s,1)
exten => i,1,Playback(custom/invalido,nm)
exten => i,n,Goto(menu,s,1)

[menu2]
include => menu1a2
exten => s,1,Noop(-----inicio da pesquisa menu 2---)
exten => s,n,Answer
exten => s,n,Playback(custom/audio3,nm)
exten => s,n,WaitExten(5,1)

[menu1a2]
exten => 1,1,Set(solucao=SIM)
exten => 1,n,Goto(fim,s,1)
exten => 2,1,Set(solucao=NAO)
exten => 2,n,Goto(fim,s,1)
exten => i,1,Playback(custom/invalido,nm)
exten => i,n,Goto(menu2,s,1)

[fim]
exten => s,1,NooP(Finalizando Pesquisa)
exten => s,n,Playback(custom/agradecimento,nm)
exten => s,n,Hangup
EOF
        fi
    fi

    # Custom Destination no PBX
    mysql -u root ${MYSQL_PWD:+-p"$MYSQL_PWD"} asterisk -e "INSERT INTO custom_destinations (custom_dest, description, notes) VALUES ('pesquisa-satisfação,8996,1', 'Pesquisa de Satisfação', 'URA de pesquisa de satisfação') ON DUPLICATE KEY UPDATE description='Pesquisa de Satisfação';" 2>/dev/null || \
    mysql -u root asterisk -e "INSERT INTO custom_destinations (custom_dest, description, notes) VALUES ('pesquisa-satisfação,8996,1', 'Pesquisa de Satisfação', 'URA de pesquisa de satisfação') ON DUPLICATE KEY UPDATE description='Pesquisa de Satisfação';" 2>/dev/null || true

    # Módulo Web
    if [ -d "$REPO_DIR/src/modules/pesquisa" ]; then
        mkdir -p /var/www/html/modules/pesquisa
        /bin/cp -rf "$REPO_DIR/src/modules/pesquisa/"* /var/www/html/modules/pesquisa/
        chown -R asterisk:asterisk /var/www/html/modules/pesquisa
        chmod -R 755 /var/www/html/modules/pesquisa
    fi

    # Banco de dados
    if command -v sqlite3 &>/dev/null; then
        sqlite3 /var/www/db/pesquisa.db "CREATE TABLE IF NOT EXISTS pesquisa (id INTEGER PRIMARY KEY AUTOINCREMENT, operador VARCHAR(50), fila VARCHAR(50), data DATE, hora TIME, telefone VARCHAR(50), avaliacao VARCHAR(50), solucao VARCHAR(50));" 2>/dev/null || true
        chown asterisk:asterisk /var/www/db/pesquisa.db 2>/dev/null || true
        chmod 666 /var/www/db/pesquisa.db 2>/dev/null || true

        mysql -u root ${MYSQL_PWD:+-p"$MYSQL_PWD"} -e "CREATE DATABASE IF NOT EXISTS asteriskcdrdb; USE asteriskcdrdb; CREATE TABLE IF NOT EXISTS pesquisa (id INT AUTO_INCREMENT PRIMARY KEY, operador VARCHAR(50), fila VARCHAR(50), data DATE, hora TIME, telefone VARCHAR(50), avaliacao VARCHAR(50), solucao VARCHAR(50));" 2>/dev/null || true

        sqlite3 /var/www/db/menu.db "DELETE FROM menu WHERE id = 'pesquisa';" 2>/dev/null || true
        sqlite3 /var/www/db/menu.db "INSERT INTO menu (id, IdParent, Link, Name, Type, order_no) VALUES ('pesquisa', 'reports', '', 'Pesquisa de Satisfação', 'module', 11);" 2>/dev/null || true
        sqlite3 /var/www/db/acl.db "INSERT OR IGNORE INTO acl_resource (name, description) VALUES ('pesquisa', 'Pesquisa de Satisfação');" 2>/dev/null || true
        sqlite3 /var/www/db/acl.db "INSERT OR IGNORE INTO acl_group_permission (id_action, id_group, id_resource) SELECT 1, 1, id FROM acl_resource WHERE name = 'pesquisa';" 2>/dev/null || true
    fi

    # Atalho 8996
    if [ -f /etc/asterisk/extensions_custom.conf ]; then
        if ! grep -q "exten => 8996" /etc/asterisk/extensions_custom.conf; then
            cat <<'EOF' >> /etc/asterisk/extensions_custom.conf

;==========================================================
; ATALHO DIRETO DE TESTE E TRANSFERENCIA DE PESQUISA (8996)
;==========================================================
[from-internal-custom]
exten => 8996,1,NoOp(---- CHAMADA DIRETA PARA PESQUISA DE SATISFACAO 8996 ----)
same => n,Goto(pesquisa,s,1)
EOF
        fi
        asterisk -rx "dialplan reload" 2>/dev/null || true
    fi

    if command -v fwconsole &>/dev/null; then
        fwconsole reload 2>/dev/null || true
    elif command -v amportal &>/dev/null; then
        amportal a r 2>/dev/null || true
    fi
    log_success "Pesquisa de Satisfação instalada."
}

# --- 15. CALL CENTER ---
update_callcenter() {
    log_info "Instalando/Atualizando Módulo Call Center..."

    if command -v yum &>/dev/null; then
        yum reinstall -y issabel-callcenter 2>/dev/null || yum install -y issabel-callcenter 2>/dev/null || true
    elif command -v dnf &>/dev/null; then
        dnf reinstall -y issabel-callcenter 2>/dev/null || dnf install -y issabel-callcenter 2>/dev/null || true
    fi

    for CC_MOD in agent_console agents callcenter_config campaign_in campaign_monitoring campaign_out dont_call_list eccp_users form_designer form_list hold_time ingoings_calls_success login_logout rep_agent_information rep_agents_monitoring rep_incoming_calls_monitoring rep_trunks_used_per_hour reports_break; do
        if [ -d "$REPO_DIR/src/modules/$CC_MOD" ]; then
            /bin/cp -rf "$REPO_DIR/src/modules/$CC_MOD" /var/www/html/modules/
            chown -R asterisk:asterisk "/var/www/html/modules/$CC_MOD"
            chmod -R 755 "/var/www/html/modules/$CC_MOD"
        fi
    done

    systemctl enable issabelcallcenter 2>/dev/null || true
    systemctl restart issabelcallcenter 2>/dev/null || true
    log_success "Módulo Call Center instalado e ativado."
}

# --- 16. CHANSPY (ESCUTA) ---
update_chanspy() {
    log_info "Configurando ChanSpy (Escuta de Ligações)..."
    CHANSPY_FILE="/etc/asterisk/extensions_override_issabelpbx.conf"
    if ! grep -q "\[app-chanspy\]" "$CHANSPY_FILE" 2>/dev/null; then
        if [ -f "$REPO_DIR/src/dialplan/chanspy.conf" ]; then
            cat "$REPO_DIR/src/dialplan/chanspy.conf" >> "$CHANSPY_FILE"
        else
            cat <<'EOF' >> "$CHANSPY_FILE"

; --- CONFIGURAÇÃO CHANSPY (PRISMA TELECOM) ---
[app-chanspy]
exten => 555,1,Macro(user-callerid,)
exten => 555,n,Answer
exten => 555,n,Wait(1)
exten => 555,n,Authenticate(1234)
exten => 555,n,Wait(1)
exten => 555,n,ChanSpy()
exten => 555,n,Hangup
exten => _555X.,1,Macro(user-callerid,)
exten => _555X.,n,Answer
exten => _555X.,n,Wait(1)
exten => _555X.,n,Authenticate(1234)
exten => _555X.,n,Wait(1)
exten => _555X.,n,ChanSpy(SIP/${EXTEN:3})
exten => _555X.,n,Hangup
EOF
        fi
        log_success "ChanSpy adicionado ao dialplan."
    else
        log_info "ChanSpy já está configurado."
    fi
}

# --- 17. MENSAGENS TEXTO (PJSIP) ---
update_textmessages() {
    log_info "Configurando Envio de Mensagens de Texto (PJSIP)..."
    CUSTOM_EXT="/etc/asterisk/extensions_custom.conf"
    if ! grep -q "\[textmessages\]" "$CUSTOM_EXT" 2>/dev/null; then
        if [ -f "$REPO_DIR/src/dialplan/textmessages.conf" ]; then
            cat "$REPO_DIR/src/dialplan/textmessages.conf" >> "$CUSTOM_EXT"
        else
            cat <<'EOF' >> "$CUSTOM_EXT"

; --- CONFIGURAÇÃO ENVIO MENSAGEM TEXTO PJSIP ---
[textmessages]
exten => _.,1,Gosub(send-text,s,1,(${EXTEN}))
exten => e,1,Hangup()

[send-text]
exten => s,1,NoOp(Sending Text To: ${ARG1})
exten => s,n,Set(PEER=${CUT(CUT(CUT(MESSAGE(from),@,1),<,2),:,2)})
exten => s,n,Set(FROM=${DB(AMPUSER/${PEER}/cidname)})
exten => s,n,Set(CALLERID_NUM=${DB(AMPUSER/${PEER}/cidnum)})
exten => s,n,Set(FROM_SIP=${STRREPLACE(MESSAGE(from),<sip:${PEER}@,<sip:${CALLERID_NUM}@)})
exten => s,n,MessageSend(pjsip:${ARG1},${FROM_SIP})
exten => s,n,Hangup()
EOF
        fi
        log_success "Contextos de mensagem de texto adicionados."
    else
        log_info "TextMessages já configurado."
    fi
}

# --- 18. SERVIDOR LDAP ---
update_ldap() {
    log_info "Instalando Servidor LDAP de Ramais..."
    LDAP_BIN_SRC="$REPO_DIR/src/ldap/issabel-ldap"
    LDAP_SVC_SRC="$REPO_DIR/src/ldap/systemd/issabel-ldap.service"

    if [ -f "$LDAP_BIN_SRC" ]; then
        cp -f "$LDAP_BIN_SRC" /usr/local/bin/issabel-ldap
        chmod 755 /usr/local/bin/issabel-ldap

        if [ -f "$LDAP_SVC_SRC" ]; then
            cp -f "$LDAP_SVC_SRC" /etc/systemd/system/issabel-ldap.service
            chmod 644 /etc/systemd/system/issabel-ldap.service
            systemctl daemon-reload
            systemctl enable issabel-ldap.service 2>/dev/null || true
            systemctl restart issabel-ldap.service 2>/dev/null || true
            log_success "Servidor LDAP instalado e ativo na porta 10389."
        fi
    else
        log_error "Binário LDAP não encontrado no repositório."
    fi
}

# --- 19. MÚSICA DE ESPERA (MOH) ---
update_moh() {
    log_info "Atualizando Músicas de Espera (MOH)..."
    MOH_DEST="/var/lib/asterisk/moh"
    if [ -d "$REPO_DIR/src/sounds/moh" ]; then
        mkdir -p "$MOH_DEST"
        cp -rn "$REPO_DIR/src/sounds/moh/"*.wav "$MOH_DEST/" 2>/dev/null || /bin/cp -rf "$REPO_DIR/src/sounds/moh/"*.wav "$MOH_DEST/" 2>/dev/null || true
        chown -R asterisk:asterisk "$MOH_DEST"
        chmod 644 "$MOH_DEST"/*.wav 2>/dev/null || true
        log_success "Músicas de espera atualizadas."
    else
        log_error "Pasta src/sounds/moh não encontrada no repositório."
    fi
}

# --- 20. TELEGRAM (NOTIFICAÇÕES) ---
update_telegram() {
    log_info "Configurando Notificações via Telegram..."
    TELEGRAM_SRC="$REPO_DIR/scripts/monitor_issabel_users.sh"
    if [ -f "$TELEGRAM_SRC" ]; then
        cp -f "$TELEGRAM_SRC" /usr/local/bin/monitor_issabel_users.sh
        chmod +x /usr/local/bin/monitor_issabel_users.sh

        if ! crontab -l 2>/dev/null | grep -q "monitor_issabel_users.sh"; then
            (crontab -l 2>/dev/null; echo "* * * * * /usr/local/bin/monitor_issabel_users.sh") | crontab -
            log_success "Agendamento de notificação Telegram criado no crontab."
        else
            log_info "Agendamento Telegram já existe no crontab."
        fi
    else
        log_error "Script monitor_issabel_users.sh não encontrado no repositório."
    fi
}

# --- 21. FERRAMENTAS DE DIAGNÓSTICO ---
update_diagnostico() {
    log_info "Instalando ferramentas de diagnóstico (net-tools, tcpdump, sngrep, nmtui)..."
    if command -v yum &>/dev/null; then
        yum install -y net-tools tcpdump 2>/dev/null || true
        if ! command -v sngrep &>/dev/null; then
            yum install -y epel-release 2>/dev/null || true
            if ! yum install -y sngrep 2>/dev/null; then
                printf "[irontec]\nname=Irontec RPMs repository\nbaseurl=http://packages.irontec.com/centos/\$releasever/\$basearch/\ngpgcheck=0\nenabled=1\n" > /etc/yum.repos.d/irontec.repo
                yum install -y sngrep 2>/dev/null || true
            fi
        fi
        yum install -y net-tools tcpdump sngrep NetworkManager-tui 2>/dev/null || true
    elif command -v dnf &>/dev/null; then
        dnf install -y net-tools tcpdump sngrep NetworkManager-tui 2>/dev/null || true
    fi
    systemctl enable NetworkManager 2>/dev/null || true
    systemctl start NetworkManager 2>/dev/null || true
    log_success "Ferramentas de diagnóstico instaladas."
}

# --- 22. FEATURES ASTERISK (TRANSFERÊNCIA + BIP) ---
update_features() {
    log_info "Aplicando ajustes de tempo de transferência e BIP..."
    FEATURES_CUSTOM="/etc/asterisk/features_general_custom.conf"
    if ! grep -q "transferdigittimeout" "$FEATURES_CUSTOM" 2>/dev/null; then
        if [ -f "$REPO_DIR/src/dialplan/features_general_custom.conf" ]; then
            cat "$REPO_DIR/src/dialplan/features_general_custom.conf" >> "$FEATURES_CUSTOM"
        else
            cat <<'EOF' >> "$FEATURES_CUSTOM"

; --- CONFIGURAÇÕES DE TRANSFERÊNCIA DE CHAMADAS (PRISMA TELECOM) ---
transferdigittimeout = 7
atxfernoanswertimeout = 30
atxferdropcall = no
atxferloopdelay = 10
atxfercallbackretries = 2
courtesytone = beep
xfersound = beep
EOF
        fi
        log_success "Ajustes de transferência e BIP aplicados."
    else
        log_info "Features já configurados."
    fi
}

# --- 23. PJSIP USER-AGENT ---
update_pjsip() {
    log_info "Configurando User-Agent PJSIP para IPbx-Prisma..."
    PJSIP_CONF="/etc/asterisk/pjsip.conf"
    PJSIP_CUSTOM="/etc/asterisk/pjsip_custom.conf"

    if [ -f "$PJSIP_CONF" ]; then
        if grep -q "^user_agent=" "$PJSIP_CONF" 2>/dev/null; then
            sed -i 's/^user_agent=.*/user_agent=IPbx-Prisma/' "$PJSIP_CONF"
        elif grep -q "\[global\]" "$PJSIP_CONF" 2>/dev/null; then
            sed -i '/\[global\]/a user_agent=IPbx-Prisma' "$PJSIP_CONF"
        else
            cat <<'EOF' >> "$PJSIP_CONF"

[global]
type=global
user_agent=IPbx-Prisma
EOF
        fi
    fi

    if [ -f "$PJSIP_CUSTOM" ]; then
        if ! grep -q "user_agent=IPbx-Prisma" "$PJSIP_CUSTOM" 2>/dev/null; then
            cat <<'EOF' >> "$PJSIP_CUSTOM"

[global]
type=global
user_agent=IPbx-Prisma
EOF
        fi
    fi
    log_success "User-Agent PJSIP configurado."
}

# --- 24. AUTO-UPDATE SEMANAL ---
update_autoupdate() {
    log_info "Configurando rotina de atualização semanal automática..."
    if [ -f "$REPO_DIR/scripts/ipbx-autoupdate.sh" ]; then
        /bin/cp -f "$REPO_DIR/scripts/ipbx-autoupdate.sh" /usr/local/bin/ipbx-autoupdate
        chmod +x /usr/local/bin/ipbx-autoupdate
        /bin/cp -f "$REPO_DIR/scripts/ipbx-autoupdate.sh" /etc/cron.weekly/ipbx-autoupdate
        chmod +x /etc/cron.weekly/ipbx-autoupdate
        log_success "Atualização automática semanal configurada."
    else
        log_error "Script ipbx-autoupdate.sh não encontrado no repositório."
    fi
}

# --- 25. WEB DEVELOPER ---
update_developer() {
    log_info "Instalando Módulos Web Developer..."
    if command -v sqlite3 &>/dev/null; then
        sqlite3 /var/www/db/menu.db "DELETE FROM menu WHERE id IN ('developer', 'build_module', 'delete_module', 'language_admin', 'web_developer');" 2>/dev/null || true
        sqlite3 /var/www/db/acl.db "DELETE FROM acl_resource WHERE name IN ('developer', 'build_module', 'delete_module', 'language_admin', 'web_developer');" 2>/dev/null || true
    fi

    if command -v yum &>/dev/null; then
        yum reinstall -y issabel-developer 2>/dev/null || yum install -y issabel-developer 2>/dev/null || true
    elif command -v dnf &>/dev/null; then
        dnf reinstall -y issabel-developer 2>/dev/null || dnf install -y issabel-developer 2>/dev/null || true
    fi

    for MOD in build_module delete_module language_admin; do
        if [ -d "$REPO_DIR/src/modules/$MOD" ]; then
            rm -rf "/var/www/html/modules/$MOD"
            /bin/cp -rf "$REPO_DIR/src/modules/$MOD" /var/www/html/modules/
            chown -R asterisk:asterisk "/var/www/html/modules/$MOD"
            chmod -R 755 "/var/www/html/modules/$MOD"
        fi
    done
    log_success "Ferramentas Web Developer instaladas."
}

# --- 26. CONFIGURAR DOMÍNIO & SSL (LET'S ENCRYPT) ---
update_ssl() {
    log_info "Iniciando assistente de configuração de Domínio e Certificado SSL..."
    if [ -f "$REPO_DIR/scripts/auto_dominio.sh" ]; then
        /bin/cp -f "$REPO_DIR/scripts/auto_dominio.sh" /usr/local/bin/ipbx-ssl
        chmod +x /usr/local/bin/ipbx-ssl
        bash "$REPO_DIR/scripts/auto_dominio.sh"
    else
        log_error "Script scripts/auto_dominio.sh não encontrado no repositório."
    fi
}

# --- 27. LIMPEZA DE LOGS & OTIMIZAÇÃO DE DISCO (LOGROTATE) ---
update_limpalogs() {
    log_info "Configurando Logrotate e executando limpeza de logs..."
    if [ -f "$REPO_DIR/scripts/ipbx-logrotate.sh" ]; then
        bash "$REPO_DIR/scripts/ipbx-logrotate.sh" --now
    elif [ -f "$REPO_DIR/scripts/limpa_logs.sh" ]; then
        /bin/cp -f "$REPO_DIR/scripts/limpa_logs.sh" /usr/local/bin/ipbx-limpalogs
        chmod +x /usr/local/bin/ipbx-limpalogs
        bash "$REPO_DIR/scripts/limpa_logs.sh"
    fi

    # Configura agendamento no crontab semanal
    if ! crontab -l 2>/dev/null | grep -q "ipbx-limpalogs"; then
        (crontab -l 2>/dev/null; echo "0 4 * * 0 /usr/local/bin/ipbx-limpalogs > /dev/null 2>&1") | crontab -
        log_success "Agendamento de limpeza semanal configurado no crontab (Domingos às 04h)."
    fi
}

# --- 28. MONITOR DE SEGURANÇA & HARDENING ANTI-INVASÃO (TELEGRAM) ---
update_monitor_prisma() {
    log_info "Aplicando Hardening de Segurança & Monitor Telegram..."
    if [ -f "$REPO_DIR/scripts/ipbx-security-hardening.sh" ]; then
        bash "$REPO_DIR/scripts/ipbx-security-hardening.sh"
    fi

    if [ -f "$REPO_DIR/scripts/monitor_prisma.sh" ]; then
        /bin/cp -f "$REPO_DIR/scripts/monitor_prisma.sh" /usr/local/bin/monitor_issabel_users.sh
        /bin/cp -f "$REPO_DIR/scripts/monitor_prisma.sh" /usr/local/bin/monitor_prisma.sh
        chmod +x /usr/local/bin/monitor_issabel_users.sh /usr/local/bin/monitor_prisma.sh

        if ! crontab -l 2>/dev/null | grep -q "monitor_issabel_users.sh"; then
            (crontab -l 2>/dev/null; echo "* * * * * /usr/local/bin/monitor_issabel_users.sh") | crontab -
            log_success "Monitor de segurança ativo no crontab (a cada minuto)."
        else
            log_info "Monitor de segurança já configurado no crontab."
        fi
    fi
}

# --- 29. ROLLBACK ---
update_rollback() {
    log_info "Implantando comando de rollback no sistema..."
    if [ -f "$REPO_DIR/rollback.sh" ]; then
        /bin/cp -f "$REPO_DIR/rollback.sh" /usr/local/bin/ipbx-rollback
        chmod +x /usr/local/bin/ipbx-rollback
        log_success "Comando de rollback disponível: ipbx-rollback"
    else
        log_error "Arquivo rollback.sh não encontrado no repositório."
    fi
}

# --- INSTALAR TUDO ---
install_all() {
    echo ""
    log_info "Executando instalação COMPLETA de todos os módulos..."
    echo ""
    update_motd
    update_admin
    update_agenda
    update_webphone
    update_clicktodial
    update_asternic_cdr
    update_chanspy
    update_textmessages
    update_ldap
    update_lang
    update_modules
    update_moh
    update_monitor_prisma
    update_painel
    update_nome_ramais
    update_pesquisa
    update_callcenter
    update_relatorio_filas
    update_relatorios_extras
    update_developer
    update_tema
    update_diagnostico
    update_features
    update_pjsip
    update_autoupdate
    update_limpalogs
    update_rollback
    reload_services
    echo ""
    log_success "INSTALAÇÃO COMPLETA FINALIZADA!"
}

# ==============================================================================
# MENU INTERATIVO
# ==============================================================================

show_menu() {
    clear
    echo -e "${BLUE}╔══════════════════════════════════════════════════════════════════════╗${NC}"
    echo -e "${BLUE}║${WHITE}       IPBX PRISMA TELECOM - MENU DE ATUALIZAÇÃO MODULAR          ${BLUE}║${NC}"
    echo -e "${BLUE}╠══════════════════════════════════════════════════════════════════════╣${NC}"
    echo -e "${BLUE}║${NC}                                                                    ${BLUE}║${NC}"
    echo -e "${BLUE}║${NC}  ${GREEN}APARÊNCIA E INTERFACE${NC}                                            ${BLUE}║${NC}"
    echo -e "${BLUE}║${NC}   ${WHITE}[1]${NC}  Terminal (MOTD)              ${WHITE}[2]${NC}  Tema e Favicon            ${BLUE}║${NC}"
    echo -e "${BLUE}║${NC}   ${WHITE}[3]${NC}  Painel Admin                ${WHITE}[4]${NC}  Traduções (lang)          ${BLUE}║${NC}"
    echo -e "${BLUE}║${NC}                                                                    ${BLUE}║${NC}"
    echo -e "${BLUE}║${NC}  ${GREEN}MÓDULOS E FUNCIONALIDADES${NC}                                        ${BLUE}║${NC}"
    echo -e "${BLUE}║${NC}   ${WHITE}[5]${NC}  Módulos Web (todos)         ${WHITE}[6]${NC}  Agenda Telefônica         ${BLUE}║${NC}"
    echo -e "${BLUE}║${NC}   ${WHITE}[7]${NC}  Webphone WebRTC             ${WHITE}[8]${NC}  Click-to-Dial             ${BLUE}║${NC}"
    echo -e "${BLUE}║${NC}   ${WHITE}[9]${NC}  Painel IPbx                 ${WHITE}[10]${NC} Nome dos Ramais           ${BLUE}║${NC}"
    echo -e "${BLUE}║${NC}                                                                    ${BLUE}║${NC}"
    echo -e "${BLUE}║${NC}  ${GREEN}RELATÓRIOS${NC}                                                       ${BLUE}║${NC}"
    echo -e "${BLUE}║${NC}   ${WHITE}[11]${NC} Relatório Geral (CDR)       ${WHITE}[12]${NC} Relatório de Filas        ${BLUE}║${NC}"
    echo -e "${BLUE}║${NC}   ${WHITE}[13]${NC} Relatórios Extras           ${WHITE}[14]${NC} Pesquisa de Satisfação    ${BLUE}║${NC}"
    echo -e "${BLUE}║${NC}                                                                    ${BLUE}║${NC}"
    echo -e "${BLUE}║${NC}  ${GREEN}CALL CENTER E COMUNICAÇÃO${NC}                                        ${BLUE}║${NC}"
    echo -e "${BLUE}║${NC}   ${WHITE}[15]${NC} Call Center                 ${WHITE}[16]${NC} ChanSpy (Escuta)          ${BLUE}║${NC}"
    echo -e "${BLUE}║${NC}   ${WHITE}[17]${NC} Mensagens Texto (PJSIP)     ${WHITE}[18]${NC} Servidor LDAP             ${BLUE}║${NC}"
    echo -e "${BLUE}║${NC}                                                                    ${BLUE}║${NC}"
    echo -e "${BLUE}║${NC}  ${GREEN}SISTEMA, SEGURANÇA E REDE${NC}                                        ${BLUE}║${NC}"
    echo -e "${BLUE}║${NC}   ${WHITE}[19]${NC} Música de Espera (MOH)      ${WHITE}[20]${NC} Monitor Segurança Telegram ${BLUE}║${NC}"
    echo -e "${BLUE}║${NC}   ${WHITE}[21]${NC} Ferramentas Diagnóstico     ${WHITE}[22]${NC} Features Asterisk         ${BLUE}║${NC}"
    echo -e "${BLUE}║${NC}   ${WHITE}[23]${NC} PJSIP User-Agent            ${WHITE}[24]${NC} Auto-Update Semanal       ${BLUE}║${NC}"
    echo -e "${BLUE}║${NC}   ${WHITE}[25]${NC} Web Developer               ${WHITE}[26]${NC} Configurar Domínio e SSL  ${BLUE}║${NC}"
    echo -e "${BLUE}║${NC}   ${WHITE}[27]${NC} Limpeza de Logs e Disco     ${WHITE}[28]${NC} Instalar Rollback         ${BLUE}║${NC}"
    echo -e "${BLUE}║${NC}                                                                    ${BLUE}║${NC}"
    echo -e "${BLUE}╠══════════════════════════════════════════════════════════════════════╣${NC}"
    echo -e "${BLUE}║${NC}   ${YELLOW}[A]${NC}  ${YELLOW}INSTALAR TUDO${NC} (igual ao install.sh completo)                ${BLUE}║${NC}"
    echo -e "${BLUE}║${NC}   ${RED}[0]${NC}  ${RED}Sair${NC}                                                       ${BLUE}║${NC}"
    echo -e "${BLUE}╚══════════════════════════════════════════════════════════════════════╝${NC}"
    echo ""
}

# --- LOOP PRINCIPAL ---
while true; do
    show_menu
    echo -ne "${CYAN}  Escolha uma opção: ${NC}"
    menu_read -r OPCAO

    case "$OPCAO" in
        1)  create_snapshot "Terminal (MOTD)"; update_motd; reload_services ;;
        2)  create_snapshot "Tema e Favicon"; update_tema; reload_services ;;
        3)  create_snapshot "Painel Admin"; update_admin; reload_services ;;
        4)  create_snapshot "Traduções (lang)"; update_lang; reload_services ;;
        5)  create_snapshot "Módulos Web"; update_modules; reload_services ;;
        6)  create_snapshot "Agenda Telefônica"; update_agenda; reload_services ;;
        7)  create_snapshot "Webphone WebRTC"; update_webphone; reload_services ;;
        8)  create_snapshot "Click-to-Dial"; update_clicktodial; reload_services ;;
        9)  create_snapshot "Painel IPbx"; update_painel; reload_services ;;
        10) create_snapshot "Nome dos Ramais"; update_nome_ramais; reload_services ;;
        11) create_snapshot "Relatório Geral (CDR)"; update_asternic_cdr; reload_services ;;
        12) create_snapshot "Relatório de Filas"; update_relatorio_filas; reload_services ;;
        13) create_snapshot "Relatórios Extras"; update_relatorios_extras; reload_services ;;
        14) create_snapshot "Pesquisa de Satisfação"; update_pesquisa; reload_services ;;
        15) create_snapshot "Call Center"; update_callcenter; reload_services ;;
        16) create_snapshot "ChanSpy (Escuta)"; update_chanspy; reload_services ;;
        17) create_snapshot "Mensagens Texto"; update_textmessages; reload_services ;;
        18) create_snapshot "Servidor LDAP"; update_ldap; reload_services ;;
        19) create_snapshot "Música de Espera"; update_moh; reload_services ;;
        20) create_snapshot "Monitor Segurança Telegram"; update_monitor_prisma ;;
        21) create_snapshot "Ferramentas Diagnóstico"; update_diagnostico; reload_services ;;
        22) create_snapshot "Features Asterisk"; update_features; reload_services ;;
        23) create_snapshot "PJSIP User-Agent"; update_pjsip; reload_services ;;
        24) create_snapshot "Auto-Update Semanal"; update_autoupdate; reload_services ;;
        25) create_snapshot "Web Developer"; update_developer; reload_services ;;
        26) create_snapshot "Configuração Domínio SSL"; update_ssl; reload_services ;;
        27) update_limpalogs ;;
        28) update_rollback ;;
        [aA]) create_snapshot "Instalação Completa"; install_all ;;
        0)
            echo ""
            echo -e "${GREEN}Saindo do menu de atualização. Até logo!${NC}"
            echo ""
            exit 0
            ;;
        *)
            echo -e "${RED}Opção inválida! Tente novamente.${NC}"
            ;;
    esac

    echo ""
    echo -ne "${YELLOW}Pressione ENTER para voltar ao menu...${NC}"
    menu_read -r
done
