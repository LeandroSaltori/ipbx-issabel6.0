#!/bin/bash
# Script de Atualização Rápida IPbx Prisma
echo "Iniciando atualização dos módulos..."

curl -s -k -o /var/www/html/themes/prisma_v5/css/custom.css https://raw.githubusercontent.com/LeandroSaltori/ipbx-issabel6.0/main/src/themes/prisma_v5/css/custom.css
curl -s -k -o /var/www/html/modules/cdrreport/index.php https://raw.githubusercontent.com/LeandroSaltori/ipbx-issabel6.0/main/src/modules/cdrreport/index.php
curl -s -k -o /var/www/html/modules/monitoring/index.php https://raw.githubusercontent.com/LeandroSaltori/ipbx-issabel6.0/main/src/modules/monitoring/index.php
curl -s -k -o /var/www/html/modules/pesquisa/index.php https://raw.githubusercontent.com/LeandroSaltori/ipbx-issabel6.0/main/src/modules/pesquisa/index.php
curl -s -k -o /var/www/html/modules/missed_calls/index.php https://raw.githubusercontent.com/LeandroSaltori/ipbx-issabel6.0/main/src/modules/missed_calls/index.php
curl -s -k -o /var/www/html/modules/graphic_report/index.php https://raw.githubusercontent.com/LeandroSaltori/ipbx-issabel6.0/main/src/modules/graphic_report/index.php

chown -R asterisk:asterisk /var/www/html/

echo "✅ Atualização concluída com sucesso!"
