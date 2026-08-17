#!/bin/bash
# ==============================================================================
# SCRIPT MESTRE DE INSTALAÇÃO E CUSTOMIZAÇÃO - ISSABEL PBX (PRISMA TELECOM)
# ==============================================================================
# Este script automatiza o processo de pós-instalação e atualização do Issabel PBX.
# Suporta Issabel 4 (CentOS 7) e Issabel 5 (Rocky Linux 8).
# Aplica temas, módulos personalizados, correções de telas, relatórios, áudios e configs.
#
# Estratégia de Backup:
# Nenhuma pasta nativa é excluída. As pastas nativas são renomeadas para "<nome>_old".
# ==============================================================================

set -e

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
NC='\033[0m' # No Color

log_info() { echo -e "${CYAN}[INFO]${NC} $1"; }
log_success() { echo -e "${GREEN}[SUCESSO]${NC} $1"; }
log_warn() { echo -e "${YELLOW}[AVISO]${NC} $1"; }
log_error() { echo -e "${RED}[ERRO]${NC} $1"; }

# --- VERIFICAÇÃO DE PERMISSÃO ROOT ---
if [ "$EUID" -ne 0 ]; then
    log_error "Este script precisa ser executado como root!"
    exit 1
fi

log_info "Iniciando a instalação automatizada das customizações IPBX Issabel..."

# --- DIRETÓRIO DO REPOSITÓRIO ---
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$SCRIPT_DIR"

# Se o script foi executado fora do repositório clonado, baixa a cópia atualizada
if [ ! -f "$REPO_DIR/install.sh" ] || [ ! -d "$REPO_DIR/src" ]; then
    TMP_REPO="/tmp/ipbx-issabel-repo"
    log_info "Preparando download do repositório em $TMP_REPO..."
    rm -rf "$TMP_REPO"
    mkdir -p "$TMP_REPO"

    # Garante que git esteja instalado no CentOS / Rocky Linux
    if ! command -v git &>/dev/null; then
        log_info "Instalando pacote git no servidor..."
        yum install -y git 2>/dev/null || dnf install -y git 2>/dev/null || true
    fi

    if command -v git &>/dev/null; then
        log_info "Baixando o repositório completo via git..."
        git clone --depth 1 https://github.com/LeandroSaltori/ipbx-issabel6.0.git "$TMP_REPO"
    else
        log_info "Baixando repositório compactado via curl..."
        curl -sSL "https://github.com/LeandroSaltori/ipbx-issabel6.0/archive/refs/heads/main.tar.gz" | tar -xz -C "$TMP_REPO" --strip-components=1
    fi
    REPO_DIR="$TMP_REPO"
fi

cd "$REPO_DIR"

# --- FUNÇÃO DE BACKUP COM SUFIXO _old ---
backup_and_deploy() {
    local src="$1"
    local dest="$2"
    local backup="${dest}_old"

    if [ -e "$dest" ]; then
        if [ ! -e "$backup" ]; then
            log_info "Backup criado: $dest -> $backup"
            mv "$dest" "$backup"
        else
            log_warn "Backup $backup já existe. Preservando backup original."
            rm -rf "$dest"
        fi
    fi

    log_info "Implantando: $src -> $dest"
    /bin/cp -rf "$src" "$dest"
}

# ==============================================================================
# 1. ALTERAR TELA DO TERMINAL (MOTD)
# ==============================================================================
log_info "1/20 - Configurando tela personalizada do terminal (MOTD)..."
if [ -f "$REPO_DIR/scripts/motd.sh" ]; then
    MOTD_DEST="/usr/local/sbin/motd.sh"
    backup_and_deploy "$REPO_DIR/scripts/motd.sh" "$MOTD_DEST"
    chmod +x "$MOTD_DEST"
    log_success "Tela do terminal (MOTD) configurada."
fi

# ==============================================================================
# 2. DIRETÓRIO ADMIN (/var/www/html/admin)
# ==============================================================================
log_info "2/20 - Atualizando pasta /var/www/html/admin..."
if [ -d "$REPO_DIR/src/admin" ]; then
    backup_and_deploy "$REPO_DIR/src/admin" "/var/www/html/admin"
    chown -R asterisk:asterisk /var/www/html/admin
    log_success "Pasta admin atualizada."
fi

# ==============================================================================
# 3. AGENDA.PHP
# ==============================================================================
log_info "3/20 - Instalando Agenda.php..."
if [ -f "$REPO_DIR/src/Agenda.php" ]; then
    cp -f "$REPO_DIR/src/Agenda.php" /var/www/html/Agenda.php
    cp -f "$REPO_DIR/src/Agenda.php" /var/www/html/agenda.php
    chown asterisk:asterisk /var/www/html/Agenda.php /var/www/html/agenda.php
    chmod 644 /var/www/html/Agenda.php /var/www/html/agenda.php
    log_success "Agenda.php instalada."
fi

# ==============================================================================
# 4. WEBPHONE WEBRTC
# ==============================================================================
log_info "4/20 - Instalando Webphone..."
if [ -d "$REPO_DIR/src/webphone" ]; then
    mkdir -p /var/www/html/webphone
    /bin/cp -rf "$REPO_DIR/src/webphone/"* /var/www/html/webphone/
    chown -R asterisk:asterisk /var/www/html/webphone
    chmod -R 755 /var/www/html/webphone
    log_success "Webphone instalado em /var/www/html/webphone."
fi

# ==============================================================================
# 5. EXTENSÃO CHROME / CLICK TO DIAL (EXTENSAO-PRISMA)
# ==============================================================================
log_info "5/20 - Instalando backend da Extensão Prisma Click-to-Dial (call.php)..."
if [ -f "$REPO_DIR/src/extensions/chrome-click-to-dial/call.php" ]; then
    cp -f "$REPO_DIR/src/extensions/chrome-click-to-dial/call.php" /var/www/html/call.php
    chown asterisk:asterisk /var/www/html/call.php
    chmod 644 /var/www/html/call.php
    log_success "call.php instalado em /var/www/html/call.php."
fi

# ==============================================================================
# 6. ASTERNIC CDR
# ==============================================================================
log_info "6/20 - Instalando/Atualizando Asternic CDR..."
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

    # Registra o menu "Relatorio Geral" dentro da aba Reports (Relatórios)
    if command -v sqlite3 &>/dev/null; then
        sqlite3 /var/www/db/acl.db "INSERT OR IGNORE INTO acl_resource (name, description) VALUES ('relatorio_cdr', 'Relatorio Geral');" 2>/dev/null || true
        sqlite3 /var/www/db/menu.db "DELETE FROM menu WHERE id = 'relatorio_cdr';" 2>/dev/null || true
        sqlite3 /var/www/db/menu.db "INSERT INTO menu (id, IdParent, Link, Name, Type, order_no) VALUES ('relatorio_cdr', 'reports', 'admin/config.php?display=asternic_cdr', 'Relatorio Geral', 'framed', 10);" 2>/dev/null || true
        sqlite3 /var/www/db/acl.db "INSERT OR IGNORE INTO acl_group_permission (id_action, id_group, id_resource) SELECT 1, 1, id FROM acl_resource WHERE name = 'relatorio_cdr';" 2>/dev/null || true
    fi
    log_success "Asternic CDR instalado e adicionado ao menu Relatórios."
fi

# ==============================================================================
# 7. CHANSPY (ESCUTA DE LIGAÇÕES)
# ==============================================================================
log_info "7/20 - Configurando ChanSpy..."
CHANSPY_FILE="/etc/asterisk/extensions_override_issabelpbx.conf"
if ! grep -q "\[app-chanspy\]" "$CHANSPY_FILE" 2>/dev/null; then
    if [ -f "$REPO_DIR/src/dialplan/chanspy.conf" ]; then
        cat "$REPO_DIR/src/dialplan/chanspy.conf" >> "$CHANSPY_FILE"
    else
        cat << 'EOF' >> "$CHANSPY_FILE"

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
    log_success "ChanSpy adicionado em $CHANSPY_FILE."
else
    log_info "ChanSpy já está configurado no dialplan."
fi

# ==============================================================================
# 8. ENVIO MENSAGEM TEXTO (PJSIP MESSAGING)
# ==============================================================================
log_info "8/20 - Configurando EnvioMensagemTexto..."
CUSTOM_EXT="/etc/asterisk/extensions_custom.conf"
if ! grep -q "\[textmessages\]" "$CUSTOM_EXT" 2>/dev/null; then
    if [ -f "$REPO_DIR/src/dialplan/textmessages.conf" ]; then
        cat "$REPO_DIR/src/dialplan/textmessages.conf" >> "$CUSTOM_EXT"
    else
        cat << 'EOF' >> "$CUSTOM_EXT"

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
    log_success "Contextos de mensagem de texto adicionados em $CUSTOM_EXT."
else
    log_info "Contexto textmessages já configurado."
fi

# ==============================================================================
# 9. SERVIDOR LDAP DE RAMAIS
# ==============================================================================
log_info "9/20 - Instalando Servidor LDAP de Ramais..."
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
        log_success "Servidor LDAP de ramais instalado e ativo na porta 10389."
    fi
fi

# ==============================================================================
# 10. PASTAS LANG E MODULES EM /var/www/html
# ==============================================================================
log_info "10/20 - Atualizando pastas lang e modules em /var/www/html..."
if [ -d "$REPO_DIR/src/lang" ]; then
    backup_and_deploy "$REPO_DIR/src/lang" "/var/www/html/lang"
    chown -R asterisk:asterisk /var/www/html/lang
fi

if [ -d "$REPO_DIR/src/modules" ]; then
    if [ -d "/var/www/html/modules" ] && [ ! -d "/var/www/html/modules_old" ]; then
        log_info "Backup criado: /var/www/html/modules -> /var/www/html/modules_old"
        /bin/cp -rf /var/www/html/modules /var/www/html/modules_old
    fi
    /bin/cp -rf "$REPO_DIR/src/modules/"* /var/www/html/modules/
    chown -R asterisk:asterisk /var/www/html/modules
    log_success "Módulos sincronizados em /var/www/html/modules."
fi

# ==============================================================================
# 11. MÚSICA DE ESPERA (MOH)
# ==============================================================================
log_info "11/20 - Atualizando Músicas de Espera (MOH)..."
MOH_DEST="/var/lib/asterisk/moh"
if [ -d "$REPO_DIR/src/sounds/moh" ]; then
    mkdir -p "$MOH_DEST"
    cp -rn "$REPO_DIR/src/sounds/moh/"*.wav "$MOH_DEST/" 2>/dev/null || /bin/cp -rf "$REPO_DIR/src/sounds/moh/"*.wav "$MOH_DEST/" 2>/dev/null || true
    chown -R asterisk:asterisk "$MOH_DEST"
    chmod 644 "$MOH_DEST"/*.wav 2>/dev/null || true
    log_success "Músicas de espera atualizadas em $MOH_DEST."
fi

# ==============================================================================
# 12. NOTIFICAÇÕES TELEGRAM
# ==============================================================================
log_info "12/20 - Configurando Notificações via Telegram..."
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
fi

# ==============================================================================
# 13. PAINEL IPBX (control_panel)
# ==============================================================================
log_info "13/20 - Instalando Painel IPbx..."
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
fi

# ==============================================================================
# 14. PESQUISA DE SATISFAÇÃO (URA + MÓDULO WEB)
# ==============================================================================
log_info "14/20 - Instalando Pesquisa de Satisfação (URA e Módulo Web)..."
SOUNDS_CUSTOM="/var/lib/asterisk/sounds/custom"
PESQUISA_SOUNDS="$REPO_DIR/src/sounds/custom"

if [ -d "$PESQUISA_SOUNDS" ]; then
    mkdir -p "$SOUNDS_CUSTOM"
    cp -f "$PESQUISA_SOUNDS"/*.wav "$SOUNDS_CUSTOM/" 2>/dev/null || true
    chown -R asterisk:asterisk "$SOUNDS_CUSTOM"
    
    if ! grep -q "\[pesquisa-satisfação\]" "$CUSTOM_EXT" 2>/dev/null; then
        cat << 'EOF' >> "$CUSTOM_EXT"

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
        log_success "Dialplan de pesquisa de satisfação adicionado."
    fi

    # Registra o Custom Destination no FreePBX/IssabelPBX (banco asterisk)
    MYSQL_PWD=""
    if [ -f /etc/issabel.conf ]; then
        MYSQL_PWD=$(grep -i mysqlrootpwd /etc/issabel.conf 2>/dev/null | cut -d'=' -f2 | tr -d ' ')
    fi

    mysql -u root -p"$MYSQL_PWD" asterisk -e "INSERT INTO custom_destinations (custom_dest, description, notes) VALUES ('pesquisa-satisfação,8996,1', 'Pesquisa de Satisfação', 'URA de pesquisa de satisfação') ON DUPLICATE KEY UPDATE description='Pesquisa de Satisfação';" 2>/dev/null || mysql -u root asterisk -e "INSERT INTO custom_destinations (custom_dest, description, notes) VALUES ('pesquisa-satisfação,8996,1', 'Pesquisa de Satisfação', 'URA de pesquisa de satisfação') ON DUPLICATE KEY UPDATE description='Pesquisa de Satisfação';" 2>/dev/null || true
    
    if command -v fwconsole &>/dev/null; then
        fwconsole reload 2>/dev/null || true
    elif command -v amportal &>/dev/null; then
        amportal a r 2>/dev/null || true
    fi
    log_success "Custom Destination da Pesquisa de Satisfação registrado no PBX."
fi

# Implantação do Módulo Web da Pesquisa de Satisfação
if [ -d "$REPO_DIR/src/modules/pesquisa" ]; then
    mkdir -p /var/www/html/modules/pesquisa
    /bin/cp -rf "$REPO_DIR/src/modules/pesquisa/"* /var/www/html/modules/pesquisa/
    chown -R asterisk:asterisk /var/www/html/modules/pesquisa
    chmod -R 755 /var/www/html/modules/pesquisa
    
    if command -v sqlite3 &>/dev/null; then
        # Cria a tabela no banco pesquisa.db (SQLite)
        sqlite3 /var/www/db/pesquisa.db "CREATE TABLE IF NOT EXISTS pesquisa (id INTEGER PRIMARY KEY AUTOINCREMENT, operador VARCHAR(50), fila VARCHAR(50), data DATE, hora TIME, telefone VARCHAR(50), avaliacao VARCHAR(50), solucao VARCHAR(50));" 2>/dev/null || true
        chown asterisk:asterisk /var/www/db/pesquisa.db 2>/dev/null || true
        chmod 666 /var/www/db/pesquisa.db 2>/dev/null || true
        
        # Cria a tabela no MySQL (asteriskcdrdb)
        MYSQL_PASS=$(grep -i mysqlrootpwd /etc/issabel.conf 2>/dev/null | cut -d'=' -f2 | tr -d ' ')
        mysql -u root -p"$MYSQL_PASS" -e "CREATE DATABASE IF NOT EXISTS asteriskcdrdb; USE asteriskcdrdb; CREATE TABLE IF NOT EXISTS pesquisa (id INT AUTO_INCREMENT PRIMARY KEY, operador VARCHAR(50), fila VARCHAR(50), data DATE, hora TIME, telefone VARCHAR(50), avaliacao VARCHAR(50), solucao VARCHAR(50));" 2>/dev/null || \
        mysql -u root -e "CREATE DATABASE IF NOT EXISTS asteriskcdrdb; USE asteriskcdrdb; CREATE TABLE IF NOT EXISTS pesquisa (id INT AUTO_INCREMENT PRIMARY KEY, operador VARCHAR(50), fila VARCHAR(50), data DATE, hora TIME, telefone VARCHAR(50), avaliacao VARCHAR(50), solucao VARCHAR(50));" 2>/dev/null || true

        # Registra no menu do Issabel apenas 'Pesquisa de Satisfação' no menu lateral
        sqlite3 /var/www/db/menu.db "DELETE FROM menu WHERE id = 'pesquisa' OR id = 'pesquisa_ajuda';" 2>/dev/null || true
        sqlite3 /var/www/db/menu.db "INSERT INTO menu (id, IdParent, Link, Name, Type, order_no) VALUES ('pesquisa', 'reports', '', 'Pesquisa de Satisfação', 'module', 11);" 2>/dev/null || true
        
        sqlite3 /var/www/db/acl.db "DELETE FROM acl_resource WHERE name = 'pesquisa_ajuda';" 2>/dev/null || true
        sqlite3 /var/www/db/acl.db "INSERT OR IGNORE INTO acl_resource (name, description) VALUES ('pesquisa', 'Pesquisa de Satisfação');" 2>/dev/null || true
        
        sqlite3 /var/www/db/acl.db "INSERT OR IGNORE INTO acl_group_permission (id_action, id_group, id_resource) SELECT 1, 1, id FROM acl_resource WHERE name = 'pesquisa';" 2>/dev/null || true
    fi
    log_success "Módulo Web da Pesquisa de Satisfação registrado no menu Relatórios."
fi

# ==============================================================================
# 15. CALL CENTER OFICIAL DO ISSABEL
# ==============================================================================
log_info "15/20 - Instalando e Configurando Módulo Call Center..."

# 1. Instala/Reinstala o pacote oficial issabel-callcenter
if command -v yum &>/dev/null; then
    yum reinstall -y issabel-callcenter 2>/dev/null || yum install -y issabel-callcenter 2>/dev/null || true
elif command -v dnf &>/dev/null; then
    dnf reinstall -y issabel-callcenter 2>/dev/null || dnf install -y issabel-callcenter 2>/dev/null || true
fi

# 2. Copia os módulos atualizados do Call Center
for CC_MOD in agent_console agents callcenter_config campaign_in campaign_monitoring campaign_out dont_call_list eccp_users form_designer form_list hold_time ingoings_calls_success login_logout rep_agent_information rep_agents_monitoring rep_incoming_calls_monitoring rep_trunks_used_per_hour reports_break; do
    if [ -d "$REPO_DIR/src/modules/$CC_MOD" ]; then
        /bin/cp -rf "$REPO_DIR/src/modules/$CC_MOD" /var/www/html/modules/
        chown -R asterisk:asterisk "/var/www/html/modules/$CC_MOD"
        chmod -R 755 "/var/www/html/modules/$CC_MOD"
    fi
done

# 3. Inicia e habilita o serviço do discador do Call Center
systemctl enable issabelcallcenter 2>/dev/null || true
systemctl restart issabelcallcenter 2>/dev/null || true
log_success "Módulo Call Center instalado e ativado."

# ==============================================================================
# 16. ASTERNIC STATS LITE, RELATÓRIO DE FILAS E RAMAIS
# ==============================================================================
log_info "16/20 - Instalando Asternic Call Center Stats Lite e Relatório de Filas..."
QUEUE_SRC="$REPO_DIR/src/modules/relatorio_de_filas"

# 1. Criação do banco de dados qstatslite no MySQL / MariaDB
MYSQL_PWD=""
if [ -f /etc/issabel.conf ]; then
    MYSQL_PWD=$(grep -i mysqlrootpwd /etc/issabel.conf 2>/dev/null | cut -d'=' -f2 | tr -d ' ')
fi

mysql -u root -p"$MYSQL_PWD" -e "CREATE DATABASE IF NOT EXISTS qstatslite DEFAULT CHARACTER SET utf8 COLLATE utf8_general_ci;" 2>/dev/null || mysql -u root -e "CREATE DATABASE IF NOT EXISTS qstatslite DEFAULT CHARACTER SET utf8 COLLATE utf8_general_ci;" 2>/dev/null || true

if [ -n "$MYSQL_PWD" ]; then
    MYSQL_CMD="mysql -u root -p$MYSQL_PWD qstatslite"
else
    MYSQL_CMD="mysql -u root qstatslite"
fi

$MYSQL_CMD 2>/dev/null << 'EOF' || true
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

# 2. Instalação do Asternic Call Center Stats Lite (parselog.php e /var/www/html/stats)
if [ ! -f /usr/local/parselog/parselog.php ] || [ ! -d /var/www/html/stats ]; then
    log_info "Baixando e configurando Asternic Stats Lite..."
    TMP_ASTERNIC="/tmp/asternic-stats-install"
    rm -rf "$TMP_ASTERNIC"
    mkdir -p "$TMP_ASTERNIC"
    
    curl -sSL "http://download.asternic.net/asternic-stats-1.5.tar.gz" -o "$TMP_ASTERNIC/asternic-stats-1.5.tar.gz" 2>/dev/null || wget -q "http://download.asternic.net/asternic-stats-1.5.tar.gz" -O "$TMP_ASTERNIC/asternic-stats-1.5.tar.gz" 2>/dev/null || true
    
    if [ -f "$TMP_ASTERNIC/asternic-stats-1.5.tar.gz" ]; then
        tar -xzf "$TMP_ASTERNIC/asternic-stats-1.5.tar.gz" -C "$TMP_ASTERNIC" 2>/dev/null || true
        
        # Cria pasta /usr/local/parselog
        mkdir -p /usr/local/parselog
        if [ -f "$TMP_ASTERNIC/asternic-stats/parselog.php" ]; then
            cp -f "$TMP_ASTERNIC/asternic-stats/parselog.php" /usr/local/parselog/
        elif [ -f "$TMP_ASTERNIC/asternic-stats/html/parselog.php" ]; then
            cp -f "$TMP_ASTERNIC/asternic-stats/html/parselog.php" /usr/local/parselog/
        fi
        
        # Configura credenciais no parselog.php
        if [ -f /usr/local/parselog/parselog.php ]; then
            sed -i "s/\$dbuser = .*/\$dbuser = 'root';/" /usr/local/parselog/parselog.php
            sed -i "s/\$dbpass = .*/\$dbpass = '$MYSQL_PWD';/" /usr/local/parselog/parselog.php
        fi
        
        # Copia pasta web do Asternic Lite para /var/www/html/stats
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
        
        # Agendamento no Crontab para processar logs de fila a cada minuto
        if ! crontab -l 2>/dev/null | grep -q "parselog.php"; then
            (crontab -l 2>/dev/null; echo "* * * * * php /usr/local/parselog/parselog.php > /dev/null 2>&1") | crontab -
            log_success "Agendamento do parselog.php criado no crontab."
        fi
        
        # Executa a primeira rodada do parselog
        php /usr/local/parselog/parselog.php &>/dev/null || true
    fi
    rm -rf "$TMP_ASTERNIC"
fi

# 3. Implantação do seu Relatório de Filas Melhorado (Interface Customizada)
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
    log_success "Módulo Ramais e Relatório de Filas configurados."
fi

# ==============================================================================
# 17. MÓDULOS WEB DEVELOPER
# ==============================================================================
log_info "17/20 - Instalando Módulos Web Developer..."

# 1. Limpa entradas duplicadas e reinstala o pacote oficial
if command -v sqlite3 &>/dev/null; then
    sqlite3 /var/www/db/menu.db "DELETE FROM menu WHERE id IN ('developer', 'build_module', 'delete_module', 'language_admin', 'web_developer');" 2>/dev/null || true
    sqlite3 /var/www/db/acl.db "DELETE FROM acl_resource WHERE name IN ('developer', 'build_module', 'delete_module', 'language_admin', 'web_developer');" 2>/dev/null || true
fi

if command -v yum &>/dev/null; then
    yum reinstall -y issabel-developer 2>/dev/null || yum install -y issabel-developer 2>/dev/null || true
elif command -v dnf &>/dev/null; then
    dnf reinstall -y issabel-developer 2>/dev/null || dnf install -y issabel-developer 2>/dev/null || true
fi

# 2. Copia exatamente os arquivos corrigidos para /var/www/html/modules/
for MOD in build_module delete_module language_admin; do
    if [ -d "$REPO_DIR/src/modules/$MOD" ]; then
        rm -rf "/var/www/html/modules/$MOD"
        /bin/cp -rf "$REPO_DIR/src/modules/$MOD" /var/www/html/modules/
        chown -R asterisk:asterisk "/var/www/html/modules/$MOD"
        chmod -R 755 "/var/www/html/modules/$MOD"
    fi
done
log_success "Ferramentas Web Developer instaladas com os arquivos corrigidos."

# ==============================================================================
# 17. FAVICON E TEMAS (prisma_v5)
# ==============================================================================
log_info "17/20 - Instalando Favicon e Tema Prisma v5..."
if [ -f "$REPO_DIR/src/favicon.ico" ]; then
    cp -f "$REPO_DIR/src/favicon.ico" /var/www/html/favicon.ico
    mkdir -p /var/www/html/themes/tenant/images /var/www/html/themes/prisma_v5/images
    cp -f "$REPO_DIR/src/favicon.ico" /var/www/html/themes/tenant/images/favicon.ico 2>/dev/null || true
    cp -f "$REPO_DIR/src/favicon.ico" /var/www/html/themes/prisma_v5/images/favicon.ico 2>/dev/null || true
fi

if [ -d "$REPO_DIR/src/themes/prisma_v5" ]; then
    /bin/cp -rf "$REPO_DIR/src/themes/prisma_v5" /var/www/html/themes/
    chown -R asterisk:asterisk /var/www/html/themes
    log_success "Tema Prisma v5 e Favicon aplicados."
fi

# ==============================================================================
# 18. INSTALAÇÃO DE FERRAMENTAS DE DIAGNÓSTICO (NET-TOOLS, TCPDUMP & SNGREP)
# ==============================================================================
log_info "18/20 - Instalando ferramentas de diagnóstico e rede (net-tools, tcpdump & sngrep)..."
if command -v yum &>/dev/null; then
    yum install -y net-tools tcpdump 2>/dev/null || true
    if ! command -v sngrep &>/dev/null; then
        yum install -y epel-release 2>/dev/null || true
        if ! yum install -y sngrep 2>/dev/null; then
            printf "[irontec]\nname=Irontec RPMs repository\nbaseurl=http://packages.irontec.com/centos/\$releasever/\$basearch/\ngpgcheck=0\nenabled=1\n" > /etc/yum.repos.d/irontec.repo
            yum install -y sngrep 2>/dev/null || true
        fi
    fi
elif command -v dnf &>/dev/null; then
    dnf install -y net-tools tcpdump sngrep 2>/dev/null || true
fi
log_success "Ferramentas net-tools (ifconfig/netstat), tcpdump e sngrep instaladas."

# ==============================================================================
# 19. AJUSTES DE TEMPO E BIP DE TRANSFERÊNCIA
# ==============================================================================
log_info "19/20 - Aplicando ajustes de tempo de transferência e BIP no Asterisk..."
FEATURES_CUSTOM="/etc/asterisk/features_general_custom.conf"

if ! grep -q "transferdigittimeout" "$FEATURES_CUSTOM" 2>/dev/null; then
    if [ -f "$REPO_DIR/src/dialplan/features_general_custom.conf" ]; then
        cat "$REPO_DIR/src/dialplan/features_general_custom.conf" >> "$FEATURES_CUSTOM"
    else
        cat << 'EOF' >> "$FEATURES_CUSTOM"

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
    log_success "Ajustes de tempo de transferência e BIP aplicados em $FEATURES_CUSTOM."
else
    log_info "Ajustes de transferência já estão configurados."
fi

# ==============================================================================
# 20. ALTERAR USER-AGENT PJSIP (IPbx-Prisma)
# ==============================================================================
log_info "20/20 - Configurando User-Agent PJSIP para IPbx-Prisma..."
PJSIP_CONF="/etc/asterisk/pjsip.conf"
PJSIP_CUSTOM="/etc/asterisk/pjsip_custom.conf"

if [ -f "$PJSIP_CONF" ]; then
    if grep -q "^user_agent=" "$PJSIP_CONF" 2>/dev/null; then
        sed -i 's/^user_agent=.*/user_agent=IPbx-Prisma/' "$PJSIP_CONF"
    elif grep -q "\[global\]" "$PJSIP_CONF" 2>/dev/null; then
        sed -i '/\[global\]/a user_agent=IPbx-Prisma' "$PJSIP_CONF"
    else
        cat << 'EOF' >> "$PJSIP_CONF"

[global]
type=global
user_agent=IPbx-Prisma
EOF
    fi
fi

if [ -f "$PJSIP_CUSTOM" ]; then
    if ! grep -q "user_agent=IPbx-Prisma" "$PJSIP_CUSTOM" 2>/dev/null; then
        cat << 'EOF' >> "$PJSIP_CUSTOM"

[global]
type=global
user_agent=IPbx-Prisma
EOF
    fi
fi
log_success "User-Agent PJSIP configurado para IPbx-Prisma."

# ==============================================================================
# RECARGA DE SERVIÇOS E FINALIZAÇÃO
# ==============================================================================
log_info "Finalizando instalação e recarregando serviços..."
asterisk -rx "module reload" 2>/dev/null || asterisk -rx "core reload" 2>/dev/null || true

if systemctl is-active httpd &>/dev/null; then
    systemctl restart httpd
elif systemctl is-active apache2 &>/dev/null; then
    systemctl restart apache2
fi

echo -e "\n${GREEN}======================================================================${NC}"
echo -e "${GREEN}  INSTALAÇÃO DE CUSTOMIZAÇÕES DO ISSABEL CONCLUÍDA COM SUCESSO!     ${NC}"
echo -e "${GREEN}======================================================================${NC}"
echo -e "${CYAN}Todas as pastas nativas substituídas foram salvas com o sufixo '_old'${NC}"
echo -e "${CYAN}Exemplo: /var/www/html/admin_old, lang_old, modules_old, etc.${NC}\n"
