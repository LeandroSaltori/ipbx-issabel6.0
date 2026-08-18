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

## 🛡️ Política de Segurança e Backup Automatizado (`_old`)

Para garantir total segurança e permitir a reversão se necessário, **nenhuma pasta nativa do Issabel é excluída**. 

Toda vez que o script atualiza uma pasta do sistema, a pasta nativa original é renomeada adicionando o sufixo `_old` (ou `_OLD`):

| Diretório Original | Backup Automático Criado |
| :--- | :--- |
| `/var/www/html/admin` | `/var/www/html/admin_old` |
| `/var/www/html/lang` | `/var/www/html/lang_old` |
| `/var/www/html/modules` | `/var/www/html/modules_old` |
| `/usr/local/sbin/motd.sh` | `/usr/local/sbin/motd_old.sh` |
| `/var/www/html/admin/modules/asternic_cdr` | `/var/www/html/admin/modules/asternic_cdr_OLD` |

---

## 📂 Estrutura do Repositório (Arquitetura Padrão)

```
ipbx-issabel6.0/
72: ├── docs/                        # Documentação técnica e manuais de instalação
73: │   ├── autoupdate.md            # Guia do sistema de Atualização Automática Semanal
74: │   ├── asternic_cdr.md          # Guia do módulo Asternic CDR
75: │   ├── painel_ipbx.md           # Guia de instalação do Painel IPbx
76: │   ├── pesquisa_satisfacao_web.md # Guia da Pesquisa de Satisfação
77: │   ├── telegram_monitor.md      # Guia do monitoramento via Telegram
78: │   ├── ldap_directory.md        # Guia do servidor LDAP de ramais
79: │   └── tftp_install.md          # Instruções para servidor TFTP
80: ├── scripts/                     # Scripts de automação e utilitários
81: │   ├── ipbx-autoupdate.sh       # Script de atualização semanal automática e registro de logs
82: │   ├── motd.sh                  # Banner de boas-vindas do terminal SSH
83: │   ├── monitor_issabel_users.sh # Alertas instantâneos no Telegram para novos usuários WEB
84: │   └── update-addressbook       # Utilitário de sincronização da agenda
85: ├── src/                         # Código-fonte, componentes e módulos
86: │   ├── admin/                   # Interface administrativa customizada do Issabel
87: │   ├── dialplan/                # Dialplans Asterisk (ChanSpy, TextMessages, URA, Features)
88: │   │   ├── chanspy.conf
89: │   │   ├── features_general_custom.conf # Ajustes de tempo de transferência e BIP
90: │   │   ├── pesquisa_satisfacao.conf
91: │   │   └── textmessages.conf
92: │   ├── extensions/              # Extensão Chrome (Click-to-Dial / call.php)
93: │   ├── ldap/                    # Servidor LDAP leve (porta 10389) e serviço systemd
94: │   ├── modules/                 # Módulos WEB do Issabel (Asternic, Painel, Queue Stats, Dev)
95: │   ├── ramais/                  # Interface API e testes de ramais AMI
96: │   ├── sounds/                  # Áudios do sistema (MOH Músicas de Espera e URA Custom)
97: │   ├── themes/                  # Temas visuais (Prisma v5)
98: │   ├── webphone/                # Softphone WebRTC integrado no navegador
99: │   ├── Agenda.php               # Agenda telefônica WEB
100: │   ├── favicon.ico              # Favicon customizado
101: │   └── lang/                    # Arquivos de tradução pt-br
102: ├── install.sh                   # Script mestre de instalação automatizada (20 etapas)
103: ├── README.md                    # Manual mestre do repositório
104: └── LICENSE                      # Licença open-source
105: ```
106: 
107: ---
108: 
109: ## ✨ Recursos Adicionados da Versão Prisma Telecom
110: 
111: 1. 🔍 **TCPDump:** Ferramenta para captura e diagnóstico de pacotes de rede no servidor.
112: 2. 📞 **SNGREP:** Analisador gráfico interativo de chamadas SIP no terminal SSH.
113: 3. 🛜 **NMTUI (NetworkManager-tui):** Interface amigável em modo texto no terminal para configuração rápida de placas de rede, IPs e Gateway.
114: 4. ⏱️ **Tempo de Transferência de Chamadas:** Aumentado o tempo limite de digitação de dígitos para 7 segundos (`transferdigittimeout = 7`) e tempo limite de resposta em transferência assistida para 30 segundos (`atxfernoanswertimeout = 30`).
115: 5. 🔔 **BIP de Transferência:** Tom de cortesia e BIP emitidos ao completar transferências (`courtesytone = beep` / `xfersound = beep`).
116: 6. 🔄 **Atualização Automática Semanal (Auto Update):** Rotina semanal (`scripts/ipbx-autoupdate.sh`) via cron (`/etc/cron.weekly/ipbx-autoupdate`). Atualiza o repositório via `git pull` e executa o `install.sh` com geração de logs detalhados na pasta do cliente (`autoupdate.log` e `autoupdate_last_status.txt`).
117: 7. ⏱️ **Resumo Flutuante com Retenção (2s Hover):** Os cards de resumo nos relatórios do sistema possuem um atraso de 2 segundos mantendo o mouse parado na linha antes de abrir, evitando popups acidentais durante a navegação.
118: 
119: ---
120: 
121: ## 🔍 Guia de Uso e Documentação por Módulo
122: 
123: Para instruções específicas de configuração de módulos individuais, acesse a pasta [`docs/`](./docs/):
124: 
125: - 🔄 [Atualização Automática Semanal (Auto Update & Logs)](./docs/autoupdate.md)
126: - 📊 [Asternic CDR - Relatórios de Chamadas](./docs/asternic_cdr.md)
127: - 🎛️ [Painel IPbx - Monitoramento Visual de Ramais](./docs/painel_ipbx.md)
128: - 📝 [Pesquisa de Satisfação WEB & URA](./docs/pesquisa_satisfacao_web.md)
129: - 📲 [Monitoramento de Usuários via Telegram](./docs/telegram_monitor.md)
130: - 🗂️ [Servidor LDAP para Telefones IP](./docs/ldap_directory.md)
131: - 📞 [Instalação TFTP](./docs/tftp_install.md)
132: 
133: ---
134: 
135: ## 🛠️ Suporte & Desenvolvimento
136: 
137: - **Autor:** Leandro Saltori - Prisma Telecom
138: - **Repositório:** [LeandroSaltori/ipbx-issabel6.0](https://github.com/LeandroSaltori/ipbx-issabel6.0)