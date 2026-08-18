#!/bin/bash
# ==============================================================================
# Script de Instalação Automatizada - Asternic CDR Report (Issabel 4 / Issabel 5)
# Repositório: https://github.com/LeandroSaltori/ipbx-issabel5
# ==============================================================================

# 1. Verifica se o usuário é root
if [ "$EUID" -ne 0 ]; then
  echo "[-] Erro: Por favor, execute este script como root (sudo su ou sudo -i)."
  exit 1
fi

echo "=========================================================="
echo "  Instalador Automático - Asternic CDR Report (Issabel PBX)"
echo "=========================================================="

# 2. Localização dos arquivos do módulo no repositório ou download
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TMP_REPO=""

if [ -f "$SCRIPT_DIR/page.asternic_cdr.php" ]; then
  SOURCE_DIR="$SCRIPT_DIR"
elif [ -d "$SCRIPT_DIR/asternic_cdr" ] && [ -f "$SCRIPT_DIR/asternic_cdr/page.asternic_cdr.php" ]; then
  SOURCE_DIR="$SCRIPT_DIR/asternic_cdr"
elif [ -d "$SCRIPT_DIR/admin/modules/asternic_cdr" ] && [ -f "$SCRIPT_DIR/admin/modules/asternic_cdr/page.asternic_cdr.php" ]; then
  SOURCE_DIR="$SCRIPT_DIR/admin/modules/asternic_cdr"
else
  echo "[+] Baixando os arquivos do repositório GitHub..."
  TMP_REPO="/tmp/ipbx-issabel5-asternic-install"
  rm -rf "$TMP_REPO"
  if command -v git &>/dev/null; then
    git clone --depth 1 https://github.com/LeandroSaltori/ipbx-issabel5.git "$TMP_REPO" &>/dev/null
  else
    echo "[-] Erro: git não encontrado. Instalando dependências..."
    if command -v dnf &>/dev/null; then
      dnf install -y git wget tar &>/dev/null
    elif command -v yum &>/dev/null; then
      yum install -y git wget tar &>/dev/null
    fi
    git clone --depth 1 https://github.com/LeandroSaltori/ipbx-issabel5.git "$TMP_REPO" &>/dev/null
  fi

  if [ -d "$TMP_REPO/asternic_cdr" ]; then
    SOURCE_DIR="$TMP_REPO/asternic_cdr"
  elif [ -d "$TMP_REPO/admin/modules/asternic_cdr" ]; then
    SOURCE_DIR="$TMP_REPO/admin/modules/asternic_cdr"
  else
    echo "[-] Erro: Não foi possível localizar a pasta do Asternic CDR no repositório."
    rm -rf "$TMP_REPO"
    exit 1
  fi
fi

# 3. Diretório de destino no IssabelPBX
TARGET_DIR="/var/www/html/admin/modules/asternic_cdr"
BACKUP_OLD_DIR="/var/www/html/admin/modules/asternic_cdr_OLD"

# 4. Renomear instalação existente para asternic_cdr_OLD (Processo de Substituição)
if [ -d "$TARGET_DIR" ]; then
  # Se já existir asternic_cdr_OLD, salva com data/hora para não sobrescrever backups antigos
  if [ -d "$BACKUP_OLD_DIR" ]; then
    mv "$BACKUP_OLD_DIR" "/var/www/html/admin/modules/asternic_cdr_OLD_$(date +%Y%m%d_%H%M%S)"
  fi
  echo "[+] Renomeando pasta de instalação anterior ($TARGET_DIR -> $BACKUP_OLD_DIR)..."
  mv "$TARGET_DIR" "$BACKUP_OLD_DIR"
fi

# 5. Aplicar e puxar a pasta asternic_cdr com as alterações customizadas
echo "[+] Aplicando arquivos alterados do Asternic CDR em $TARGET_DIR..."
mkdir -p "$TARGET_DIR"
cp -rf "$SOURCE_DIR/"* "$TARGET_DIR/"

# Removendo pastas e arquivos temporários da cópia de instalação, se existirem
rm -rf "$TARGET_DIR/_Instalador" 2>/dev/null || true
rm -rf "$TARGET_DIR/instalar-asternic-cdr.sh" 2>/dev/null || true

# 6. Leitura da senha root do MySQL em /etc/issabel.conf e registro no DB
MYSQL_PWD=""
if [ -f /etc/issabel.conf ]; then
  MYSQL_PWD=$(grep -i mysqlrootpwd /etc/issabel.conf | cut -d'=' -f2 | tr -d ' ')
fi

if [ -n "$MYSQL_PWD" ]; then
  echo "[+] Registrando módulo na base de dados do IssabelPBX (asterisk.modules)..."
  mysql -u root -p"$MYSQL_PWD" asterisk -e "INSERT INTO modules (modulename, version, enabled, signature) VALUES ('asternic_cdr', '1.6.6', 1, '') ON DUPLICATE KEY UPDATE enabled=1, version='1.6.6';" 2>/dev/null || true
  # Garante permissões totais para o usuário admin no IssabelPBX
  mysql -u root -p"$MYSQL_PWD" asterisk -e "UPDATE ampusers SET sections='*' WHERE username='admin';" 2>/dev/null || true
fi

# 7. Ajuste de permissões e proprietário (asterisk:asterisk)
echo "[+] Ajustando permissões do diretório web (/var/www/html/admin/modules/asternic_cdr)..."
chown -R asterisk:asterisk "$TARGET_DIR"
chmod -R 755 "$TARGET_DIR"

# 8. Habilitação do módulo no FreePBX / IssabelPBX CLI e recarregamento
echo "[+] Habilitando módulo e recarregando configurações do PBX..."
amportal a ma install asternic_cdr 2>/dev/null || true
amportal a ma enable asternic_cdr 2>/dev/null || true
fwconsole ma install asternic_cdr 2>/dev/null || true
fwconsole ma enable asternic_cdr 2>/dev/null || true

if [ -f /var/lib/asterisk/bin/retrieve_conf ]; then
  php /var/lib/asterisk/bin/retrieve_conf &>/dev/null || true
fi

amportal a r 2>/dev/null || true
fwconsole reload 2>/dev/null || true

if command -v asterisk &>/dev/null; then
  asterisk -rx "module reload" &>/dev/null || true
fi

# Limpeza de repositório temporário
if [ -n "$TMP_REPO" ] && [ -d "$TMP_REPO" ]; then
  rm -rf "$TMP_REPO"
fi

# 9. Identificação do IP do servidor para exibição
IP_LOCAL=$(hostname -I 2>/dev/null | awk '{print $1}')
if [ -z "$IP_LOCAL" ]; then
  IP_LOCAL="IP_DO_SEU_SERVIDOR"
fi

echo "=========================================================="
echo " [✓] Instalação do Asternic CDR concluída com sucesso!"
echo ""
echo " Como acessar o relatório no navegador:"
echo ""
echo " 1. Acesso Direto PBX (Recomendado):"
echo "    https://$IP_LOCAL/admin/config.php?display=asternic_cdr"
echo ""
echo " 2. Menu do Issabel PBX:"
echo "    Acesse: Configuração PBX -> Administrador de Módulos"
echo "    ou em Opções Avançadas -> Asternic CDR Report"
echo "=========================================================="
