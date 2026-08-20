# 🚀 IPBX Issabel PBX Customizado - Prisma Telecom

Este repositório contém a suíte completa de customizações, módulos avançados, temas visuais, correções e ferramentas de monitoramento para o **Issabel PBX** (versões 4 e 5).

Com este repositório, você não precisa realizar nenhuma alteração manual após instalar o Issabel básico. Basta executar um **único comando** no terminal para transformar seu servidor em uma central IPBX 100% personalizada.

---

## ⚡ Instalação em 1 Comando (Recomendado)

Conecte-se ao seu servidor Issabel via **SSH como `root`** e execute o comando abaixo:

```bash
curl -sSL https://raw.githubusercontent.com/LeandroSaltori/ipbx-issabel6.0/main/install.sh | bash
```

> **Nota:** Caso já tenha clonado o repositório no servidor, você também pode executar localmente:
> ```bash
> chmod +x install.sh
> ./install.sh
> ```

---

## 🎛️ Atualização Modular via Menu Interativo (Novo)

Para ambientes em produção ou versões não-padrão do Issabel/Asterisk onde você prefere atualizar os módulos **um a um** com total controle:

```bash
# Se já executou a instalação ou possui o comando no sistema:
ipbx-update

# Ou execute diretamente via curl:
curl -sSL https://raw.githubusercontent.com/LeandroSaltori/ipbx-issabel6.0/main/ipbx-menu.sh | bash
```

O menu interativo permite escolher exatamente o que deseja atualizar, recarrega os serviços e retorna ao menu para a próxima ação:

```text
╔══════════════════════════════════════════════════════════════════════╗
║       IPBX PRISMA TELECOM - MENU DE ATUALIZAÇÃO MODULAR              ║
╠══════════════════════════════════════════════════════════════════════╣
║  APARÊNCIA E INTERFACE                                               ║
║   [1]  Terminal (MOTD)              [2]  Tema e Favicon              ║
║   [3]  Painel Admin                [4]  Traduções (lang)             ║
║                                                                      ║
║  MÓDULOS E FUNCIONALIDADES                                           ║
║   [5]  Módulos Web (todos)         [6]  Agenda Telefônica            ║
║   [7]  Webphone WebRTC             [8]  Click-to-Dial                ║
║   [9]  Painel IPbx                 [10] Nome dos Ramais              ║
║                                                                      ║
║  RELATÓRIOS                                                          ║
║   [11] Relatório Geral (CDR)       [12] Relatório de Filas           ║
║   [13] Relatórios Extras           [14] Pesquisa de Satisfação       ║
║                                                                      ║
║  CALL CENTER E COMUNICAÇÃO                                           ║
║   [15] Call Center                 [16] ChanSpy (Escuta)             ║
║   [17] Mensagens Texto (PJSIP)     [18] Servidor LDAP                ║
║                                                                      ║
║  SISTEMA E CONFIGURAÇÕES                                             ║
║   [19] Música de Espera (MOH)      [20] Telegram (Notificações)      ║
║   [21] Ferramentas Diagnóstico     [22] Features Asterisk            ║
║   [23] PJSIP User-Agent            [24] Auto-Update Semanal          ║
║   [25] Web Developer               [26] Instalar Rollback            ║
║                                                                      ║
╠══════════════════════════════════════════════════════════════════════╣
║   [A]  INSTALAR TUDO (igual ao install.sh completo)                  ║
║   [0]  Sair                                                          ║
╚══════════════════════════════════════════════════════════════════════╝
```

---

## 🐧 Instalação do Issabel 5 / 6 do Zero no Rocky Linux (Netinstall)

Caso esteja configurando um servidor limpo a partir do **Rocky Linux 8 ou 9** do zero via terminal, siga o procedimento de instalação oficial e pós-customização:

### Passo 1: Instalação do PBX Issabel 5 no Rocky Linux 8
Conecte-se via SSH no servidor Rocky Linux 8 como `root` e execute:

```bash
# 1. Atualizar pacotes do Rocky Linux 8
dnf update -y

# 2. Instalar wget e git
dnf install -y wget git

# 3. Executar o instalador do Issabel 5
curl http://repo.issabel.org/issabel5-netinstall.sh | bash
```

### Passo 2: Aplicação do Pacote IPBX Prisma Telecom
Após o término do script Netinstall e o reboot do servidor, execute o comando abaixo para aplicar todas as customizações, temas e relatórios:

```bash
curl -sSL https://raw.githubusercontent.com/LeandroSaltori/ipbx-issabel6.0/main/install.sh | bash
```

---

## 🛡️ Política de Segurança e Snapshots com Data/Hora

Em ambientes de produção, **nenhum dado de cliente, histórico de chamadas (CDR) ou gravação é apagado**. 

A cada execução do `ipbx-update` (menu modular) ou do `install.sh`:
1. Um snapshot completo e isolado é criado em `/var/backup/ipbx/backup_YYYY-MM-DD_HHMMSS/`.
2. O snapshot guarda o estado exato dos arquivos web, bancos SQLite (`menu.db`, `acl.db`), configs do Asterisk (`.conf`) e agendamentos do crontab.
3. Um arquivo `manifesto.txt` registra o módulo alterado, a data e a hora exatas.
4. O link `/var/backup/ipbx/latest` aponta sempre para o ponto de restauração mais recente.

---

## 🔄 Rollback Versionado (Restaurar por Data)

Se após qualquer alteração você precisar reverter o PBX para o estado anterior, basta executar:

```bash
ipbx-rollback
```

### Modos de Uso do Rollback

| Comando | Descrição |
| :--- | :--- |
| `ipbx-rollback` | Abre a lista de datas disponíveis e permite escolher qual restaurar (ou ENTER para o último) |
| `ipbx-rollback --latest` | Restaura imediatamente o snapshot **mais recente** |
| `ipbx-rollback --list` | Apenas **lista** os pontos de restauração com data, hora e descrição |
| `ipbx-rollback --dry-run` | **Simula** o que seria restaurado sem alterar nenhum arquivo |

> **Caso o comando não esteja instalado:**
> ```bash
> curl -sSL https://raw.githubusercontent.com/LeandroSaltori/ipbx-issabel6.0/main/rollback.sh | bash
> ```

---

## 📂 Estrutura do Repositório (Arquitetura Padrão)

```
ipbx-issabel6.0/
├── docs/                        # Documentação técnica e manuais de instalação
│   ├── autoupdate.md            # Guia do sistema de Atualização Automática Semanal
│   ├── asternic_cdr.md          # Guia do módulo Asternic CDR
│   ├── painel_ipbx.md           # Guia de instalação do Painel IPbx
│   ├── pesquisa_satisfacao_web.md # Guia da Pesquisa de Satisfação
│   ├── telegram_monitor.md      # Guia do monitoramento via Telegram
│   ├── ldap_directory.md        # Guia do servidor LDAP de ramais
│   └── tftp_install.md          # Instruções para servidor TFTP
├── scripts/                     # Scripts de automação e utilitários
│   ├── auto_dominio.sh          # Assistente de Domínio e Certificado SSL Let's Encrypt + WebRTC
│   ├── limpa_logs.sh            # Otimização de disco e limpeza segura de logs (preserva CDRs)
│   ├── monitor_prisma.sh        # Monitor de segurança (Web shells/Emad, Firewall e Usuários WEB)
│   ├── ipbx-autoupdate.sh       # Script de atualização semanal automática e registro de logs
│   ├── motd.sh                  # Banner de boas-vindas do terminal SSH
│   └── update-addressbook       # Utilitário de sincronização da agenda
├── src/                         # Código-fonte, componentes e módulos
│   ├── admin/                   # Interface administrativa customizada do Issabel
│   ├── dialplan/                # Dialplans Asterisk (ChanSpy, TextMessages, URA, Features)
│   │   ├── chanspy.conf
│   │   ├── features_general_custom.conf # Ajustes de tempo de transferência e BIP
│   │   ├── pesquisa_satisfacao.conf
│   │   └── textmessages.conf
│   ├── extensions/              # Extensão Chrome (Click-to-Dial / call.php)
│   ├── ldap/                    # Servidor LDAP leve (porta 10389) e serviço systemd
│   ├── modules/                 # Módulos WEB do Issabel (Asternic, Painel, Queue Stats, Dev)
│   ├── ramais/                  # Interface API e testes de ramais AMI
│   ├── sounds/                  # Áudios do sistema (MOH Músicas de Espera e URA Custom)
│   ├── themes/                  # Temas visuais (Prisma v5)
│   ├── webphone/                # Softphone WebRTC integrado no navegador
│   ├── agenda.php               # Agenda telefônica WEB
│   ├── favicon.ico              # Favicon customizado
│   └── lang/                    # Arquivos de tradução pt-br
├── install.sh                   # Script mestre de instalação automatizada (20 etapas)
├── ipbx-menu.sh                 # Menu interativo de atualização modular (ipbx-update)
├── rollback.sh                  # Script de rollback versionado por data/hora (ipbx-rollback)
├── README.md                    # Manual mestre do repositório
└── LICENSE                      # Licença open-source
```

---

## ✨ Recursos Adicionados da Versão Prisma Telecom

1. 🔍 **TCPDump:** Ferramenta para captura e diagnóstico de pacotes de rede no servidor.
2. 📞 **SNGREP:** Analisador gráfico interativo de chamadas SIP no terminal SSH.
3. 🛜 **NMTUI (NetworkManager-tui):** Interface amigável em modo texto no terminal para configuração rápida de placas de rede, IPs e Gateway.
4. ⏱️ **Tempo de Transferência de Chamadas:** Aumentado o tempo limite de digitação de dígitos para 7 segundos (`transferdigittimeout = 7`) e tempo limite de resposta em transferência assistida para 30 segundos (`atxfernoanswertimeout = 30`).
5. 🔔 **BIP de Transferência:** Tom de cortesia e BIP emitidos ao completar transferências (`courtesytone = beep` / `xfersound = beep`).
6. 🔒 **Domínio e SSL Automático (`auto_dominio.sh`):** Emite certificado SSL Let's Encrypt com 1 comando e sincroniza chaves WSS com o Webphone.
7. 🧹 **Limpeza de Logs e Otimização de Disco (`limpa_logs.sh`):** Esvazia logs >50MB e limpa temporários sem apagar histórico CDR de ligações.
8. 🚨 **Monitor de Segurança Telegram (`monitor_prisma.sh`):** Alertas em tempo real de web shells, queda de Firewall e novos usuários WEB.
9. 🔄 **Atualização Automática Semanal (Auto Update):** Rotina semanal (`scripts/ipbx-autoupdate.sh`) via cron (`/etc/cron.weekly/ipbx-autoupdate`).
10. ⏱️ **Resumo Flutuante com Retenção (2s Hover):** Os cards de resumo nos relatórios do sistema possuem um atraso de 2 segundos mantendo o mouse parado na linha antes de abrir, evitando popups acidentais durante a navegação.

---

## 🛠️ Scripts Utilitários e Comandos Rápidos

O repositório disponibiliza diversos scripts independentes na pasta [`scripts/`](./scripts/), que podem ser executados diretamente ou via seus comandos instalados no sistema:

| Comando / Script | O que faz | Como executar avulso |
| :--- | :--- | :--- |
| **`ipbx-update`**<br>([`ipbx-menu.sh`](./ipbx-menu.sh)) | **Menu Interativo Modular:** Permite atualizar módulo por módulo com segurança e snapshots automáticos por data. | `ipbx-update`<br>*ou* `curl -sSL .../ipbx-menu.sh \| bash` |
| **`ipbx-rollback`**<br>([`rollback.sh`](./rollback.sh)) | **Rollback por Data/Hora:** Restaura o PBX para o estado anterior exato de qualquer data/hora gravada. | `ipbx-rollback`<br>*ou* `ipbx-rollback --latest` |
| **`ipbx-ssl`**<br>([`scripts/auto_dominio.sh`](./scripts/auto_dominio.sh)) | **Domínio e SSL Let's Encrypt:** Cria VirtualHost no Apache, emite certificado SSL gratuito e integra chaves com o Webphone WebRTC. | `bash scripts/auto_dominio.sh <dominio> <email>` |
| **`ipbx-limpalogs`**<br>([`scripts/limpa_logs.sh`](./scripts/limpa_logs.sh)) | **Limpeza Segura de Logs e Disco:** Trunca logs >50MB, apaga logs antigos rotacionados e limpa cache Smarty. **Nunca apaga CDRs nem gravações.** | `bash scripts/limpa_logs.sh` |
| **`monitor_prisma.sh`**<br>([`scripts/monitor_prisma.sh`](./scripts/monitor_prisma.sh)) | **Monitor de Segurança Telegram:** Detecta web shells/Emad em `/var/www/html/`, monitora Firewall (iptables/firewalld) e alerta novos usuários Web criados. | `bash scripts/monitor_prisma.sh` |
| **`ipbx-autoupdate`**<br>([`scripts/ipbx-autoupdate.sh`](./scripts/ipbx-autoupdate.sh)) | **Atualização Automática Semanal:** Executado via cron semanal para sincronizar atualizações e gerar logs locais. | `bash scripts/ipbx-autoupdate.sh` |
| **`motd.sh`**<br>([`scripts/motd.sh`](./scripts/motd.sh)) | **Banner do Terminal SSH:** Tela personalizada de boas-vindas ao logar como root no terminal. | `bash scripts/motd.sh` |

---

## 🔍 Guia de Uso e Documentação por Módulo

Para instruções detalhadas de configuração de cada recurso, acesse os manuais na pasta [`docs/`](./docs/):

- 🔒 [Configuração de Domínio e SSL Let's Encrypt (`auto_dominio.sh`)](./docs/auto_dominio_ssl.md)
- 🧹 [Limpeza de Logs e Otimização de Disco (`limpa_logs.sh`)](./docs/limpa_logs.md)
- 🚨 [Monitor de Segurança & Alertas Telegram (`monitor_prisma.sh`)](./docs/monitor_seguranca_telegram.md)
- 🕵️ [Detecção e Remoção do Invasor Emad (`invador_emad.md`)](./docs/invador_emad.md)
- 🔄 [Atualização Automática Semanal (Auto Update & Logs)](./docs/autoupdate.md)
- 📊 [Asternic CDR - Relatórios de Chamadas](./docs/asternic_cdr.md)
- 🎛️ [Painel IPbx - Monitoramento Visual de Ramais](./docs/painel_ipbx.md)
- 📝 [Pesquisa de Satisfação WEB & URA](./docs/pesquisa_satisfacao_web.md)
- 🗂️ [Servidor LDAP para Telefones IP](./docs/ldap_directory.md)
- 📞 [Instalação TFTP](./docs/tftp_install.md)
- 🔗 [Alteração de Links no Menu do Issabel](./docs/alterar_link.md)
- 🔄 [Rollback Versionado por Data/Hora](#-rollback-versionado-restaurar-por-data)

---

## 🛠️ Suporte & Desenvolvimento

- **Autor:** Leandro Saltori - Prisma Telecom
- **Repositório:** [LeandroSaltori/ipbx-issabel6.0](https://github.com/LeandroSaltori/ipbx-issabel6.0)