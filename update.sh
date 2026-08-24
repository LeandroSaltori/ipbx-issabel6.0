#!/bin/bash
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

# Limpar cache do Smarty e reiniciar serviços para limpar OpCache
rm -rf /var/www/html/var/templates_c/* 2>/dev/null
systemctl reload httpd 2>/dev/null || systemctl restart httpd 2>/dev/null || service httpd restart 2>/dev/null

echo "✅ Atualização e limpeza de cache concluídas com sucesso!"
