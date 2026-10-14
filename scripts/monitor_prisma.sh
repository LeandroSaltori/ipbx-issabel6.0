#!/bin/bash
# ==============================================================================
# MONITOR DE SEGURANCA - IPBX PRISMA TELECOM
# ==============================================================================
# Este monitor foi UNIFICADO no IPbx Sentinel (scripts/ipbx-sentinel.sh), que agora
# faz tudo: webshells, modulos falsos, firewall, dialplan, cron, contas/senhas e
# alertas no Telegram. As credenciais do Telegram ficam em /etc/ipbx/telegram.conf
# (nunca neste arquivo). Este atalho existe apenas por compatibilidade.
# ==============================================================================
S=/usr/local/bin/ipbx-sentinel
[ -x "$S" ] && exec "$S" --host
exit 0
