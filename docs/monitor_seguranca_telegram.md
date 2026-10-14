# 🚨 Monitor de Segurança & Alertas Telegram (IPbx Sentinel)

> **Monitor único.** O antigo `monitor_prisma.sh` foi unificado no **IPbx Sentinel** (`scripts/ipbx-sentinel.sh`). Ao rodar a opção **[20]** do menu (ou o `install.sh`), o Sentinel é instalado, as credenciais do Telegram do monitor antigo são copiadas para `/etc/ipbx/telegram.conf` (modo 600) e o cron/arquivos antigos são removidos. O token **não fica mais dentro de scripts**: edite `/etc/ipbx/telegram.conf` (`TELEGRAM_BOT_TOKEN=` / `TELEGRAM_CHAT_ID=`) e teste com `ipbx-sentinel --test-alert`. Detalhes completos em [invador_emad.md](./invador_emad.md).

O **IPbx Sentinel** (`scripts/ipbx-sentinel.sh`) é o monitor único de segurança do servidor. Ele envia **notificações via Telegram** quando detecta anomalias e, nos casos de arquivo, move o item para uma quarentena reversível.

---

## 🎯 O que o monitor verifica

| Frequência | Verificação | Ação |
|---|---|---|
| 1 min | **Firewall** (`iptables`/`firewalld` parado ou com poucas regras) | Avisa uma vez, **reativa** o firewall e avisa quando voltar |
| 1 min | **Dialplan malicioso** (`thanku-outcall`, `custom-get-extensions`) | Só alerta |
| 1 min | **Crontab suspeito** (`asterisk` e `root`) | Só alerta |
| 1 min | **Versão vulnerável do painel** (aviso oficial Issabel de 02/08/2026: `issabel-framework` ≤ 4.0.0-12 no Issabel 4 ou ≤ 5.0.0-3 no Issabel 5 permite controle remoto) | Só alerta, uma vez por versão, com o comando de atualização. **Não atualiza sozinho** |
| 5 min | **Web shells e invasões** em `/var/www/html` (assinaturas `Emad__Was__Here`, `eval` ofuscado em cadeia, `eval(base64_decode`, `c99shell`, `r57shell`...) | Quarentena reversível + alerta com data, arquivo e IP de origem |
| 5 min | **Módulos falsos** (`phones`, `h`, `_cache`, `tinfo`, `fpbxphones`) e **PHP em pastas de imagem** | Quarentena + alerta |
| 5 min | **Contas e senhas**: usuário novo, senha/permissão alterada ou removido (painel `acl.db`, FreePBX, MySQL, `/etc/issabel.conf`, `/etc/amportal.conf`, `/etc/freepbx.conf`) e **nomes suspeitos** (`atmin`, `emad`...) | Só alerta (nunca desfaz) |

- Não há varredura noturna (sem alertas de madrugada). A varredura completa roda **uma vez na instalação** e **sob demanda** (`ipbx-sentinel --scan`).
- Cada achado é avisado **uma única vez** (sem repetir a cada minuto).
- O Sentinel nunca reinicia o Asterisk; o único serviço reativado é o firewall.
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
- **Opções:** `/etc/ipbx/sentinel.conf` (`SENTINEL_ACTION=quarantine|alert`, `SENTINEL_FIREWALL_HEAL=1|0`, `SENTINEL_HEAL_PERMS=0|1`).
- **Exceções (falso positivo):** `/etc/ipbx/sentinel.allow` (um caminho por linha).
- O nome do servidor nos alertas é o **hostname** da máquina.

---

## 🛡️ Aviso oficial Issabel de 02/08/2026 (crítico)

Há uma campanha automatizada explorando o `issabel-framework`. A correção é a atualização do pacote (faça snapshot/backup antes):

```bash
# Issabel 4
yum --disablerepo=* --enablerepo=issabel-updates update issabel-framework
# Issabel 5
yum --disablerepo=* --enablerepo=issabel-cinco-updates update issabel-framework
```

- A atualização **impede novas invasões, mas não remove** malware instalado antes: depois de atualizar, rode `ipbx-sentinel --scan` e revise contas (`--baseline` só com o servidor limpo).
- Não exponha o servidor diretamente à Internet; troque as senhas administrativas se houver suspeita de acesso indevido; use apenas repositórios oficiais.
- O Sentinel avisa no Telegram se o servidor ainda estiver numa versão afetada.
