# 🚨 Monitor de Segurança & Alertas Telegram (IPbx Sentinel)

> **Monitor único.** O antigo `monitor_prisma.sh` foi unificado no **IPbx Sentinel** (`scripts/ipbx-sentinel.sh`). Ao rodar a opção **[20]** do menu (ou o `install.sh`), o Sentinel é instalado, as credenciais do Telegram do monitor antigo são copiadas para `/etc/ipbx/telegram.conf` (modo 600) e o cron/arquivos antigos são removidos. O token **não fica mais dentro de scripts**: edite `/etc/ipbx/telegram.conf` (`TELEGRAM_BOT_TOKEN=` / `TELEGRAM_CHAT_ID=`) e teste com `ipbx-sentinel --test-alert`. Detalhes completos em [invador_emad.md](./invador_emad.md).

O **IPbx Sentinel** (`scripts/ipbx-sentinel.sh`) é o monitor único de segurança do servidor. Ele envia **notificações via Telegram** quando detecta anomalias e, nos casos de arquivo, move o item para uma quarentena reversível.

---

## 🎯 O que o monitor verifica

| Frequência | Verificação | Ação |
|---|---|---|
| 1 min | **Firewall** (`iptables`/`firewalld` parado, ou ativo mas praticamente sem regras, o que deixa o servidor aberto) | Avisa uma vez e avisa quando voltar. Só avisa por padrão, pois o firewall é gerido pela tela do Issabel; para tentar reiniciar o serviço: `SENTINEL_FIREWALL_HEAL=1` |
| 1 min | **Dialplan malicioso** (`thanku-outcall`, `custom-get-extensions`) | Só alerta |
| 1 min | **Crontab suspeito** (`asterisk` e `root`) | Só alerta |
| 1 min | **Versão vulnerável do painel** (aviso oficial Issabel de 02/08/2026: `issabel-framework` ≤ 4.0.0-12 no Issabel 4 ou ≤ 5.0.0-3 no Issabel 5 permite controle remoto) | Alerta uma vez por versão. A correção é automática na instalação (opção [20]/`install.sh`) ou com `ipbx-sentinel --update-framework` |
| 5 min | **Web shells e invasões** em `/var/www/html` (assinaturas `Emad__Was__Here`, `eval` ofuscado em cadeia, `eval(base64_decode`, `c99shell`, `r57shell`...) | Quarentena reversível + alerta com data, arquivo e IP de origem |
| 5 min | **Módulos falsos** (`phones`, `h`, `_cache`, `tinfo`, `fpbxphones`) e **PHP em pastas de imagem** | Quarentena + alerta |
| 5 min | **Contas e senhas**: usuário novo, senha/permissão alterada ou removido (painel `acl.db`, FreePBX, MySQL, `/etc/issabel.conf`, `/etc/amportal.conf`, `/etc/freepbx.conf`) **nomes suspeitos** (`atmin`, `emad`...) e **`root` do MySQL liberado para qualquer origem** (`root@%`, acesso remoto total ao banco) | Só alerta (nunca desfaz) |

- Não há varredura noturna (sem alertas de madrugada). A varredura completa roda **uma vez na instalação** e **sob demanda** (`ipbx-sentinel --scan`).
- Se o Sentinel não conseguir ler uma fonte de contas (sem `sqlite3`, sem `mysql` ou senha do root diferente de `/etc/issabel.conf`), ele registra um AVISO no log (`/var/log/ipbx-sentinel.log`) e `ipbx-sentinel --status` mostra qual está cega.
- Cada achado é avisado **uma única vez** (sem repetir a cada minuto).
- O Sentinel nunca reinicia o Asterisk; o firewall só é reiniciado se `SENTINEL_FIREWALL_HEAL=1`.
- **Watchdog:** se alguém apagar o Sentinel ou o agendamento, ele é restaurado automaticamente e o Telegram avisa.

---

## 🚀 Como instalar e usar

### Via menu interativo
```bash
ipbx-update
# Escolha a opção [20] Monitor Segurança Telegram
```
A opção [20] roda o hardening de segurança e instala o Sentinel. O `install.sh` também o instala.

### Comandos do dia a dia
```bash
ipbx-sentinel --scan          # varredura completa agora
ipbx-sentinel --test-alert    # teste do Telegram
ipbx-sentinel --list          # itens em quarentena
ipbx-sentinel --restore ARQ   # restaura um arquivo da quarentena
ipbx-sentinel --baseline      # refaz a foto de contas/senhas (servidor limpo)
ipbx-sentinel --audit [DIAS] # auditoria forense somente-leitura (veja abaixo)
ipbx-sentinel --status        # mostra se a vigilancia de contas consegue ler painel, MySQL e arquivos
ipbx-sentinel --selftest      # autoteste do detector
```

---

## ⚙️ Configuração

- **Telegram:** `/etc/ipbx/telegram.conf` (modo 600):
  ```bash
  TELEGRAM_BOT_TOKEN="..."
  TELEGRAM_CHAT_ID="..."
  ```
  O token **nunca** fica dentro de scripts do repositório. Se o servidor vinha do monitor antigo (`monitor_prisma.sh`), a instalação copia as credenciais dele para esse arquivo e remove o monitor antigo. Depois, troque o token no BotFather.
- **Opções:** `/etc/ipbx/sentinel.conf` (`SENTINEL_ACTION=quarantine|alert`, `SENTINEL_FIREWALL_HEAL=0|1` (padrão 0), `SENTINEL_HEAL_PERMS=0|1`).
- **Exceções (falso positivo):** `/etc/ipbx/sentinel.allow` (um caminho por linha).
- O nome do servidor nos alertas é o **hostname** da máquina.

---

## 🛡️ Aviso oficial Issabel de 02/08/2026 (crítico)

Há uma campanha automatizada explorando o `issabel-framework` (Issabel 4 ≤ 4.0.0-12; Issabel 5 ≤ 5.0.0-3). O Sentinel identifica a versão do servidor e **corrige automaticamente** ao ser instalado (opção **[20]** do menu, `install.sh` ou `ipbx-sentinel --install`):

1. Detecta Issabel 4 ou 5 pela versão do `issabel-framework` instalado (se não estiver na faixa vulnerável, não faz nada).
2. Cria o **snapshot** em `/var/backup/ipbx/backup_AAAA-MM-DD_HHMMSS` (manifesto, bancos do painel, configs do Asterisk, crontab, lista de pacotes) e atualiza o link `latest`.
3. Atualiza só o pacote, pelo repositório oficial correto:
   - Issabel 4: `yum --disablerepo=* --enablerepo=issabel-updates update issabel-framework`
   - Issabel 5: `yum --disablerepo=* --enablerepo=issabel-cinco-updates update issabel-framework`
4. Avisa no Telegram o resultado (sucesso ou falha, com o comando manual).

Manual a qualquer momento: `ipbx-sentinel --update-framework`. Para desativar a correção automática na instalação: `SENTINEL_AUTO_UPDATE_FRAMEWORK=0` em `/etc/ipbx/sentinel.conf` (o alerta continua).

- A atualização **impede novas invasões, mas não remove** malware instalado antes: depois, rode `ipbx-sentinel --scan` e revise contas (`--baseline` só com o servidor limpo).
- Não exponha o servidor diretamente à Internet; troque as senhas administrativas se houver suspeita de acesso indevido; use apenas repositórios oficiais.
- O Asterisk não é reiniciado pelo Sentinel; confira se o `yum` não reinicia serviços no seu ambiente.

## 🖥️ Painel novo (`/front_api/`) exige PHP 7.0 ou superior

O `instalar-front-api.sh` verifica o PHP antes de instalar. Em PHP antigo (Issabel 4 = PHP 5.4) ele **cancela sem alterar nada**. Se um painel anterior foi sobrescrito por engano: `bash /var/www/html/front_api/instalar-front-api.sh --rollback`.

---

## 🔎 Auditoria pós-incidente (`ipbx-sentinel --audit [DIAS]`)

Somente leitura (não altera nada). Cobre o que um invasor costuma deixar além dos arquivos web, e salva o relatório em `/var/log/ipbx-sentinel-audit-*.txt`. Pontos marcados com `[!]` precisam de revisão humana:

1. Contas do sistema (UID 0 extra, shells de login, **chaves SSH** com fingerprint, `sudo` sem senha, `sshd_config`).
2. Agendamentos suspeitos (`curl`, `wget`, `base64`, `/tmp`, `/dev/shm`...) em crontabs, `/etc/cron.*`.
3. Processos rodando de `/tmp`, `/dev/shm` ou binário apagado; maiores consumidores de CPU.
4. Portas abertas e portas sensíveis expostas (3306, 5038, 6379...).
5. Persistência/rootkit: `/etc/ld.so.preload`, `rc.local`, serviços systemd novos, arquivos suspeitos em áreas temporárias.
6. Binários do sistema diferentes do pacote original (`rpm -Va`).
7. Arquivos alterados nos últimos N dias (padrão 30) em `/etc`, `/usr/local`, `/opt`, `/root`, cron e AGI.
8. **Origem do ataque**: data de modificação mais antiga dos arquivos em quarentena (quando o invasor entrou, no máximo) e IPs que acessaram esses arquivos nos logs do Apache.
9. Asterisk: AMI aberto para qualquer origem e dialplan suspeito.

Limites: o `last` e o `wtmp` só mostram logins SSH/console; uma invasão por falha web (RCE) não aparece ali. Logs rotacionados podem não retroceder até a data da invasão. Depois de um RCE com possível rootkit, reinstalar a partir de backup limpo continua sendo o caminho mais seguro.
