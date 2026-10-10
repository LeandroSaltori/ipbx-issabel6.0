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
# Preserva a base SQLite de produção (configurações, token da API, usuários, histórico):
# o repositório traz um .ht_whatsapp_config.sqlite de exemplo que NÃO pode sobrescrever a base viva.
KEEP_DIR="$(mktemp -d /tmp/front_api_keep.XXXXXX)"
for rel in ".ht_whatsapp_config.sqlite" "pabx_panel/.ht_whatsapp_config.sqlite"; do
  if [ -f "$DEST_DIR/$rel" ]; then
    mkdir -p "$KEEP_DIR/$(dirname "$rel")"
    /bin/cp -p "$DEST_DIR/$rel" "$KEEP_DIR/$rel"
  fi
done
if [ -d "$DEST_DIR/pabx_panel/logs" ]; then
  mkdir -p "$KEEP_DIR/pabx_panel"
  /bin/cp -rp "$DEST_DIR/pabx_panel/logs" "$KEEP_DIR/pabx_panel/logs"
fi

echo "[+] Copiando arquivos atualizados para $DEST_DIR..."
mkdir -p "$DEST_DIR"
/bin/cp -rf "$SOURCE_DIR/"* "$DEST_DIR/"

# Restaura a base e os logs de produção por cima dos arquivos de exemplo
for rel in ".ht_whatsapp_config.sqlite" "pabx_panel/.ht_whatsapp_config.sqlite"; do
  if [ -f "$KEEP_DIR/$rel" ]; then
    /bin/cp -pf "$KEEP_DIR/$rel" "$DEST_DIR/$rel"
    echo "[✓] Base preservada: $rel"
  fi
done
[ -d "$KEEP_DIR/pabx_panel/logs" ] && /bin/cp -rpf "$KEEP_DIR/pabx_panel/logs/." "$DEST_DIR/pabx_panel/logs/"
rm -rf "$KEEP_DIR"

# 4. Ajuste estrito de permissões e proprietário do PABX
echo "[+] Ajustando permissões de sistema..."
chown -R asterisk:asterisk "$DEST_DIR"
chmod -R 755 "$DEST_DIR"

# Permissão nos bancos SQLite se presentes
[ -f "$DEST_DIR/.ht_whatsapp_config.sqlite" ] && chmod 666 "$DEST_DIR/.ht_whatsapp_config.sqlite" 2>/dev/null || true
[ -f "$DEST_DIR/pabx_panel/.ht_whatsapp_config.sqlite" ] && chmod 666 "$DEST_DIR/pabx_panel/.ht_whatsapp_config.sqlite" 2>/dev/null || true

# Opcional: --sem-login (painel aberto, só para testes) ou --com-login (volta a exigir senha)
for arg in "$@"; do
  case "$arg" in
    --sem-login) php "$DEST_DIR/pabx_panel/set_auth.php" off || true ;;
    --com-login) php "$DEST_DIR/pabx_panel/set_auth.php" on || true ;;
  esac
done

# Senha inicial do painel: só gera se ninguém tiver senha definida ainda
if command -v php &>/dev/null && [ -f "$DEST_DIR/pabx_panel/set_password.php" ]; then
  echo "[+] Verificando senha de acesso do painel..."
  php "$DEST_DIR/pabx_panel/set_password.php" || true
  chown -R asterisk:asterisk "$DEST_DIR"
  [ -f "$DEST_DIR/pabx_panel/.ht_whatsapp_config.sqlite" ] && chmod 666 "$DEST_DIR/pabx_panel/.ht_whatsapp_config.sqlite" 2>/dev/null || true
fi

# Cron de abandono de fila (WhatsApp para cliente/supervisor) - idempotente
if [ -d /etc/cron.d ]; then
  PHP_BIN="$(command -v php || echo /usr/bin/php)"
  cat > /etc/cron.d/ipbx-front-api <<CRON
# IPbx Prisma - notificacao WhatsApp de abandono de fila (gerado por instalar-front-api.sh)
* * * * * asterisk $PHP_BIN $DEST_DIR/pabx_panel/cron/send_queue_abandon.php >/dev/null 2>&1
# IPbx Prisma - resumo por IA e link da gravacao ao atendente (WhatsApp)
* * * * * asterisk $PHP_BIN $DEST_DIR/pabx_panel/cron/send_call_summaries.php >/dev/null 2>&1
CRON
  chmod 644 /etc/cron.d/ipbx-front-api
  echo "[✓] Crons (abandono de fila, resumo de chamadas) instalados em /etc/cron.d/ipbx-front-api"
fi

# Fail2ban do Issabel: jail "ipbx-front-api" (bloqueio de IP por falhas de login do painel)
# Gerido nas telas nativas do Issabel: Security > Fail2ban (jails) e Security > Fail2ban Banned IPs.
if [ -d /etc/fail2ban ]; then
  F2B_LOG_DIR="/var/log/ipbx-front-api"
  mkdir -p "$F2B_LOG_DIR"
  touch "$F2B_LOG_DIR/auth.log"
  chown -R asterisk:asterisk "$F2B_LOG_DIR"
  chmod 755 "$F2B_LOG_DIR"; chmod 644 "$F2B_LOG_DIR/auth.log"

  cat > /etc/fail2ban/filter.d/ipbx-front-api.conf <<'F2BFILTER'
# IPbx Prisma - falhas de login no painel front_api
[Definition]
failregex = ^.*ipbx-front-api LOGIN_FAIL ip=<HOST> user=\S*\s*$
ignoreregex =
F2BFILTER

  JAIL_BLOCK='[ipbx-front-api]
enabled = true
filter = ipbx-front-api
logpath = /var/log/ipbx-front-api/auth.log
port = http,https
maxretry = 5
findtime = 600
bantime = 3600'

  ISSABEL_JAILS="/etc/fail2ban/jail.d/issabel.conf"
  if [ -f "$ISSABEL_JAILS" ]; then
    # Dentro do arquivo do Issabel, para aparecer/ser editável em Security > Fail2ban
    if ! grep -q '^\[ipbx-front-api\]' "$ISSABEL_JAILS"; then
      [ -f "$ISSABEL_JAILS.bak-ipbx" ] || /bin/cp -p "$ISSABEL_JAILS" "$ISSABEL_JAILS.bak-ipbx"
      printf '\n%s\n' "$JAIL_BLOCK" >> "$ISSABEL_JAILS"
    fi
    rm -f /etc/fail2ban/jail.d/ipbx-front-api.conf
  else
    printf '%s\n' "$JAIL_BLOCK" > /etc/fail2ban/jail.d/ipbx-front-api.conf
  fi

  cat > /etc/logrotate.d/ipbx-front-api <<'LOGROT'
/var/log/ipbx-front-api/auth.log {
    weekly
    rotate 8
    compress
    missingok
    notifempty
    copytruncate
}
LOGROT

  if [ -x /usr/bin/issabel-helper ]; then
    /usr/bin/issabel-helper fb_client reload >/dev/null 2>&1 || fail2ban-client reload >/dev/null 2>&1 || true
  elif command -v fail2ban-client &>/dev/null; then
    fail2ban-client reload >/dev/null 2>&1 || true
  fi
  echo "[✓] Jail fail2ban 'ipbx-front-api' instalado (5 falhas/10 min = banimento de 1h)."
fi

# Telas nativas do Issabel (lista de IPs banidos e jails) com o jail do painel
if [ -d "$SOURCE_DIR/../modules/sec_fb_banned" ] && [ -d /var/www/html/modules/sec_fb_banned ]; then
  /bin/cp -f "$SOURCE_DIR/../modules/sec_fb_banned/index.php" /var/www/html/modules/sec_fb_banned/index.php
  /bin/cp -f "$SOURCE_DIR/../modules/sec_fb_admin/libs/IssabelF2Bservice.class.php" /var/www/html/modules/sec_fb_admin/libs/IssabelF2Bservice.class.php
  echo "[✓] Telas nativas do Fail2ban atualizadas para exibir o jail do painel."
fi

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
echo " Redefinir senha de um usuário:"
echo "    php $DEST_DIR/pabx_panel/set_password.php email@dominio.com"
echo ""
echo " Para reverter a qualquer momento, execute:"
echo "    bash $DEST_DIR/instalar-front-api.sh --rollback"
echo "=========================================================="
exit 0
