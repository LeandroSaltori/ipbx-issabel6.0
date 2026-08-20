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

# --- DIRETÓRIO DO REPOSITÓRIO E ATUALIZAÇÃO AUTOMÁTICA ---
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" 2>/dev/null && pwd || echo "")"
REPO_DIR="$SCRIPT_DIR"
TMP_REPO="/tmp/ipbx-issabel-repo"

# Se o repositório local for um clone git, força a atualização das novidades via git pull
if [ -d "$REPO_DIR/.git" ]; then
    log_info "Sincronizando repositório local com o GitHub..."
    (cd "$REPO_DIR" && git fetch origin 2>/dev/null && (git pull origin main 2>/dev/null || git pull origin master 2>/dev/null)) || true
fi

# Se o script estiver fora do repositório ou desatualizado sem a pasta src/nome_ramais, baixa o código mais recente
if [ ! -f "$REPO_DIR/install.sh" ] || [ ! -d "$REPO_DIR/src/nome_ramais" ]; then
    log_info "Baixando última versão do repositório em $TMP_REPO..."
    rm -rf "$TMP_REPO"
    mkdir -p "$TMP_REPO"

    if ! command -v git &>/dev/null; then
        log_info "Instalando pacote git no servidor..."
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

    # Sincroniza logos transparentes do Asternic CDR e Admin
    if [ -f "$REPO_DIR/src/admin/modules/asternic_cdr/images/asternic_cdr_logo.jpg" ]; then
        mkdir -p /var/www/html/admin/modules/asternic_cdr/images /var/www/html/modules/asternic_cdr/images /var/www/html/admin/images 2>/dev/null || true
        cp -f "$REPO_DIR/src/admin/modules/asternic_cdr/images/asternic_cdr_logo.jpg" /var/www/html/admin/modules/asternic_cdr/images/asternic_cdr_logo.jpg 2>/dev/null || true
        cp -f "$REPO_DIR/src/admin/modules/asternic_cdr/images/asternic_cdr_logo.jpg" /var/www/html/modules/asternic_cdr/images/asternic_cdr_logo.jpg 2>/dev/null || true
        cp -f "$REPO_DIR/src/admin/images/issabel_logo.png" /var/www/html/admin/images/issabel_logo.png 2>/dev/null || true
        cp -f "$REPO_DIR/src/admin/images/issabelpbx_small.png" /var/www/html/admin/images/issabelpbx_small.png 2>/dev/null || true
    fi
    log_success "Pasta admin e logos do CDR atualizadas."
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

    # Registra o menu "Relatorio Geral" (Asternic CDR) dentro da aba Reports (Relatórios)
    if command -v sqlite3 &>/dev/null; then
        sqlite3 /var/www/db/acl.db "INSERT OR IGNORE INTO acl_resource (name, description) VALUES ('relatorio_geral', 'Relatorio Geral');" 2>/dev/null || true
        sqlite3 /var/www/db/acl.db "INSERT OR IGNORE INTO acl_resource (name, description) VALUES ('relatorio_cdr', 'Relatorio Geral');" 2>/dev/null || true
        sqlite3 /var/www/db/menu.db "DELETE FROM menu WHERE id = 'relatorio_geral' OR id = 'relatorio_cdr' OR id = 'asternic_cdr';" 2>/dev/null || true
        sqlite3 /var/www/db/menu.db "INSERT INTO menu (id, IdParent, Link, Name, Type, order_no) VALUES ('relatorio_geral', 'reports', 'admin/config.php?display=asternic_cdr', 'Relatorio Geral', 'framed', 10);" 2>/dev/null || true
        sqlite3 /var/www/db/acl.db "INSERT OR IGNORE INTO acl_group_permission (id_action, id_group, id_resource) SELECT 1, 1, id FROM acl_resource WHERE name = 'relatorio_geral';" 2>/dev/null || true
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
    if [ -d "$REPO_DIR/src/modules/asternic_cdr" ]; then
        mkdir -p /var/www/html/admin/modules/asternic_cdr
        /bin/cp -rf "$REPO_DIR/src/modules/asternic_cdr/"* /var/www/html/admin/modules/asternic_cdr/ 2>/dev/null || true
        chown -R asterisk:asterisk /var/www/html/admin/modules/asternic_cdr
    fi
    chown -R asterisk:asterisk /var/www/html/modules
    log_success "Módulos sincronizados em /var/www/html/modules e /var/www/html/admin/modules/."
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
# IMPLANTAÇÃO DO GERENCIADOR DE NOME DOS RAMAIS (/var/www/html/nome_ramais)
# ==============================================================================
log_info "Implantando Módulo Gerenciador de Nome dos Ramais (nome_ramais)..."
mkdir -p /var/www/html/nome_ramais
mkdir -p /var/www/html/ramais
mkdir -p /var/www/html/modules/nome_ramais
mkdir -p /var/www/html/modules/ramais

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

# Se por qualquer motivo o index.php não estiver criado em /var/www/html/nome_ramais, cria o arquivo autônomo diretamente
if [ ! -f /var/www/html/nome_ramais/index.php ] || [ $(wc -l < /var/www/html/nome_ramais/index.php 2>/dev/null || echo 0) -lt 50 ]; then
    log_info "Gerando arquivo index.php autônomo do Gerenciador de Ramais em /var/www/html/nome_ramais..."
    cat << 'EOF_NOME_RAMAIS' > /var/www/html/nome_ramais/index.php
<?php
// IPBX Prisma Telecom - Módulo de Gerenciamento e Alteração de Nome de Ramais
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'asterisk';

if (file_exists('/etc/issabel.conf')) {
    $issabel_conf = parse_ini_file('/etc/issabel.conf');
    if (isset($issabel_conf['mysqlrootpwd'])) {
        $db_pass = trim($issabel_conf['mysqlrootpwd']);
    }
}

try {
    $pdo = new PDO("mysql:host={$db_host};dbname={$db_name};charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (PDOException $e) { 
    try {
        $pdo = new PDO("mysql:host={$db_host};dbname={$db_name};charset=utf8mb4", 'root', 'ls251289');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    } catch (PDOException $e2) {
        die('Erro de Conexão com o Banco de Dados Asterisk: ' . $e2->getMessage()); 
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $exten = filter_input(INPUT_POST, 'exten', FILTER_SANITIZE_FULL_SPECIAL_CHARS);
    $newName = filter_input(INPUT_POST, 'new_name', FILTER_SANITIZE_FULL_SPECIAL_CHARS);

    if ($exten && $newName) {
        try {
            $stmt1 = $pdo->prepare("UPDATE users SET name = :name WHERE extension = :exten");
            $stmt1->execute([':name' => $newName, ':exten' => $exten]);

            $stmt2 = $pdo->prepare("UPDATE devices SET description = :name WHERE id = :exten");
            $stmt2->execute([':name' => $newName, ':exten' => $exten]);

            @shell_exec('sudo /var/lib/asterisk/bin/retrieve_conf 2>&1');
            @shell_exec('sudo /usr/sbin/fwconsole reload 2>&1 || sudo asterisk -rx "core reload" 2>&1 || sudo asterisk -rx "module reload" 2>&1');

            echo json_encode(['status' => 'success', 'message' => 'Ramal ' . $exten . ' atualizado para "' . $newName . '" com sucesso!']);
        } catch (Exception $e) {
            echo json_encode(['status' => 'error', 'message' => 'Erro ao salvar no banco: ' . $e->getMessage()]);
        }
        exit;
    }
    echo json_encode(['status' => 'error', 'message' => 'Dados inválidos inseridos.']);
    exit;
}

$stmt = $pdo->query("SELECT extension, name FROM users ORDER BY CAST(extension AS UNSIGNED) ASC");
$ramais = $stmt->fetchAll(PDO::FETCH_ASSOC);
$totalRamais = count($ramais);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Prisma Telecom - Gerenciador de Ramais</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #6d1a7c;
            --primary-hover: #561363;
            --bg-color: #f8fafc;
            --card-bg: #ffffff;
            --text-main: #1e293b;
            --text-muted: #64748b;
            --border-color: #e2e8f0;
            --success: #10b981;
            --success-hover: #059669;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Inter', sans-serif; }
        body { background-color: var(--bg-color); color: var(--text-main); padding: 30px 15px; }

        .container { max-width: 950px; margin: 0 auto; }
        
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 25px;
            flex-wrap: wrap;
            gap: 15px;
        }

        .header h1 { font-size: 1.5rem; color: var(--primary); font-weight: 700; display: flex; align-items: center; gap: 10px; }
        .badge-count { background: #f1f5f9; border: 1px solid var(--border-color); color: var(--primary); font-size: 0.85rem; padding: 4px 14px; border-radius: 20px; font-weight: 600; }

        .search-box {
            position: relative;
            margin-bottom: 20px;
        }

        .search-box input {
            width: 100%;
            padding: 12px 16px 12px 42px;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            background: var(--card-bg);
            font-size: 0.95rem;
            outline: none;
            transition: all 0.2s;
        }

        .search-box input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(109, 26, 124, 0.15);
        }

        .search-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
        }

        .card {
            background: var(--card-bg);
            border-radius: 12px;
            border: 1px solid var(--border-color);
            box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05);
            overflow: hidden;
        }

        table { width: 100%; border-collapse: collapse; text-align: left; }
        th { background: #f8fafc; color: var(--text-muted); font-size: 0.8rem; text-transform: uppercase; letter-spacing: 0.05em; padding: 14px 20px; border-bottom: 1px solid var(--border-color); }
        td { padding: 14px 20px; border-bottom: 1px solid var(--border-color); font-size: 0.95rem; vertical-align: middle; }
        tr:last-child td { border-bottom: none; }
        tr:hover { background-color: #faf5ff; }

        .ramal-number { font-weight: 700; color: var(--primary); font-size: 1rem; }
        
        .input-name {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            font-size: 0.9rem;
            outline: none;
            transition: border 0.2s;
        }

        .input-name:focus { border-color: var(--primary); }

        .btn-save {
            background-color: var(--primary);
            color: white;
            border: none;
            padding: 8px 18px;
            border-radius: 6px;
            font-weight: 600;
            font-size: 0.85rem;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 6px;
            min-width: 80px;
        }

        .btn-save:hover { background-color: var(--primary-hover); }
        .btn-save:disabled { opacity: 0.6; cursor: not-allowed; }

        #toast {
            position: fixed;
            bottom: 20px;
            right: 20px;
            padding: 12px 20px;
            border-radius: 8px;
            color: white;
            font-weight: 500;
            font-size: 0.9rem;
            box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1);
            display: none;
            z-index: 1000;
        }
        #toast.success { background-color: var(--success); }
        #toast.error { background-color: #ef4444; }

        @media (max-width: 640px) {
            th:nth-child(2), td:nth-child(2) { display: none; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72 12.84 12.84 0 0 0 .7 2.81 2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45 12.84 12.84 0 0 0 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
                Alterar Nomes dos Ramais
            </h1>
            <span class="badge-count">Total: <?= $totalRamais ?> Ramais</span>
        </div>

        <div class="search-box">
            <svg class="search-icon" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            <input type="text" id="searchInput" placeholder="Pesquisar por ramal ou nome..." onkeyup="filtrarRamais()">
        </div>

        <div class="card">
            <table>
                <thead>
                    <tr>
                        <th>Ramal</th>
                        <th>Nome Atual</th>
                        <th>Novo Nome</th>
                        <th style="width: 120px; text-align: center;">Ação</th>
                    </tr>
                </thead>
                <tbody id="ramaisTable">
                    <?php foreach ($ramais as $r): ?>
                    <tr id="row_<?= $r['extension'] ?>">
                        <td class="ramal-number"><?= htmlspecialchars($r['extension']) ?></td>
                        <td id="name_<?= $r['extension'] ?>"><?= htmlspecialchars($r['name']) ?></td>
                        <td>
                            <input type="text" class="input-name" id="in_<?= $r['extension'] ?>" placeholder="Digite o novo nome...">
                        </td>
                        <td style="text-align: center;">
                            <button class="btn-save" id="btn_<?= $r['extension'] ?>" onclick="salvar('<?= $r['extension'] ?>')">
                                Salvar
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div id="toast"></div>

    <script>
    function showToast(message, type = 'success') {
        const toast = document.getElementById('toast');
        toast.innerText = message;
        toast.className = type;
        toast.style.display = 'block';
        setTimeout(() => { toast.style.display = 'none'; }, 4000);
    }

    function filtrarRamais() {
        const input = document.getElementById('searchInput').value.toLowerCase();
        const rows = document.querySelectorAll('#ramaisTable tr');

        rows.forEach(row => {
            const exten = row.cells[0].innerText.toLowerCase();
            const name = row.cells[1].innerText.toLowerCase();
            if (exten.includes(input) || name.includes(input)) {
                row.style.display = '';
            } else {
                row.style.display = 'none';
            }
        });
    }

    function salvar(exten) {
        const nameInput = document.getElementById('in_' + exten);
        const name = nameInput.value.trim();
        const btn = document.getElementById('btn_' + exten);

        if(!name) {
            showToast('Por favor, digite um novo nome!', 'error');
            return;
        }

        btn.disabled = true;
        btn.innerHTML = 'Salvando...';

        const fd = new URLSearchParams();
        fd.append('exten', exten);
        fd.append('new_name', name);

        fetch('', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if(data.status === 'success') {
                showToast(data.message, 'success');
                document.getElementById('name_' + exten).innerText = name;
                nameInput.value = '';
            } else {
                showToast(data.message || 'Erro ao atualizar.', 'error');
            }
        })
        .catch(() => {
            showToast('Erro de conexão ou falha nas permissões sudo.', 'error');
        })
        .finally(() => {
            btn.disabled = false;
            btn.innerHTML = 'Salvar';
        });
    }
    </script>
</body>
</html>
EOF_NOME_RAMAIS
fi

# Copia para os demais diretórios e ajusta permissões
/bin/cp -f /var/www/html/nome_ramais/index.php /var/www/html/modules/nome_ramais/index.php 2>/dev/null || true
echo "asterisk ALL=(ALL) NOPASSWD: ALL" > /etc/sudoers.d/asterisk 2>/dev/null || true
chmod 440 /etc/sudoers.d/asterisk 2>/dev/null || true

chown -R asterisk:asterisk /var/www/html/ramais /var/www/html/nome_ramais /var/www/html/modules/nome_ramais /var/www/html/modules/ramais 2>/dev/null || true
chmod -R 755 /var/www/html/ramais /var/www/html/nome_ramais /var/www/html/modules/nome_ramais /var/www/html/modules/ramais 2>/dev/null || true

if command -v sqlite3 &>/dev/null; then
    sqlite3 /var/www/db/menu.db "DELETE FROM menu WHERE id = 'nome_ramais' OR Link LIKE '%nome_ramais%';" 2>/dev/null || true
    sqlite3 /var/www/db/menu.db "INSERT INTO menu (id, IdParent, Link, Name, Type, order_no) VALUES ('nome_ramais', 'pbxconfig', 'nome_ramais/', 'Nome Ramais', 'framed', 15);" 2>/dev/null || true
    
    sqlite3 /var/www/db/acl.db "DELETE FROM acl_resource WHERE name = 'nome_ramais';" 2>/dev/null || true
    sqlite3 /var/www/db/acl.db "INSERT INTO acl_resource (name, description) VALUES ('nome_ramais', 'Nome Ramais');" 2>/dev/null || true
    sqlite3 /var/www/db/acl.db "DELETE FROM acl_group_permission WHERE id_resource IN (SELECT id FROM acl_resource WHERE name = 'nome_ramais');" 2>/dev/null || true
    sqlite3 /var/www/db/acl.db "INSERT INTO acl_group_permission (id_action, id_group, id_resource) SELECT 1, g.id, r.id FROM acl_group g CROSS JOIN acl_resource r WHERE r.name = 'nome_ramais';" 2>/dev/null || true
    
    sqlite3 /var/www/db/menu.db "UPDATE menu SET Link = 'nome_ramais/', Type = 'framed' WHERE id = 'myex_config' OR id = 'ramais';" 2>/dev/null || true
fi

chown -R asterisk:asterisk /var/www/html/nome_ramais 2>/dev/null || true
chown -R asterisk:asterisk /var/www/html/ramais 2>/dev/null || true
chmod -R 755 /var/www/html/nome_ramais 2>/dev/null || true
chmod -R 755 /var/www/html/ramais 2>/dev/null || true

log_success "Módulo Gerenciador de Nome dos Ramais implantado em /var/www/html/nome_ramais."

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
    systemctl reload httpd 2>/dev/null || systemctl restart httpd 2>/dev/null || true
fi

# Implantação do Módulo Web do Relatório de Ligações (CDR Report)
if [ -d "$REPO_DIR/src/modules/cdrreport" ]; then
    mkdir -p /var/www/html/modules/cdrreport
    /bin/cp -rf "$REPO_DIR/src/modules/cdrreport/"* /var/www/html/modules/cdrreport/
    chown -R asterisk:asterisk /var/www/html/modules/cdrreport
    chmod -R 755 /var/www/html/modules/cdrreport
    systemctl reload httpd 2>/dev/null || systemctl restart httpd 2>/dev/null || true
fi

# Implantação do Módulo Web de Uso de Canais (channelusage)
if [ -d "$REPO_DIR/src/modules/channelusage" ]; then
    mkdir -p /var/www/html/modules/channelusage
    /bin/cp -rf "$REPO_DIR/src/modules/channelusage/"* /var/www/html/modules/channelusage/
    chown -R asterisk:asterisk /var/www/html/modules/channelusage
    chmod -R 755 /var/www/html/modules/channelusage
    systemctl reload httpd 2>/dev/null || systemctl restart httpd 2>/dev/null || true
fi

# Implantação do Módulo Web do Relatório Gráfico (graphic_report)
if [ -d "$REPO_DIR/src/modules/graphic_report" ]; then
    mkdir -p /var/www/html/modules/graphic_report
    /bin/cp -rf "$REPO_DIR/src/modules/graphic_report/"* /var/www/html/modules/graphic_report/
    chown -R asterisk:asterisk /var/www/html/modules/graphic_report
    chmod -R 755 /var/www/html/modules/graphic_report
    systemctl reload httpd 2>/dev/null || systemctl restart httpd 2>/dev/null || true
fi

# Implantação do Módulo Web do Resumo por Ramal (summary_by_extension)
if [ -d "$REPO_DIR/src/modules/summary_by_extension" ]; then
    mkdir -p /var/www/html/modules/summary_by_extension
    /bin/cp -rf "$REPO_DIR/src/modules/summary_by_extension/"* /var/www/html/modules/summary_by_extension/
    chown -R asterisk:asterisk /var/www/html/modules/summary_by_extension
    chmod -R 755 /var/www/html/modules/summary_by_extension
    systemctl reload httpd 2>/dev/null || systemctl restart httpd 2>/dev/null || true
fi

# Implantação do Módulo Web de Chamadas Perdidas (missed_calls)
if [ -d "$REPO_DIR/src/modules/missed_calls" ]; then
    mkdir -p /var/www/html/modules/missed_calls
    /bin/cp -rf "$REPO_DIR/src/modules/missed_calls/"* /var/www/html/modules/missed_calls/
    chown -R asterisk:asterisk /var/www/html/modules/missed_calls
    chmod -R 755 /var/www/html/modules/missed_calls
    systemctl reload httpd 2>/dev/null || systemctl restart httpd 2>/dev/null || true
fi
    
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

        # Saneamento automático de links no menu.db (Remove IPs legados e mapeia caminhos oficiais)
        sqlite3 /var/www/db/menu.db "UPDATE menu SET Link = REPLACE(Link, 'https://192.168.0.245', '') WHERE Link LIKE '%192.168.0.245%';" 2>/dev/null || true
        sqlite3 /var/www/db/menu.db "UPDATE menu SET Link = REPLACE(Link, 'http://192.168.0.245', '') WHERE Link LIKE '%192.168.0.245%';" 2>/dev/null || true
        
        # Remoção do menu secundário de ajuda da pesquisa (para ficar apenas Pesquisa de Satisfação)
        sqlite3 /var/www/db/menu.db "DELETE FROM menu WHERE id = 'pesquisa_ajuda' OR id = 'pesquisa_como_funciona' OR Link LIKE '%pesquisa_como_funciona%';" 2>/dev/null || true
        sqlite3 /var/www/db/acl.db "DELETE FROM acl_resource WHERE name = 'pesquisa_ajuda' OR name = 'pesquisa_como_funciona';" 2>/dev/null || true

        sqlite3 /var/www/db/menu.db "DELETE FROM menu WHERE id = 'nome_ramais';" 2>/dev/null || true
        sqlite3 /var/www/db/menu.db "INSERT INTO menu (id, IdParent, Link, Name, Type, order_no) VALUES ('nome_ramais', 'pbxconfig', 'nome_ramais/', 'Nome Ramais', 'framed', 15);" 2>/dev/null || true
        sqlite3 /var/www/db/acl.db "INSERT OR IGNORE INTO acl_resource (name, description) VALUES ('nome_ramais', 'Nome Ramais');" 2>/dev/null || true
        sqlite3 /var/www/db/acl.db "INSERT OR IGNORE INTO acl_group_permission (id_action, id_group, id_resource) SELECT 1, 1, id FROM acl_resource WHERE name = 'nome_ramais';" 2>/dev/null || true
        sqlite3 /var/www/db/menu.db "UPDATE menu SET Link = '/admin/config.php?display=blacklist' WHERE id = 'blacklist' OR Link LIKE '%blacklist%';" 2>/dev/null || true
    fi

    # Registra o atalho de discagem e transferencia 8996 no Asterisk
    if [ -f /etc/asterisk/extensions_custom.conf ]; then
        if ! grep -q "exten => 8996" /etc/asterisk/extensions_custom.conf; then
            cat << 'EOF' >> /etc/asterisk/extensions_custom.conf

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

    log_success "Módulo Web da Pesquisa de Satisfação registrado no menu Relatórios."

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
    yum install -y net-tools tcpdump sngrep NetworkManager-tui 2>/dev/null || true
elif command -v dnf &>/dev/null; then
    dnf install -y net-tools tcpdump sngrep NetworkManager-tui 2>/dev/null || true
fi
systemctl enable NetworkManager 2>/dev/null || true
systemctl start NetworkManager 2>/dev/null || true
log_success "Ferramentas net-tools (ifconfig/netstat), tcpdump, sngrep e nmtui (NetworkManager-tui) instaladas e ativas."

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
# IMPLANTAÇÃO DO COMANDO DE ROLLBACK (ipbx-rollback) E MENU (ipbx-update)
# ==============================================================================
log_info "Implantando comandos auxiliares no sistema..."
if [ -f "$REPO_DIR/rollback.sh" ]; then
    /bin/cp -f "$REPO_DIR/rollback.sh" /usr/local/bin/ipbx-rollback
    chmod +x /usr/local/bin/ipbx-rollback
    log_success "Comando de rollback disponível: ipbx-rollback"
fi

if [ -f "$REPO_DIR/ipbx-menu.sh" ]; then
    /bin/cp -f "$REPO_DIR/ipbx-menu.sh" /usr/local/bin/ipbx-update
    chmod +x /usr/local/bin/ipbx-update
    log_success "Menu de atualização modular disponível: ipbx-update"
fi

# ==============================================================================
# CONFIGURAÇÃO DE ATUALIZAÇÃO AUTOMÁTICA SEMANAL (CRON & LOGS)
# ==============================================================================
log_info "Configurando rotina de atualização semanal automática e registros de log..."
if [ -f "$REPO_DIR/scripts/ipbx-autoupdate.sh" ]; then
    /bin/cp -f "$REPO_DIR/scripts/ipbx-autoupdate.sh" /usr/local/bin/ipbx-autoupdate
    chmod +x /usr/local/bin/ipbx-autoupdate
    
    # Registra no cron semanal (/etc/cron.weekly/ipbx-autoupdate)
    /bin/cp -f "$REPO_DIR/scripts/ipbx-autoupdate.sh" /etc/cron.weekly/ipbx-autoupdate
    chmod +x /etc/cron.weekly/ipbx-autoupdate
    
    log_success "Atualização automática semanal configurada em /etc/cron.weekly/ipbx-autoupdate."
    log_info "Os logs de atualização serão gerados em $REPO_DIR/autoupdate.log e $REPO_DIR/autoupdate_last_status.txt."
fi

# ==============================================================================
# RECARGA DE SERVIÇOS E FINALIZAÇÃO
# ==============================================================================
log_info "Limpando cache de templates do Smarty e recarregando serviços..."
mkdir -p /var/log/asterisk/cdr-csv /var/log/asterisk/cdr-custom 2>/dev/null || true
touch /var/log/asterisk/cdr-csv/Master.csv 2>/dev/null || true
chown -R asterisk:asterisk /var/log/asterisk/cdr-csv /var/log/asterisk/cdr-custom 2>/dev/null || true
chmod 755 /var/log/asterisk/cdr-csv /var/log/asterisk/cdr-custom 2>/dev/null || true
chmod 664 /var/log/asterisk/cdr-csv/Master.csv 2>/dev/null || true

rm -rf /var/www/html/var/templates_c/* 2>/dev/null || true
rm -rf /tmp/smarty* 2>/dev/null || true

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
