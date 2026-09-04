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
║                                                                      ║
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
║  SISTEMA, SEGURANÇA E REDE                                           ║
║   [19] Música de Espera (MOH)      [20] Monitor Segurança Telegram   ║
║   [21] Ferramentas Diagnóstico     [22] Features Asterisk            ║
║   [23] PJSIP User-Agent            [24] Auto-Update Semanal          ║
║   [25] Web Developer               [26] Configurar Domínio e SSL     ║
║   [27] Limpeza de Logs e Disco     [28] Servidor OpenVPN (EasyVPN)   ║
║   [29] Rollback (Restauro/Instalar)[30] Data/Hora e NTP (São Paulo)  ║
║                                                                      ║
╠══════════════════════════════════════════════════════════════════════╣
║   [A]  INSTALAR TUDO (igual ao install.sh completo)                  ║
║   [0]  Sair                                                          ║
╚══════════════════════════════════════════════════════════════════════╝
```

---

## 📖 Guia Completo: O que faz cada opção do Menu Modular

| Opção | Nome da Opção | O que faz no Sistema | Arquivos / Pastas Afetados |
| :---: | :--- | :--- | :--- |
| **`[1]`** | **Terminal (MOTD)** | Instala a tela de boas-vindas do terminal SSH com logotipo da Prisma Telecom, status de serviços, IPs e contatos de suporte. | `scripts/motd.sh` ➔ `/etc/profile.d/motd.sh` |
| **`[2]`** | **Tema e Favicon** | Aplica o tema escuro moderno **Prisma v5** (glassmorphism/paleta escura), define o favicon customizado e ativa o tema em `settings.db`. | `src/themes/prisma_v5/`, `src/favicon.ico` |
| **`[3]`** | **Painel Admin** | Atualiza os scripts da interface administrativa do IssabelPBX/FreePBX, corrigindo compatibilidade com PHP 7/8 e sessões web. | `src/admin/` ➔ `/var/www/html/admin/` |
| **`[4]`** | **Traduções (lang)** | Instala os dicionários traduzidos para Português do Brasil (`pt-br.lang`), corrigindo termos em espanhol/inglês. | `src/lang/` ➔ `/var/www/html/lang/` |
| **`[5]`** | **Módulos Web (todos)** | Atualiza todos os módulos web do Issabel de uma só vez, aplicando permissões `asterisk:asterisk`. | `src/modules/` ➔ `/var/www/html/modules/` |
| **`[6]`** | **Agenda Telefônica** | Instala a interface web da Agenda Telefônica e sincroniza o banco SQLite com a lista de discagem rápida. | `src/agenda.php`, `scripts/update-addressbook` |
| **`[7]`** | **Webphone WebRTC** | Instala o softphone WebRTC no navegador com suporte a WSS (porta 8089), PWA e codecs Opus/G.711. | `src/webphone/` ➔ `/var/www/html/webphone/` |
| **`[8]`** | **Click-to-Dial** | Configura a API de discagem rápida no PBX e disponibiliza a extensão para navegadores Chrome/Edge. | `src/extensions/` ➔ `/var/www/html/` |
| **`[9]`** | **Painel IPbx** | Instala e atualiza o painel visual com status de ramais, troncos e filas em tempo real. | `src/modules/control_panel/`, `_issabelpanel/` |
| **`[10]`** | **Nome dos Ramais** | Instala o módulo para personalização e exibição de apelidos/nomes dos ramais nos relatórios. | `src/nome_ramais/` ➔ `/var/www/html/modules/nome_ramais/` |
| **`[11]`** | **Relatório Geral (CDR)** | Instala o relatório de chamadas CDR com player flutuante em HTML5 `<dialog>` nativo na Top Layer, downloads e filtros. | `src/modules/cdrreport/`, `src/modules/asternic_cdr/` |
| **`[12]`** | **Relatório de Filas** | Atualiza os relatórios de atendimento, tempo médio de espera, tempo de atendimento (TMA) e abandono de chamadas. | `src/modules/relatorio_de_filas/`, `src/modules/queues/` |
| **`[13]`** | **Relatórios Extras** | Instala gráficos de volume de tráfego, relatório de chamadas perdidas e distribuição por canal. | `src/modules/graphic_report/`, `summary_by_extension/`, etc. |
| **`[14]`** | **Pesquisa de Satisfação** | Instala a URA de pesquisa (notas de 1 a 5 após a chamada) e o módulo web de auditoria das respostas. | `src/dialplan/pesquisa_satisfacao.conf`, `src/modules/pesquisa/` |
| **`[15]`** | **Call Center** | Atualiza a console do agente, discadores preditivos/progressivos, pausas e campanhas ativas e receptivas. | `src/modules/callcenter_config/`, `src/modules/agent_console/` |
| **`[16]`** | **ChanSpy (Escuta)** | Configura os dialplans de monitoramento em tempo real (escuta secreta, sussurro e intervenção em chamada). | `src/dialplan/chanspy.conf` ➔ `/etc/asterisk/chanspy.conf` |
| **`[17]`** | **Mensagens Texto (PJSIP)** | Habilita o roteamento de mensagens de texto instantâneas (SIP MESSAGE) entre ramais PJSIP e softphones. | `src/dialplan/textmessages.conf` ➔ `/etc/asterisk/textmessages.conf` |
| **`[18]`** | **Servidor LDAP** | Instala o servidor `issabel-ldap` nativo na porta 10389 com credenciais padronizadas (`Prisma@500`) e regras de firewall/iptables. | `src/ldap/issabel-ldap`, `/etc/sysconfig/issabel-ldap` |
| **`[19]`** | **Música de Espera (MOH)** | Instala a coleção de músicas de espera em alta fidelidade e áudios padronizados para o Asterisk. | `src/sounds/moh/` ➔ `/var/lib/asterisk/moh/` |
| **`[20]`** | **Monitor Segurança Telegram** | Configura o bot de monitoramento no Telegram para alertas em tempo real de web shells (Emad), firewall e novos usuários. | `scripts/monitor_prisma.sh` ➔ `/etc/cron.d/monitor_prisma` |
| **`[21]`** | **Ferramentas Diagnóstico** | Instala utilitários essenciais de terminal (`tcpdump`, `sngrep`, `nmtui`) e a API de diagnóstico AMI de ramais. | `src/ramais/` ➔ `/var/www/html/ramais/` |
| **`[22]`** | **Features Asterisk** | Ajusta os timeouts de transferência para 7s / 30s e ativa os BIPs de confirmação (`courtesytone=beep`). | `src/dialplan/features_general_custom.conf` |
| **`[23]`** | **PJSIP User-Agent** | Padroniza o cabeçalho `User-Agent: IPBX PRISMA` no transporte SIP/PJSIP para maior segurança contra scanners. | `/etc/asterisk/pjsip.transports_custom.conf` |
| **`[24]`** | **Auto-Update Semanal** | Instala a rotina automática no cron semanal para manter correções de segurança e relatórios sempre sincronizados. | `scripts/ipbx-autoupdate.sh` ➔ `/etc/cron.weekly/ipbx-autoupdate` |
| **`[25]`** | **Web Developer** | Instala o módulo de desenvolvimento para inspecionar banco de dados, compilar Smarty e testar códigos PHP. | `src/modules/web_developer/` |
| **`[26]`** | **Configurar Domínio e SSL** | Executa o assistente `auto_dominio.sh` para apontar o domínio do cliente, emitir SSL Let's Encrypt e sincronizar WSS. | `scripts/auto_dominio.sh` |
| **`[27]`** | **Limpeza de Logs e Disco** | Executa a limpeza segura de logs truncando arquivos >50MB e liberando espaço sem apagar CDRs ou gravações. | `scripts/limpa_logs.sh` |
| **`[28]`** | **Servidor OpenVPN (EasyVPN)** | Instala o servidor OpenVPN corporativo com Easy-RSA 3.0.8, painel web `ovpn2`, rotas e regras NAT para ramais remotos. | `scripts/ipbx-openvpn.sh`, `scripts/ipbx-openvpn-sync.sh` |
| **`[29]`** | **Rollback (Restauro/Instalar)** | Abre o assistente de restauração por data/hora para reverter qualquer alteração com zero perda de dados. | `rollback.sh` ➔ `/usr/local/bin/ipbx-rollback` |
| **`[30]`** | **Data/Hora e NTP (São Paulo)** | Ajusta timezone oficial `America/Sao_Paulo` (Brasília) no Linux, PHP e Asterisk, sincronizando com servidores NTP. | `scripts/ipbx-timezone.sh` |
| **`[A]`** | **INSTALAR TUDO** | Executa a suíte completa de atualização com criação prévia de snapshot, igual ao `install.sh`. | Aplica todas as opções [1] a [30] em lote |
| **`[0]`** | **Sair** | Encerra o menu interativo com segurança. | N/A |

---

## 📇 Servidor LDAP de Ramais (Opção [18]) & Provisionamento Grandstream GDMS

O servidor LDAP nativo (`issabel-ldap`) roda como serviço em segundo plano na porta **`10389`** (TCP), permitindo que aparelhos IP consultem ramais e a agenda corporativa em tempo real.

### 🔑 Credenciais Padronizadas:
- **Endereço do Servidor:** Domínio FQDN ou IP do PABX do cliente (ex: `cliente.ipbxprisma.cloud`)
- **Porta:** `10389`
- **Base DN:** `dc=pbx,dc=com`
- **Usuário Administrador (Bind DN):** `cn=admin,dc=pbx,dc=com`
- **Senha Padrão:** `Prisma@500` (configurada em `/etc/sysconfig/issabel-ldap`)

### 📋 Template XML Pronto para o GDMS da Grandstream:
Para provisionar centenas de aparelhos via nuvem no GDMS com 1 clique:

```xml
<?xml version="1.0" encoding="utf-8"?>
<gs_provision>
    <config version="2">
        <item name="ldap">
            <!-- Endereço do Servidor do Cliente -->
            <part name="server">cliente.ipbxprisma.cloud</part>
            <part name="port">10389</part>
            <part name="base">dc=pbx,dc=com</part>
            <part name="protocol">LDAP</part>
            <part name="version">3</part>
            <part name="username">cn=admin,dc=pbx,dc=com</part>
            <part name="password">Prisma@500</part>
            <part name="ldapDisplayName">%cn</part>
            <part name="ldapNumberFilter">(homePhone=%)</part>
            <part name="ldapNumberAttributes">homePhone</part>
            <part name="ldapNameFilter">(|(cn=%)(displayName=%))</part>
            <part name="ldapNameAttributes">cn displayName</part>
            <part name="ldapMailFilter"></part>
            <part name="ldapMailAttributes"></part>
            <part name="ldapPositionFilter"></part>
            <part name="ldapPositionAttributes"></part>
            <part name="ldapDepartmentFilter"></part>
            <part name="ldapDepartmentAttributes"></part>
        </item>
    </config>
</gs_provision>
```

### 🧪 Teste Rápido no Terminal do PBX:
```bash
ldapsearch -x -H ldap://127.0.0.1:10389 \
  -D "cn=admin,dc=pbx,dc=com" \
  -w "Prisma@500" \
  -b "dc=pbx,dc=com" "(homePhone=*)"
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

11. 🛡️ **Servidor OpenVPN (EasyVPN):** Túnel criptografado com Easy-RSA 3.0.8 corrigido, IP forwarding no kernel e regras de NAT para ramais remotos e telefones IP físicos sem falha de áudio.
12. 📇 **Servidor LDAP Integrado (Porta 10389):** Serviço nativo `issabel-ldap` com sincronização automática da agenda corporativa para telefones Grandstream, Fanvil e Yealink.

---

## 🛠️ Scripts Utilitários e Comandos Rápidos

O repositório disponibiliza diversos scripts independentes na pasta [`scripts/`](./scripts/), que podem ser executados diretamente ou via seus comandos instalados no sistema:

| Comando / Script | O que faz | Como executar avulso |
| :--- | :--- | :--- |
| **`ipbx-update`**<br>([`ipbx-menu.sh`](./ipbx-menu.sh)) | **Menu Interativo Modular:** Permite atualizar módulo por módulo com segurança e snapshots automáticos por data. | `ipbx-update`<br>*ou* `curl -sSL .../ipbx-menu.sh \| bash` |
| **`ipbx-rollback`**<br>([`rollback.sh`](./rollback.sh)) | **Rollback por Data/Hora:** Restaura o PBX para o estado anterior exato de qualquer data/hora gravada. | `ipbx-rollback`<br>*ou* `ipbx-rollback --latest` |
| **`ipbx-openvpn.sh`**<br>([`scripts/ipbx-openvpn.sh`](./scripts/ipbx-openvpn.sh)) | **Servidor OpenVPN (EasyVPN):** Instala OpenVPN, Easy-RSA 3.0.8, interface web `ovpn2`, regras NAT e iptables. | `bash scripts/ipbx-openvpn.sh` |
| **`ipbx-ssl`**<br>([`scripts/auto_dominio.sh`](./scripts/auto_dominio.sh)) | **Domínio e SSL Let's Encrypt:** Cria VirtualHost no Apache, emite certificado SSL gratuito e integra chaves com o Webphone WebRTC. | `bash scripts/auto_dominio.sh <dominio> <email>` |
| **`ipbx-limpalogs`**<br>([`scripts/limpa_logs.sh`](./scripts/limpa_logs.sh)) | **Limpeza Segura de Logs e Disco:** Trunca logs >50MB, apaga logs antigos rotacionados e limpa cache Smarty. **Nunca apaga CDRs nem gravações.** | `bash scripts/limpa_logs.sh` |
| **`monitor_prisma.sh`**<br>([`scripts/monitor_prisma.sh`](./scripts/monitor_prisma.sh)) | **Monitor de Segurança Telegram:** Detecta web shells/Emad em `/var/www/html/`, monitora Firewall (iptables/firewalld) e alerta novos usuários Web criados. | `bash scripts/monitor_prisma.sh` |
| **`ipbx-autoupdate`**<br>([`scripts/ipbx-autoupdate.sh`](./scripts/ipbx-autoupdate.sh)) | **Atualização Automática Semanal:** Executado via cron semanal para sincronizar atualizações e gerar logs locais. | `bash scripts/ipbx-autoupdate.sh` |
| **`motd.sh`**<br>([`scripts/motd.sh`](./scripts/motd.sh)) | **Banner do Terminal SSH:** Tela personalizada de boas-vindas ao logar como root no terminal. | `bash scripts/motd.sh` |

---

## 🔍 Guia de Uso e Documentação por Módulo

Para instruções detalhadas de configuração de cada recurso, acesse os manuais na pasta [`docs/`](./docs/):

- 🗺️ [Mapeamento Visual de Arquitetura (Archify HTML & mindwalk 3D)](./docs/architecture/README.md)
- 🛡️ [Relatório de Auditoria de Vulnerabilidades Google Mantis](./docs/security/MANTIS_SECURITY_AUDIT.md)
- 🛡️ [Servidor OpenVPN (EasyVPN) - Configuração e Softphones/Telefones IP](./docs/openvpn_server.md)
- 📇 [Servidor LDAP Integrado (issabel-ldap na porta 10389)](./docs/ldap_server.md)
- ☎️ [Provisionamento LDAP Grandstream (GRP2601/Série GRP) com XML](./docs/ldap_grandstream.md)
- ☎️ [Provisionamento LDAP Fanvil (Série X/V)](./docs/ldap_fanvil.md)
- 🔒 [Configuração de Domínio e SSL Let's Encrypt (`auto_dominio.sh`)](./docs/auto_dominio_ssl.md)
- 🧹 [Limpeza de Logs e Otimização de Disco (`limpa_logs.sh`)](./docs/limpa_logs.md)
- 🚨 [Monitor de Segurança & Alertas Telegram (`monitor_prisma.sh`)](./docs/monitor_seguranca_telegram.md)
- 🕵️ [Detecção e Remoção do Invasor Emad (`invador_emad.md`)](./docs/invador_emad.md)
- 🔄 [Atualização Automática Semanal (Auto Update & Logs)](./docs/autoupdate.md)
- 📊 [Asternic CDR - Relatórios de Chamadas](./docs/asternic_cdr.md)
- 🎛️ [Painel IPbx - Monitoramento Visual de Ramais](./docs/painel_ipbx.md)
- 📝 [Pesquisa de Satisfação WEB & URA](./docs/pesquisa_satisfacao_web.md)
- 📞 [Instalação TFTP](./docs/tftp_install.md)
- 🔗 [Alteração de Links no Menu do Issabel](./docs/alterar_link.md)
- 🔄 [Rollback Versionado por Data/Hora](#-rollback-versionado-restaurar-por-data)

---

## 🛠️ Suporte & Desenvolvimento

- **Autor:** Leandro Saltori - Prisma Telecom
- **Repositório:** [LeandroSaltori/ipbx-issabel6.0](https://github.com/LeandroSaltori/ipbx-issabel6.0)