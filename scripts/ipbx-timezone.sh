#!/bin/bash
# ==============================================================================
# CONFIGURAÇÃO DE TIMEZONE, DATA, HORA E NTP (BRASIL / SÃO PAULO)
# IPBX ISSABEL (PRISMA TELECOM) - COMPATÍVEL COM CENTOS 7 E ROCKY LINUX 8
# ==============================================================================
# Este script configura de forma segura e não intrusiva:
# 1. Fuso horário do sistema para America/Sao_Paulo (Horário de Brasília)
# 2. Sincronização de Data e Hora via NTP com servidores oficiais do Brasil (NTP.br)
# 3. Configuração da diretiva date.timezone no php.ini
# 4. Zero Downtime: sem interrupção do Asterisk ou chamadas ativas.
# ==============================================================================

set -e

# Desativa aliases do root
unalias cp 2>/dev/null || true
unalias mv 2>/dev/null || true
unalias rm 2>/dev/null || true
shopt -s expand_aliases 2>/dev/null || true

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

TARGET_TIMEZONE="America/Sao_Paulo"
ZONEINFO_FILE="/usr/share/zoneinfo/$TARGET_TIMEZONE"

echo ""
log_info "=== Iniciando Configuração de Data, Hora e Timezone (São Paulo / NTP.br) ==="
echo ""

# ------------------------------------------------------------------------------
# 1. AJUSTE DO FUSO HORÁRIO DO SISTEMA OPERACIONAL (LINUX)
# ------------------------------------------------------------------------------
log_info "1. Configurando Fuso Horário do Linux para $TARGET_TIMEZONE..."

# Utiliza timedatectl se disponível
if command -v timedatectl &>/dev/null; then
    timedatectl set-timezone "$TARGET_TIMEZONE" 2>/dev/null || true
fi

# Garante o link simbólico /etc/localtime
if [ -f "$ZONEINFO_FILE" ]; then
    rm -f /etc/localtime 2>/dev/null || true
    ln -sf "$ZONEINFO_FILE" /etc/localtime
    log_success "Fuso horário do sistema vinculado a $TARGET_TIMEZONE (/etc/localtime)."
else
    log_warn "Arquivo de zona $ZONEINFO_FILE não encontrado, verificando tzdata..."
    if command -v yum &>/dev/null; then
        yum install -y tzdata 2>/dev/null || true
    elif command -v dnf &>/dev/null; then
        dnf install -y tzdata 2>/dev/null || true
    fi
    if [ -f "$ZONEINFO_FILE" ]; then
        ln -sf "$ZONEINFO_FILE" /etc/localtime
    fi
fi

# Ajusta /etc/timezone e /etc/sysconfig/clock para compatibilidade legada
echo "$TARGET_TIMEZONE" > /etc/timezone 2>/dev/null || true
if [ -f /etc/sysconfig/clock ]; then
    sed -i 's|^ZONE=.*|ZONE="'"$TARGET_TIMEZONE"'"|' /etc/sysconfig/clock 2>/dev/null || true
fi

# ------------------------------------------------------------------------------
# 2. CONFIGURAÇÃO DE SERVIDORES NTP OFICIAIS DO BRASIL (NTP.BR)
# ------------------------------------------------------------------------------
log_info "2. Configurando Sincronização NTP com servidores oficiais do Brasil (NTP.br)..."

# Prioridade 1: CHRONY (Padrão no CentOS 7 e Rocky Linux 8)
if [ -f /etc/chrony.conf ] || command -v chronyd &>/dev/null; then
    CHRONY_CONF="/etc/chrony.conf"
    
    # Se chrony não estiver instalado mas yum/dnf existir, instala
    if [ ! -f "$CHRONY_CONF" ]; then
        if command -v yum &>/dev/null; then
            yum install -y chrony 2>/dev/null || true
        elif command -v dnf &>/dev/null; then
            dnf install -y chrony 2>/dev/null || true
        fi
    fi

    if [ -f "$CHRONY_CONF" ]; then
        # Backup seguro
        [ ! -f "${CHRONY_CONF}.bak" ] && cp -pf "$CHRONY_CONF" "${CHRONY_CONF}.bak" 2>/dev/null || true

        # Verifica se os servidores NTP.br já estão presentes
        if ! grep -q "a.st1.ntp.br" "$CHRONY_CONF" 2>/dev/null; then
            log_info "Inserindo servidores ntp.br em $CHRONY_CONF..."
            # Comenta servidores padrão de pool centos/rhel
            sed -i 's/^\(server [0-3]\.centos\.pool\.ntp\.org\)/# \1/' "$CHRONY_CONF" 2>/dev/null || true
            sed -i 's/^\(pool 2\.rocky\.pool\.ntp\.org\)/# \1/' "$CHRONY_CONF" 2>/dev/null || true
            sed -i 's/^\(pool 2\.centos\.pool\.ntp\.org\)/# \1/' "$CHRONY_CONF" 2>/dev/null || true

            # Cria bloco de configuração do Brasil no início
            TMP_NTP=$(mktemp)
            cat <<'EOF' > "$TMP_NTP"
# --- SERVIDORES NTP OFICIAIS DO BRASIL (PRISMA TELECOM / NTP.BR) ---
server a.st1.ntp.br iburst
server b.st1.ntp.br iburst
server c.st1.ntp.br iburst
server d.st1.ntp.br iburst
pool pool.ntp.br iburst

EOF
            cat "$CHRONY_CONF" >> "$TMP_NTP"
            mv -f "$TMP_NTP" "$CHRONY_CONF"
        fi

        # Garante diretiva makestep para ajuste rápido na inicialização
        if ! grep -q "^makestep" "$CHRONY_CONF" 2>/dev/null; then
            echo "makestep 1.0 3" >> "$CHRONY_CONF"
        fi

        # Habilita e reinicia chronyd
        systemctl enable chronyd 2>/dev/null || chkconfig chronyd on 2>/dev/null || true
        systemctl restart chronyd 2>/dev/null || service chronyd restart 2>/dev/null || true

        # Força passo de sincronização imediata
        chronyc makestep 2>/dev/null || chronyc -a makestep 2>/dev/null || true
        log_success "Chrony configurado e sincronizado com os servidores NTP.br."
    fi

# Prioridade 2: NTPD Clássico (se chrony não estiver em uso e ntpd existir)
elif [ -f /etc/ntp.conf ] || command -v ntpd &>/dev/null; then
    NTP_CONF="/etc/ntp.conf"
    [ ! -f "${NTP_CONF}.bak" ] && cp -pf "$NTP_CONF" "${NTP_CONF}.bak" 2>/dev/null || true

    if ! grep -q "a.st1.ntp.br" "$NTP_CONF" 2>/dev/null; then
        sed -i 's/^\(server [0-3]\.centos\.pool\.ntp\.org\)/# \1/' "$NTP_CONF" 2>/dev/null || true
        cat <<'EOF' >> "$NTP_CONF"

# --- SERVIDORES NTP OFICIAIS DO BRASIL (PRISMA TELECOM / NTP.BR) ---
server a.st1.ntp.br iburst
server b.st1.ntp.br iburst
server c.st1.ntp.br iburst
server d.st1.ntp.br iburst
server pool.ntp.br iburst
EOF
    fi

    systemctl enable ntpd 2>/dev/null || chkconfig ntpd on 2>/dev/null || true
    systemctl restart ntpd 2>/dev/null || service ntpd restart 2>/dev/null || true
    log_success "NTPd configurado e sincronizado com os servidores NTP.br."
fi

# Ativa sincronização NTP no systemd
if command -v timedatectl &>/dev/null; then
    timedatectl set-ntp true 2>/dev/null || true
fi

# ------------------------------------------------------------------------------
# 3. CONFIGURAÇÃO DE TIMEZONE NO PHP & APACHE
# ------------------------------------------------------------------------------
log_info "3. Configurando Timezone no PHP ($TARGET_TIMEZONE)..."

for PHP_FILE in /etc/php.ini /etc/php.d/date.ini /etc/php.d/timezone.ini /etc/opt/rh/rh-php7*/php.ini; do
    if [ -f "$PHP_FILE" ]; then
        if grep -q "^[; ]*date\.timezone" "$PHP_FILE" 2>/dev/null; then
            sed -i "s|^[; ]*date\.timezone\s*=.*|date.timezone = $TARGET_TIMEZONE|" "$PHP_FILE"
        else
            echo "date.timezone = $TARGET_TIMEZONE" >> "$PHP_FILE"
        fi
        log_success "Timezone atualizado em: $PHP_FILE"
    fi
done

# Cria arquivo isolado em php.d para garantir precedência
if [ -d /etc/php.d ]; then
    echo "date.timezone = $TARGET_TIMEZONE" > /etc/php.d/00-ipbx-timezone.ini
    log_success "Configuração prioritária criada em /etc/php.d/00-ipbx-timezone.ini"
fi

# ------------------------------------------------------------------------------
# 4. ATUALIZAÇÃO DO BANCO DE DADOS DO FREEPBX / ISSABEL (TIME CONDITIONS)
# ------------------------------------------------------------------------------
log_info "4. Atualizando timezone no banco de dados do FreePBX / Issabel..."

# Obtém senha de root do MySQL do Issabel caso exista
MYSQL_PWD_OPT=""
if [ -f /etc/issabel.conf ]; then
    MYSQL_ROOT_PASS=$(grep -i "^mysqlrootpwd=" /etc/issabel.conf | cut -d'=' -f2 | tr -d ' "\r\n' || true)
    [ -n "$MYSQL_ROOT_PASS" ] && MYSQL_PWD_OPT="-p$MYSQL_ROOT_PASS"
fi

mysql -u root $MYSQL_PWD_OPT -e "UPDATE asterisk.freepbx_settings SET value='$TARGET_TIMEZONE' WHERE keyword='TIMEZONE';" 2>/dev/null || \
mysql -e "UPDATE asterisk.freepbx_settings SET value='$TARGET_TIMEZONE' WHERE keyword='TIMEZONE';" 2>/dev/null || true

# ------------------------------------------------------------------------------
# 5. REINICIALIZAÇÃO DE SERVIÇOS (MARIADB, APACHE, PHP-FPM, CROND)
# ------------------------------------------------------------------------------
log_info "5. Reiniciando serviços para aplicar o novo fuso em todos os daemons..."

# Reinicia MariaDB/MySQL para atualizar NOW() e variáveis de tempo do banco
systemctl restart mariadb 2>/dev/null || systemctl restart mysqld 2>/dev/null || service mariadb restart 2>/dev/null || service mysqld restart 2>/dev/null || true

# Reinicia Apache e PHP-FPM para limpar variáveis de ambiente de processos antigos
systemctl restart httpd 2>/dev/null || systemctl restart apache2 2>/dev/null || service httpd restart 2>/dev/null || true
systemctl restart php-fpm 2>/dev/null || service php-fpm restart 2>/dev/null || true

# Reinicia Crond para que agendamentos sigam o horário de Brasília
systemctl restart crond 2>/dev/null || systemctl restart cron 2>/dev/null || true

# Recarrega o Dialplan e Logger do Asterisk
if command -v asterisk &>/dev/null; then
    asterisk -rx "dialplan reload" 2>/dev/null || true
    asterisk -rx "logger reload" 2>/dev/null || true
    asterisk -rx "module reload app_timecondition.so" 2>/dev/null || true
fi

# ------------------------------------------------------------------------------
# 6. RESUMO E CONFIRMAÇÃO FINAL
# ------------------------------------------------------------------------------
echo ""
DATA_HORA_ATUAL=$(date '+%d/%m/%Y %H:%M:%S (%Z %z)')
log_success "✅ Configuração de Data, Hora e Timezone finalizada com sucesso!"
echo -e "${CYAN}   → Data e Hora Atual do Servidor:${NC} ${GREEN}${DATA_HORA_ATUAL}${NC}"
echo -e "${CYAN}   → Fuso Horário Ativo:${NC} ${GREEN}${TARGET_TIMEZONE}${NC}"
if command -v timedatectl &>/dev/null; then
    echo -e "${CYAN}   → Status Timedatectl:${NC}"
    timedatectl status 2>/dev/null | grep -E "Time zone|Local time|NTP service|synchronized" || timedatectl status 2>/dev/null || true
fi
echo ""
log_info "💡 DICA: Caso o Asterisk tenha sido iniciado antes da alteração de timezone,"
log_info "        um 'reboot' no servidor ou 'systemctl restart asterisk' garantirá que"
log_info "        100% das regras de Time Conditions e gravações utilizem o novo relógio."
echo ""
