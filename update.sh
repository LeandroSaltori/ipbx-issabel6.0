#!/bin/bash
# ==============================================================================
# SCRIPT DE ATUALIZAÇÃO RÁPIDA COM SNAPSHOT / ROLLBACK - IPBX PRISMA TELECOM
# ==============================================================================
set -e

unalias cp 2>/dev/null || true
unalias mv 2>/dev/null || true
unalias rm 2>/dev/null || true
shopt -s expand_aliases 2>/dev/null || true

# Cria snapshot com data e hora antes de aplicar a atualização
TIMESTAMP=$(date '+%Y-%m-%d_%H%M%S')
BACKUP_DIR="/var/backup/ipbx/backup_${TIMESTAMP}"
mkdir -p "$BACKUP_DIR/html/themes/prisma_v5/css" \
         "$BACKUP_DIR/html/modules/cdrreport" \
         "$BACKUP_DIR/html/modules/monitoring" \
         "$BACKUP_DIR/html/modules/pesquisa" \
         "$BACKUP_DIR/html/modules/missed_calls" \
         "$BACKUP_DIR/html/modules/graphic_report" 2>/dev/null || true

echo "Update Rápido de Módulos (update.sh) - $(date '+%d/%m/%Y às %H:%M:%S')" > "$BACKUP_DIR/manifesto.txt"

# Salva estado atual dos arquivos modificados no snapshot
[ -f /var/www/html/themes/prisma_v5/css/custom.css ] && cp -pf /var/www/html/themes/prisma_v5/css/custom.css "$BACKUP_DIR/html/themes/prisma_v5/css/" 2>/dev/null || true
[ -f /var/www/html/modules/cdrreport/index.php ] && cp -pf /var/www/html/modules/cdrreport/index.php "$BACKUP_DIR/html/modules/cdrreport/" 2>/dev/null || true
[ -f /var/www/html/modules/monitoring/index.php ] && cp -pf /var/www/html/modules/monitoring/index.php "$BACKUP_DIR/html/modules/monitoring/" 2>/dev/null || true
[ -f /var/www/html/modules/pesquisa/index.php ] && cp -pf /var/www/html/modules/pesquisa/index.php "$BACKUP_DIR/html/modules/pesquisa/" 2>/dev/null || true
[ -f /var/www/html/modules/missed_calls/index.php ] && cp -pf /var/www/html/modules/missed_calls/index.php "$BACKUP_DIR/html/modules/missed_calls/" 2>/dev/null || true
[ -f /var/www/html/modules/graphic_report/index.php ] && cp -pf /var/www/html/modules/graphic_report/index.php "$BACKUP_DIR/html/modules/graphic_report/" 2>/dev/null || true

ln -sfn "$BACKUP_DIR" /var/backup/ipbx/latest 2>/dev/null || true
echo "📦 Ponto de restauração gravado em: $BACKUP_DIR"

echo "=== Atualizando módulos IPbx Prisma ==="

# Download dos arquivos atualizados
curl -s -k -o /var/www/html/themes/prisma_v5/css/custom.css https://raw.githubusercontent.com/LeandroSaltori/ipbx-issabel6.0/main/src/themes/prisma_v5/css/custom.css
curl -s -k -o /var/www/html/modules/cdrreport/index.php https://raw.githubusercontent.com/LeandroSaltori/ipbx-issabel6.0/main/src/modules/cdrreport/index.php
curl -s -k -o /var/www/html/modules/monitoring/index.php https://raw.githubusercontent.com/LeandroSaltori/ipbx-issabel6.0/main/src/modules/monitoring/index.php
curl -s -k -o /var/www/html/modules/pesquisa/index.php https://raw.githubusercontent.com/LeandroSaltori/ipbx-issabel6.0/main/src/modules/pesquisa/index.php
curl -s -k -o /var/www/html/modules/missed_calls/index.php https://raw.githubusercontent.com/LeandroSaltori/ipbx-issabel6.0/main/src/modules/missed_calls/index.php
curl -s -k -o /var/www/html/modules/graphic_report/index.php https://raw.githubusercontent.com/LeandroSaltori/ipbx-issabel6.0/main/src/modules/graphic_report/index.php

# Permissões
chown -R asterisk:asterisk /var/www/html/

# Limpar cache do Smarty e recarregar Apache para limpar OpCache
rm -rf /var/www/html/var/templates_c/* 2>/dev/null || true
systemctl reload httpd 2>/dev/null || systemctl restart httpd 2>/dev/null || service httpd reload 2>/dev/null || true

echo "✅ Atualização e limpeza de cache concluídas com sucesso!"
