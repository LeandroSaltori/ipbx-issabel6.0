#!/bin/bash
# ==============================================================================
# Script de Instalação Automatizada - Relatório de Filas IPbx Prisma (Issabel 4 e 5)
# Zero dependências externas quebradas / 100% autônomo com PHP 5.4 até 8.3
# ==============================================================================

if [ "$EUID" -ne 0 ]; then
  echo "[-] Erro: Por favor, execute este script como root (sudo su)."
  exit 1
fi

echo "=========================================================="
echo "  Instalador Automático - Relatório de Filas IPbx Prisma"
echo "=========================================================="

# 1. Leitura da senha root do MySQL
MYSQL_PWD=""
if [ -f /etc/issabel.conf ]; then
  MYSQL_PWD=$(grep -i mysqlrootpwd /etc/issabel.conf 2>/dev/null | cut -d'=' -f2 | tr -d ' ')
elif [ -f /etc/amportal.conf ]; then
  MYSQL_PWD=$(grep -i AMPDBPASS /etc/amportal.conf 2>/dev/null | cut -d'=' -f2 | tr -d ' ')
fi

# 2. Instalação de dependências essenciais
echo "[+] Verificando dependências básicas..."
if command -v dnf &> /dev/null; then
  dnf install -y git sox lame wget sqlite &> /dev/null || true
elif command -v yum &> /dev/null; then
  yum install -y git sox lame wget sqlite &> /dev/null || true
fi

# 3. Garantir que o Asterisk registre logs de fila (queue_log = yes)
if [ -d /etc/asterisk ]; then
  LOGGER_CUSTOM="/etc/asterisk/logger_general_custom.conf"
  if [ -f "$LOGGER_CUSTOM" ]; then
    if ! grep -qi "queue_log" "$LOGGER_CUSTOM"; then
      echo "queue_log = yes" >> "$LOGGER_CUSTOM"
      asterisk -rx "logger reload" 2>/dev/null || true
    fi
  elif [ -f /etc/asterisk/logger.conf ]; then
    if ! grep -qi "queue_log\s*=\s*yes" /etc/asterisk/logger.conf; then
      echo "queue_log = yes" >> /etc/asterisk/logger_general_custom.conf 2>/dev/null || true
      asterisk -rx "logger reload" 2>/dev/null || true
    fi
  fi
fi

# 4. Criação e Saneamento do Banco de Dados qstatslite
echo "[+] Configurando banco de dados MariaDB/MySQL (qstatslite)..."
if [ -n "$MYSQL_PWD" ]; then
  MYSQL_EXEC="mysql -u root -p$MYSQL_PWD"
else
  MYSQL_EXEC="mysql -u root"
fi

$MYSQL_EXEC -e "CREATE DATABASE IF NOT EXISTS qstatslite DEFAULT CHARACTER SET utf8 COLLATE utf8_general_ci;" 2>/dev/null || true

# Schema completo com queue_stats_id AUTO_INCREMENT PRIMARY KEY
$MYSQL_EXEC qstatslite 2>/dev/null << 'EOF' || true
CREATE TABLE IF NOT EXISTS `qname` (
  `qname_id` int(11) NOT NULL AUTO_INCREMENT,
  `queue` varchar(50) NOT NULL DEFAULT '',
  PRIMARY KEY (`qname_id`),
  UNIQUE KEY `idx_queue` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `qagent` (
  `agent_id` int(11) NOT NULL AUTO_INCREMENT,
  `agent` varchar(50) NOT NULL DEFAULT '',
  PRIMARY KEY (`agent_id`),
  UNIQUE KEY `idx_agent` (`agent`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `qevent` (
  `event_id` int(11) NOT NULL,
  `event` varchar(50) NOT NULL DEFAULT '',
  PRIMARY KEY (`event_id`),
  UNIQUE KEY `idx_event` (`event`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

CREATE TABLE IF NOT EXISTS `queue_stats` (
  `queue_stats_id` int(12) NOT NULL AUTO_INCREMENT,
  `uniqueid` varchar(40) NOT NULL DEFAULT '',
  `datetime` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
  `qname` int(11) NOT NULL DEFAULT '0',
  `qagent` int(11) NOT NULL DEFAULT '0',
  `qevent` int(11) NOT NULL DEFAULT '0',
  `info1` varchar(100) NOT NULL DEFAULT '',
  `info2` varchar(100) NOT NULL DEFAULT '',
  `info3` varchar(100) NOT NULL DEFAULT '',
  `info4` varchar(100) NOT NULL DEFAULT '',
  `info5` varchar(100) NOT NULL DEFAULT '',
  PRIMARY KEY (`queue_stats_id`),
  KEY `idx_dt` (`datetime`),
  KEY `idx_uid` (`uniqueid`),
  KEY `idx_qn` (`qname`),
  KEY `idx_qa` (`qagent`),
  KEY `idx_qe` (`qevent`),
  UNIQUE KEY `unico` (`uniqueid`, `datetime`, `qname`, `qagent`, `qevent`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;

-- Seed de eventos obrigatórios para tradução correta no relatório
INSERT IGNORE INTO `qevent` (`event_id`, `event`) VALUES
(1,'ABANDON'),(2,'AGENTDUMP'),(3,'AGENTLOGIN'),(4,'AGENTCALLBACKLOGIN'),
(5,'AGENTLOGOFF'),(6,'AGENTCALLBACKLOGOFF'),(7,'COMPLETEAGENT'),(8,'COMPLETECALLER'),
(9,'CONFIGRELOAD'),(10,'CONNECT'),(11,'ENTERQUEUE'),(12,'EXITWITHKEY'),
(13,'EXITWITHTIMEOUT'),(14,'QUEUESTART'),(15,'SYSCOMPAT'),(16,'TRANSFER'),
(17,'PAUSE'),(18,'UNPAUSE'),(19,'RINGNOANSWER'),(20,'EXITEMPTY'),
(21,'PAUSEALL'),(22,'UNPAUSEALL');

-- Inserir registros neutros padrão
INSERT IGNORE INTO `qname` (`queue`) VALUES ('NONE');
INSERT IGNORE INTO `qagent` (`agent`) VALUES ('NONE');
EOF

# Autocura de colunas caso a tabela já existisse no schema antigo sem primary key
$MYSQL_EXEC qstatslite -e "
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='qstatslite' AND TABLE_NAME='queue_stats' AND COLUMN_NAME='queue_stats_id');
SET @alter_sql = IF(@col_exists=0, 'ALTER TABLE queue_stats ADD COLUMN queue_stats_id INT(12) NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST', 'SELECT 1');
PREPARE stmt FROM @alter_sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
" 2>/dev/null || true

# 5. Instalação do Parser de Log Moderno (parselog.php)
echo "[+] Configurando motor de processamento de filas (parselog.php)..."
mkdir -p /usr/local/parselog

# Localiza arquivo parselog.php no diretório do script atual ou no repositório
CURRENT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" 2>/dev/null && pwd)"
PARSER_INSTALLED=false

if [ -f "$CURRENT_DIR/parselog/parselog.php" ]; then
  /bin/cp -f "$CURRENT_DIR/parselog/parselog.php" /usr/local/parselog/parselog.php
  PARSER_INSTALLED=true
elif [ -f "$CURRENT_DIR/parselog.php" ]; then
  /bin/cp -f "$CURRENT_DIR/parselog/parselog.php" /usr/local/parselog/parselog.php
  PARSER_INSTALLED=true
fi

# Se foi executado via curl pipe, baixa diretamente do repositório GitHub
if [ "$PARSER_INSTALLED" = false ]; then
  curl -sSL "https://raw.githubusercontent.com/LeandroSaltori/ipbx-issabel6.0/main/src/modules/relatorio_de_filas/parselog/parselog.php" -o /usr/local/parselog/parselog.php 2>/dev/null || \
  wget -q "https://raw.githubusercontent.com/LeandroSaltori/ipbx-issabel6.0/main/src/modules/relatorio_de_filas/parselog/parselog.php" -O /usr/local/parselog/parselog.php 2>/dev/null || true
fi

chmod +x /usr/local/parselog/parselog.php 2>/dev/null || true

# Agendamento no Crontab para execução minuto a minuto
if ! crontab -l 2>/dev/null | grep -q "parselog.php"; then
  (crontab -l 2>/dev/null; echo "* * * * * /usr/bin/php /usr/local/parselog/parselog.php > /dev/null 2>&1") | crontab -
  echo "[✓] Parser agendado no crontab com sucesso."
fi

# Executa imediatamente carga de logs existentes e sincronização de filas
echo "[+] Processando logs existentes do Asterisk..."
/usr/bin/php /usr/local/parselog/parselog.php all 2>/dev/null || true

# 6. Implantação da Interface Web
echo "[+] Instalando arquivos do Relatório de Filas..."
TARGET_DIR="/var/www/html/Relatorio_de_filas"
mkdir -p "$TARGET_DIR" /var/www/html/relatorio_de_filas /var/www/html/modules/relatorio_de_filas

if [ -f "$CURRENT_DIR/index.php" ]; then
  /bin/cp -rf "$CURRENT_DIR/"* "$TARGET_DIR/" 2>/dev/null || true
  /bin/cp -rf "$CURRENT_DIR/"* "/var/www/html/relatorio_de_filas/" 2>/dev/null || true
  /bin/cp -rf "$CURRENT_DIR/"* "/var/www/html/modules/relatorio_de_filas/" 2>/dev/null || true
else
  TMP_REPO="/tmp/ipbx-filas-install"
  rm -rf "$TMP_REPO"
  git clone --depth 1 https://github.com/LeandroSaltori/ipbx-issabel6.0.git "$TMP_REPO" &>/dev/null || true
  if [ -d "$TMP_REPO/src/modules/relatorio_de_filas" ]; then
    /bin/cp -rf "$TMP_REPO/src/modules/relatorio_de_filas/"* "$TARGET_DIR/" 2>/dev/null || true
    /bin/cp -rf "$TMP_REPO/src/modules/relatorio_de_filas/"* "/var/www/html/relatorio_de_filas/" 2>/dev/null || true
    /bin/cp -rf "$TMP_REPO/src/modules/relatorio_de_filas/"* "/var/www/html/modules/relatorio_de_filas/" 2>/dev/null || true
  fi
  rm -rf "$TMP_REPO"
fi

# Permissões
chown -R asterisk:asterisk "$TARGET_DIR" /var/www/html/relatorio_de_filas /var/www/html/modules/relatorio_de_filas /usr/local/parselog
chmod -R 755 "$TARGET_DIR" /var/www/html/relatorio_de_filas /var/www/html/modules/relatorio_de_filas /usr/local/parselog

# 7. Registro do Menu no Issabel (relatorio_de_filas e relatorio_filas)
if command -v sqlite3 &>/dev/null; then
  echo "[+] Registrando menus e permissões de acesso..."
  for MENU_ID in "relatorio_de_filas" "relatorio_filas"; do
    sqlite3 /var/www/db/acl.db "INSERT OR IGNORE INTO acl_resource (name, description) VALUES ('$MENU_ID', 'Relatório de Filas');" 2>/dev/null || true
    sqlite3 /var/www/db/menu.db "DELETE FROM menu WHERE id = '$MENU_ID';" 2>/dev/null || true
    sqlite3 /var/www/db/menu.db "INSERT INTO menu (id, IdParent, Link, Name, Type, order_no) VALUES ('$MENU_ID', 'reports', 'Relatorio_de_filas/', 'Relatório de Filas', 'framed', 9);" 2>/dev/null || true
    sqlite3 /var/www/db/acl.db "INSERT OR IGNORE INTO acl_group_permission (id_action, id_group, id_resource) SELECT 1, 1, id FROM acl_resource WHERE name = '$MENU_ID';" 2>/dev/null || true
  done
fi

IP_LOCAL=$(hostname -I 2>/dev/null | awk '{print $1}')
if [ -z "$IP_LOCAL" ]; then
  IP_LOCAL="IP_DO_SEU_SERVIDOR"
fi

echo "=========================================================="
echo " [✓] Instalação concluída com sucesso!"
echo ""
echo " O Relatório de Filas foi integrado ao menu 'Relatórios' do Issabel."
echo " Acesso direto: http://$IP_LOCAL/Relatorio_de_filas/"
echo "=========================================================="
