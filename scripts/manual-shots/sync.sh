#!/bin/bash
# Copia o painel para o ambiente de teste das capturas e aplica ajustes so de teste (nao altera o repositorio)
S=/tmp/claude-0/-home-claude/c6c31332-6c51-5b72-ac5e-03aaa26652ab/scratchpad/ut/panel
R=$(cd "$(dirname "$0")/../.." && pwd)
rsync -a --exclude config --exclude '.ht_*' --exclude logs "$R/src/front_api/pabx_panel/" "$S/"
sed -i "s/!empty(\$_SESSION\['via_sso'\])/true/" "$S/includes/sidebar.php"
sed -i "s/if (\$secret === '') {/if (false) {/" "$S/webphone.php"
