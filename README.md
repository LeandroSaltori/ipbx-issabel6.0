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

### Passo 1: Instalação do PBX Issabel no Rocky Linux
Conecte-se via SSH no servidor Rocky Linux como `root` e execute:

```bash
# Atualiza os repositórios e instala o pacote wget
dnf update -y && dnf install -y wget git

# Executa o instalador oficial Netinstall do Issabel 5/6
wget -O - http://repo.issabel.org/issabel-netinstall.sh | bash
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
├── docs/                        # Documentação técnica e manuais de instalação
│   ├── asternic_cdr.md          # Guia do módulo Asternic CDR
│   ├── painel_ipbx.md           # Guia de instalação do Painel IPbx
│   ├── pesquisa_satisfacao_web.md # Guia da Pesquisa de Satisfação
│   ├── telegram_monitor.md      # Guia do monitoramento via Telegram
│   ├── ldap_directory.md        # Guia do servidor LDAP de ramais
│   └── tftp_install.md          # Instruções para servidor TFTP
├── scripts/                     # Scripts de automação e utilitários
│   ├── motd.sh                  # Banner de boas-vindas do terminal SSH
│   ├── monitor_issabel_users.sh # Alertas instantâneos no Telegram para novos usuários WEB
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
│   ├── Agenda.php               # Agenda telefônica WEB
│   ├── favicon.ico              # Favicon customizado
│   └── lang/                    # Arquivos de tradução pt-br
├── install.sh                   # Script mestre de instalação automatizada (19 etapas)
├── README.md                    # Manual mestre do repositório
└── LICENSE                      # Licença open-source
```

---

## ✨ Recursos Adicionados da Versão Prisma Telecom

1. 🔍 **TCPDump:** Ferramenta para captura e diagnóstico de pacotes de rede no servidor.
2. 📞 **SNGREP:** Analisador gráfico interativo de chamadas SIP no terminal SSH.
3. ⏱️ **Tempo de Transferência de Chamadas:** Aumentado o tempo limite de digitação de dígitos para 7 segundos (`transferdigittimeout = 7`) e tempo limite de resposta em transferência assistida para 30 segundos (`atxfernoanswertimeout = 30`).
4. 🔔 **BIP de Transferência:** Tom de cortesia e BIP emitidos ao completar transferências (`courtesytone = beep` / `xfersound = beep`).

---

## 🔍 Guia de Uso e Documentação por Módulo

Para instruções específicas de configuração de módulos individuais, acesse a pasta [`docs/`](./docs/):

- 📊 [Asternic CDR - Relatórios de Chamadas](./docs/asternic_cdr.md)
- 🎛️ [Painel IPbx - Monitoramento Visual de Ramais](./docs/painel_ipbx.md)
- 📝 [Pesquisa de Satisfação WEB & URA](./docs/pesquisa_satisfacao_web.md)
- 📲 [Monitoramento de Usuários via Telegram](./docs/telegram_monitor.md)
- 🗂️ [Servidor LDAP para Telefones IP](./docs/ldap_directory.md)
- 📞 [Instalação TFTP](./docs/tftp_install.md)

---

## 🛠️ Suporte & Desenvolvimento

- **Autor:** Leandro Saltori - Prisma Telecom
- **Repositório:** [LeandroSaltori/ipbx-issabel6.0](https://github.com/LeandroSaltori/ipbx-issabel6.0)