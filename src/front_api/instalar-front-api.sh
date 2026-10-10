#!/bin/bash
# ==============================================================================
# Script de Instalação e Atualização Automatizada - front_api (Painel PABX Prisma)
# Repositório: https://github.com/LeandroSaltori/ipbx-issabel6.0
# ==============================================================================

if [ "$EUID" -ne 0 ]; then
  echo "[-] Erro: Por favor, execute este script como root (sudo su ou sudo -i)."
  exit 1
fi

DEST_DIR="/var/www/html/front_api"
BACKUP_OLD="/var/www/html/front_api_OLD"

# Suporte a Rollback imediato
if [ "$1" == "--rollback" ] || [ "$1" == "-r" ] || [ "$1" == "rollback" ]; then
  echo "=========================================================="
  echo "  Rollback do front_api - Restaurando pasta original"
  echo "=========================================================="
  if [ -d "$BACKUP_OLD" ]; then
    echo "[+] Removendo pasta atual $DEST_DIR..."
    rm -rf "$DEST_DIR"
    echo "[+] Restaurando $BACKUP_OLD para $DEST_DIR..."
    /bin/cp -rf "$BACKUP_OLD" "$DEST_DIR"
    chown -R asterisk:asterisk "$DEST_DIR"
    chmod -R 755 "$DEST_DIR"
    [ -f "$DEST_DIR/.ht_whatsapp_config.sqlite" ] && chmod 666 "$DEST_DIR/.ht_whatsapp_config.sqlite" 2>/dev/null || true
    [ -f "$DEST_DIR/pabx_panel/.ht_whatsapp_config.sqlite" ] && chmod 666 "$DEST_DIR/pabx_panel/.ht_whatsapp_config.sqlite" 2>/dev/null || true
    echo "[✓] Rollback concluído com sucesso! Pasta original restaurada."
    exit 0
  else
    echo "[-] Erro: Backup original $BACKUP_OLD não foi encontrado para rollback."
    exit 1
  fi
fi

echo "=========================================================="
echo "  Instalador Automático - front_api (Painel PABX Asterisk)"
echo "=========================================================="

# 1. Localização da fonte de arquivos
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
TMP_REPO=""

if [ -f "$SCRIPT_DIR/index.php" ] && [ -d "$SCRIPT_DIR/pabx_panel" ]; then
  SOURCE_DIR="$SCRIPT_DIR"
elif [ -d "$SCRIPT_DIR/front_api" ] && [ -f "$SCRIPT_DIR/front_api/index.php" ]; then
  SOURCE_DIR="$SCRIPT_DIR/front_api"
elif [ -d "$SCRIPT_DIR/src/front_api" ] && [ -f "$SCRIPT_DIR/src/front_api/index.php" ]; then
  SOURCE_DIR="$SCRIPT_DIR/src/front_api"
else
  echo "[+] Baixando os arquivos mais recentes do repositório GitHub..."
  TMP_REPO="/tmp/ipbx-front-api-install"
  rm -rf "$TMP_REPO"
  if command -v git &>/dev/null; then
    git clone --depth 1 https://github.com/LeandroSaltori/ipbx-issabel6.0.git "$TMP_REPO"
  else
    mkdir -p "$TMP_REPO"
    curl -sSL https://github.com/LeandroSaltori/ipbx-issabel6.0/archive/refs/heads/main.tar.gz | tar -xz -C "$TMP_REPO" --strip-components=1
  fi
  SOURCE_DIR="$TMP_REPO/src/front_api"
fi

if [ ! -d "$SOURCE_DIR" ] || [ ! -f "$SOURCE_DIR/index.php" ]; then
  echo "[-] Erro: Não foi possível localizar os arquivos válidos do front_api."
  [ -n "$TMP_REPO" ] && rm -rf "$TMP_REPO"
  exit 1
fi

# 2. Backup da pasta original do sistema (Padrão front_api_OLD)
if [ -d "$DEST_DIR" ]; then
  if [ ! -d "$BACKUP_OLD" ]; then
    echo "[+] Criando backup seguro da pasta original: $DEST_DIR -> $BACKUP_OLD..."
    /bin/cp -rf "$DEST_DIR" "$BACKUP_OLD"
    echo "[✓] Pasta original preservada com sucesso em $BACKUP_OLD."
  else
    echo "[*] Backup original $BACKUP_OLD já preservado. Atualizando pasta de produção..."
  fi
fi

# 3. Cópia e implantação dos arquivos novos
echo "[+] Copiando arquivos atualizados para $DEST_DIR..."
mkdir -p "$DEST_DIR"
/bin/cp -rf "$SOURCE_DIR/"* "$DEST_DIR/"

# 4. Ajuste estrito de permissões e proprietário do PABX
echo "[+] Ajustando permissões de sistema..."
chown -R asterisk:asterisk "$DEST_DIR"
chmod -R 755 "$DEST_DIR"

# Permissão nos bancos SQLite se presentes
[ -f "$DEST_DIR/.ht_whatsapp_config.sqlite" ] && chmod 666 "$DEST_DIR/.ht_whatsapp_config.sqlite" 2>/dev/null || true
[ -f "$DEST_DIR/pabx_panel/.ht_whatsapp_config.sqlite" ] && chmod 666 "$DEST_DIR/pabx_panel/.ht_whatsapp_config.sqlite" 2>/dev/null || true

# Limpeza de arquivos temporários se houve clone
if [ -n "$TMP_REPO" ] && [ -d "$TMP_REPO" ]; then
  rm -rf "$TMP_REPO"
fi

# 5. Identificação de IP para exibição
IP_LOCAL=$(hostname -I 2>/dev/null | awk '{print $1}')
if [ -z "$IP_LOCAL" ]; then
  IP_LOCAL="IP_DO_SEU_SERVIDOR"
fi

echo "=========================================================="
echo " [✓] front_api instalado com sucesso no PABX!"
echo ""
echo " Pasta de produção: $DEST_DIR"
echo " Backup original mantido: $BACKUP_OLD"
echo ""
echo " Como acessar no navegador:"
echo "    http://$IP_LOCAL/front_api/"
echo "    ou https://$IP_LOCAL/front_api/"
echo ""
echo " Para reverter a qualquer momento, execute:"
echo "    bash $DEST_DIR/instalar-front-api.sh --rollback"
echo "=========================================================="
exit 0
