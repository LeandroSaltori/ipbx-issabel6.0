🛡️ Guia de Segurança e Monitoramento - Issabel (Caso Emad)
Este guia documenta o processo de limpeza e a criação de um sistema de alerta preventivo contra a injeção de webshells por espelhamento.

1. Limpeza Cirúrgica (Remoção da Assinatura)
Para remover apenas os arquivos maliciosos sem afetar o sistema, utilizamos a assinatura específica encontrada no código invasor:

Bash
# Remove todos os arquivos com a assinatura, exceto o index.php do SMSS
grep -rl "Emad__Was__Here" /var/www/html/ | grep -v "index.php" | xargs rm -f

# Saneia o arquivo legítimo do módulo SMSS sem deletá-lo
sed -i '/Emad__Was__Here/d' /var/www/html/admin/modules/smss/index.php

# Limpa o cache de templates (Obrigatório para remover injeções em memória)
rm -rf /var/www/html/var/templates_c/*

2. Blindagem de Diretório
Após a limpeza, travamos a raiz do diretório web para impedir que novos arquivos sejam criados via exploit PHP:

Bash
# Trava o diretório (Imutabilidade)
chattr +i /var/www/html/

# Nota: Para manutenções futuras, use chattr -i /var/www/html/

3. Script de Monitoramento e Alerta (Telegram)
Script criado para rodar via cron e notificar a equipe da Prisma Telecom caso qualquer rastro do invasor reapareça.

Caminho: /root/monitor_emad.sh

Bash
#!/bin/bash

# --- CONFIGURAÇÕES ---
TOKEN="<definido em /etc/ipbx/telegram.conf>"
CHAT_ID="<definido em /etc/ipbx/telegram.conf>"
URL="https://api.telegram.org/bot$TOKEN/sendMessage"
DIR="/var/www/html/"
TERM="Emad__Was__Here"
HOSTNAME=$(hostname)

# --- EXECUÇÃO ---
FILES_FOUND=$(grep -rl "$TERM" "$DIR")

if [ ! -z "$FILES_FOUND" ]; then
    LIST=$(echo "$FILES_FOUND" | head -n 10)
    MESSAGE="🚨 *ALERTA DE SEGURANÇA - $HOSTNAME* 🚨%0A%0AInvasor '*Emad*' detectado!%0AArquivos infectados encontrados:%0A%0A\`$LIST\`%0A%0A⚠️ *Ação:* Verifique se o chattr foi burlado e limpe o templates_c."

    curl -s -X POST "$URL" -d chat_id="$CHAT_ID" -d text="$MESSAGE" -d parse_mode="Markdown" > /dev/null
    echo "$(date): Invasão detectada: $FILES_FOUND" >> /var/log/monitor_emad.log
fi


4. Agendamento Automático (Cron)
Configuração para rodar a verificação a cada 5 minutos:

Bash
# Comando para injetar no crontab sem abrir editor:
(crontab -l 2>/dev/null; echo "*/5 * * * * /root/monitor_emad.sh") | crontab -


✅ Checklist Pós-Incidente
[ ] Alterar senhas de usuários no painel Issabel (prisma / suporte).

[ ] Validar se as rotas de saída de telefonia não possuem destinos internacionais suspeitos.

[ ] Restringir portas 80/443 via Firewall (MikroTik/pfSense).

---

## 5. Atualização: IPbx Sentinel (automático em toda a frota)

O monitor manual acima foi substituído pelo **IPbx Sentinel** (`scripts/ipbx-sentinel.sh`), instalado pelo `ipbx-security-hardening.sh` (menu [20]). Compatível com Issabel 4 e 5; atua só na camada web e não toca no Asterisk.

**O que detecta (caso real Issabel 4, 13/10/2026):**
- Assinatura `Emad__Was__Here` e variantes.
- `eval($a($b($c("..."))))`: eval ofuscado em cadeia de variáveis-função.
- `eval(base64_decode(...))`, `system/shell_exec/passthru/assert` sobre `$_GET/$_POST/$_REQUEST`.
- Módulos falsos em `/var/www/html/admin/modules/`: `phones`, `h`, `_cache`, `tinfo`, `fpbxphones`.
- Qualquer `.php` dentro de pastas `images/` ou `img/`.

**Legítimos ignorados (falsos positivos):** `admin/libraries/php-upgrade/*`, `XML_Unserializer.php`, arquivos `.js`, `var/templates_c`.

**Vigilância de contas e senhas (o invasor entrou como `admin` e alterou o usuário do banco):** na primeira execução o Sentinel tira uma "foto" (só impressões digitais, nunca senhas) de: usuários e permissões do painel (`/var/www/db/acl.db`), usuários do FreePBX (`ampusers`), usuários do MySQL e os arquivos `/etc/issabel.conf`, `/etc/amportal.conf`, `/etc/freepbx.conf`. A cada execução compara e avisa no Telegram: **NOVO**, **ALTERADO** (senha/permissões) ou **REMOVIDO**. Só alerta, não desfaz: uma troca de senha pode ser legítima. Fonte que não puder ser lida (ex.: banco fora do ar) é ignorada, sem alarme falso. A foto deve nascer com o servidor limpo; para refazer depois de uma mudança legítima/limpeza: `ipbx-sentinel --baseline`.

**Nomes suspeitos (caso real: usuário `atmin` no MySQL, sósia de `admin`):** independente da foto, o Sentinel avisa uma única vez se encontrar no painel, FreePBX ou MySQL contas como `atmin`, `admln`, `adm1n`, `admim`, `4dmin`, `emad`, `supportpbx`. Assim, um servidor que já estava invadido na hora da instalação também é sinalizado. Na limpeza, remova o usuário (`DROP USER 'atmin'@'%';`), troque as senhas do banco e atualize `/etc/issabel.conf`, `/etc/amportal.conf` e `/etc/freepbx.conf`; depois rode `ipbx-sentinel --baseline`.

**O que faz ao achar:** move para **quarentena** (`/var/backup/ipbx/quarantine/AAAA-MM-DD_HHMMSS/`, reversível), registra em `/var/log/ipbx-sentinel.log` e avisa no Telegram com data/hora, arquivo, motivo e IP de origem (quando aparece no log do Apache).

**Agenda:** `/etc/cron.d/ipbx-sentinel`: a cada 1 min (firewall, dialplan, cron) e a cada 5 min (arquivos alterados nos últimos 10 min). **Não há varredura noturna** (sem alertas de madrugada). A varredura completa roda uma vez na instalação e sob demanda: `ipbx-sentinel --scan`. Limitação: um arquivo malicioso antigo, que não foi modificado recentemente, só é achado nessa varredura completa.

**Comandos:** `ipbx-sentinel --scan | --list | --restore ARQUIVO | --baseline | --test-alert | --selftest | --uninstall`

**Telegram:** crie `/etc/ipbx/telegram.conf` (modo 600) com `TELEGRAM_BOT_TOKEN=` e `TELEGRAM_CHAT_ID=`. Segredos **nunca** vão para o repositório. Sem esse arquivo, o Sentinel reaproveita as credenciais do monitor já instalado.

**Falso positivo:** adicione o caminho em `/etc/ipbx/sentinel.allow` (um por linha, aceita `*`).

**Decisões de segurança:**
- Sem `rm` direto: quarentena permite desfazer erros.
- Sem `chown/chmod -R` no site inteiro: afrouxaria bancos e sessões do painel novo. Opcional: `SENTINEL_HEAL_PERMS=1` apenas remove escrita de "outros".
- Sem `chattr +i` na raiz: impede atualizações e o instalador.
- **Após o incidente:** trocar senhas, revisar rotas internacionais, restringir 80/443 no firewall e **gerar novo token do bot** (o token antigo apareceu no repositório e deve ser considerado exposto).

### 5.1 Monitor único (Sentinel absorveu o monitor_prisma.sh)

O Sentinel agora também faz o que o antigo `monitor_prisma.sh` fazia, em um só agendador (`/etc/cron.d/ipbx-sentinel`):

| Frequência | O que roda | Detalhe |
|---|---|---|
| 1 min (`--host`, leve) | Firewall | Se `iptables`/`firewalld` cair (ou ficar com poucas regras), avisa **uma vez** e reativa (`SENTINEL_FIREWALL_HEAL=0` = só avisa). Avisa de novo quando voltar. |
| 1 min | Dialplan malicioso | `thanku-outcall` / `custom-get-extensions` em `/etc/asterisk/extensions*.conf`. Só alerta, uma vez por achado. |
| 1 min | Crontab suspeito | `asterisk` e `root` com `curl`/`wget`/`php`... Só alerta, uma vez por achado. |
| 5 min | Arquivos alterados e contas | Web shells, módulos falsos, PHP em pastas estáticas, contas/senhas e nomes suspeitos (`atmin`, etc.). |

- **Watchdog sem token:** `/etc/cron.d/ipbx-watchdog` restaura o Sentinel e o cron se alguém apagar e avisa no Telegram. Substitui o watchdog antigo, que tinha o token fixo.
- **Migração automática** (`ipbx-sentinel --install`): copia as credenciais do monitor antigo para `/etc/ipbx/telegram.conf`, remove a linha do crontab e os arquivos `monitor_prisma.sh` / `monitor_issabel_users.sh`.
- Nunca reinicia o Asterisk; o único serviço reativado é o firewall.

