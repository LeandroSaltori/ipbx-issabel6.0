# Blindagem do painel (acesso direto por URL)

**Falha encontrada (visao de atacante):** as telas em `modules/*`, `includes/*` e os scripts
`parselog.php` / `whatsapp_agi.php` podiam ser abertas direto pela URL, sem passar pelo `index.php`
(que e quem exige login). Isso vazava campos de configuracao (ex.: senha de API) e logs.

**Correcao em duas camadas (nao altera o acesso dos clientes ao painel):**
1. PHP: constante `IPBX_PANEL` definida so no `index.php`; cada tela responde 404 se for chamada direto.
   Scripts de linha de comando respondem 404 se chamados via web.
2. Apache: `/etc/httpd/conf.d/ipbx-protect.conf` (criado pelo `instalar-front-api.sh`, com configtest e
   rollback automatico) nega `modules/ includes/ config/ cron/ logs/ parselog/ docs/` e arquivos
   `.sqlite .db .log .bak .sql .ini .env .sh .md` sob `/front_api/`.

Logs e sessoes (`logs/*.log`, `logs/sessions/sess_*`) deixaram de ser versionados.
Teste: URLs diretas -> 404; painel logado normal; smoke-test 36 telas / 89 cliques OK.
Nao testado: o arquivo do Apache em servidor real (so valida no proximo update).
