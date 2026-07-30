#!/bin/bash
# ==============================================================================
# Script de Instalação Automatizada - Relatório de Filas IPbx Prisma (Issabel 4)
# ==============================================================================

# Verifica se o usuário é root
if [ "$EUID" -ne 0 ]; then
  echo "[-] Erro: Por favor, execute este script como root (sudo su)."
  exit 1
fi

echo "=========================================================="
echo "  Instalador Automático - Relatório de Filas IPbx Prisma"
echo "=========================================================="

# 1. Leitura da senha root do MySQL no /etc/issabel.conf
MYSQL_PWD=""
if [ -f /etc/issabel.conf ]; then
  MYSQL_PWD=$(grep -i mysqlrootpwd /etc/issabel.conf | cut -d'=' -f2 | tr -d ' ')
fi

# 2. Instalação de dependências essenciais (git, sox, lame, etc. via yum/dnf)
echo "[+] Instalando dependências (git, sox, lame)..."
if command -v yum &> /dev/null; then
  yum install -y git sox lame wget tar &> /dev/null
elif command -v dnf &> /dev/null; then
  dnf install -y git sox lame wget tar &> /dev/null
fi

# 3. Verificação e Criação do Banco de Dados qstatslite
echo "[+] Verificando banco de dados MariaDB/MySQL (qstatslite)..."
DB_EXISTS=$(mysql -u root -p"$MYSQL_PWD" -e "SHOW DATABASES LIKE 'qstatslite';" 2>/dev/null | grep qstatslite)

if [ -z "$DB_EXISTS" ]; then
  echo "[+] Criando banco de dados qstatslite e tabelas de estatísticas..."
  mysql -u root -p"$MYSQL_PWD" -e "CREATE DATABASE IF NOT EXISTS qstatslite DEFAULT CHARACTER SET utf8 COLLATE utf8_general_ci;" 2>/dev/null

  # Schema de tabelas do qstatslite
  mysql -u root -p"$MYSQL_PWD" qstatslite << 'EOF'
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
  echo "[+] Banco qstatslite configurado com sucesso."
else
  echo "[+] Banco de dados qstatslite já existe."
fi

# 4. Verificação/Instalação do parser de log (parselog.php / Asternic Lite)
if [ ! -f /usr/local/parselog/parselog.php ] && [ ! -f /var/www/html/stats/parselog.php ]; then
  echo "[+] Baixando componentes do Asternic Lite para processamento de logs (parselog.php)..."
  cd /tmp
  rm -rf asternic-stats*
  wget -q http://download.asternic.net/asternic-stats-1.5.tar.gz -O asternic-stats-1.5.tar.gz 2>/dev/null
  if [ -f asternic-stats-1.5.tar.gz ]; then
    tar -zxf asternic-stats-1.5.tar.gz
    mkdir -p /usr/local/parselog
    if [ -f asternic-stats/parselog.php ]; then
      cp -f asternic-stats/parselog.php /usr/local/parselog/
    elif [ -f asternic-stats/html/parselog.php ]; then
      cp -f asternic-stats/html/parselog.php /usr/local/parselog/
    fi
    
    # Ajusta credencial no parselog.php
    if [ -f /usr/local/parselog/parselog.php ]; then
      sed -i "s/\$dbuser = .*/\$dbuser = 'root';/" /usr/local/parselog/parselog.php
      sed -i "s/\$dbpass = .*/\$dbpass = '$MYSQL_PWD';/" /usr/local/parselog/parselog.php
    fi

    # Adiciona no Crontab para atualizar estatísticas a cada minuto
    if ! crontab -l 2>/dev/null | grep -q "parselog.php"; then
      (crontab -l 2>/dev/null; echo "* * * * * php /usr/local/parselog/parselog.php > /dev/null 2>&1") | crontab -
    fi

    # Roda uma vez para processar logs atuais
    php /usr/local/parselog/parselog.php &>/dev/null || true
  fi
fi

# 5. Baixar e atualizar a pasta Relatorio_de_filas do GitHub
echo "[+] Baixando os arquivos do Relatório de Filas do GitHub..."
TMP_REPO="/tmp/ipbx-issabel4-install"
rm -rf "$TMP_REPO"
git clone --depth 1 https://github.com/LeandroSaltori/ipbx-issabel5.git "$TMP_REPO" &>/dev/null

TARGET_DIR="/var/www/html/Relatorio_de_filas"
mkdir -p "$TARGET_DIR"

if [ -d "$TMP_REPO/Relatorio_queue_stats/issabel 4/Relatorio_de_filas" ]; then
  cp -rf "$TMP_REPO/Relatorio_queue_stats/issabel 4/Relatorio_de_filas/"* "$TARGET_DIR/"
elif [ -d "$TMP_REPO/Relatorio_queue_stats/issabel 4/Relarorio_de_filas" ]; then
  cp -rf "$TMP_REPO/Relatorio_queue_stats/issabel 4/Relarorio_de_filas/"* "$TARGET_DIR/"
elif [ -d "$TMP_REPO/issabel 4/Relatorio_de_filas" ]; then
  cp -rf "$TMP_REPO/issabel 4/Relatorio_de_filas/"* "$TARGET_DIR/"
elif [ -d "$TMP_REPO/issabel 4/Relarorio_de_filas" ]; then
  cp -rf "$TMP_REPO/issabel 4/Relarorio_de_filas/"* "$TARGET_DIR/"
elif [ -d "$TMP_REPO/Relatorio_de_filas" ]; then
  cp -rf "$TMP_REPO/Relatorio_de_filas/"* "$TARGET_DIR/"
else
  echo "[-] Erro: Não foi possível localizar a pasta Relatorio_de_filas dentro do repositório clonado."
  rm -rf "$TMP_REPO"
  exit 1
fi

rm -rf "$TMP_REPO"

# 6. Permissões de Arquivo
echo "[+] Ajustando permissões no diretório web (/var/www/html/Relatorio_de_filas)..."
chown -R asterisk:asterisk "$TARGET_DIR"
chmod -R 755 "$TARGET_DIR"

# 7. Finalização
IP_LOCAL=$(hostname -I 2>/dev/null | awk '{print $1}')
if [ -z "$IP_LOCAL" ]; then
  IP_LOCAL="IP_DO_SEU_SERVIDOR"
fi

echo "=========================================================="
echo " [✓] Instalação concluída com sucesso!"
echo ""
echo " Acesse o relatório no navegador pelo link:"
echo " http://$IP_LOCAL/Relatorio_de_filas/"
echo "=========================================================="
