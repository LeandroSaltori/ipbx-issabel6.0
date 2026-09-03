# 🛠️ Scripts de Automação do IPBX Prisma Telecom (`scripts/`)

Esta pasta contém os scripts utilitários, rotinas de automação, ferramentas de segurança e serviços auxiliares do sistema **IPBX Issabel PBX**.

A maioria destes scripts pode ser executada de forma independente ou através das opções do menu interativo **`ipbx-update`** ([`ipbx-menu.sh`](../ipbx-menu.sh)).

---

## 📋 Mapeamento de Scripts x Opções do Menu

| Script | Opção no Menu (`ipbx-update`) | Descrição e Finalidade |
| :--- | :---: | :--- |
| **`motd.sh`** | **[1]** | Banner personalizado de boas-vindas do terminal SSH (MOTD) exibindo status, logo e links de suporte. |
| **`update-addressbook`** | **[6]** | Sincronizador de contatos da agenda SQLite (`address_book.db`) e geração de listas de discagem rápida. |
| **`monitor_prisma.sh`** | **[20]** | Monitor de integridade e segurança com notificações via Telegram (detecta web shells Emad, monitora firewall e novos usuários web). |
| **`ipbx-autoupdate.sh`** | **[24]** | Rotina de atualização semanal automática do PBX instalada no cron (`/etc/cron.weekly/ipbx-autoupdate`). |
| **`auto_dominio.sh`** | **[26]** | Assistente para configuração de domínio FQDN, emissão de certificado SSL Let's Encrypt e sincronização de chaves WSS WebRTC. |
| **`limpa_logs.sh`** | **[27]** | Limpeza segura de disco e otimização de logs (trunca logs >50MB, limpa cache Smarty e preserva histórico CDR/gravações). |
| **`ipbx-openvpn.sh`** | **[28]** | Instalador e configurador completo do servidor OpenVPN (EasyVPN) com Easy-RSA 3.0.8, interface web `ovpn2`, rotas e regras NAT. |
| **`ipbx-openvpn-sync.sh`** | **[28]** | Sincronizador de certificados e usuários OpenVPN para manter clientes e rotas íntegras. |
| **`ipbx-timezone.sh`** | **[30]** | Ajuste automático de data, hora, NTP e fuso horário oficial de Brasília/São Paulo (`America/Sao_Paulo`) no Linux, PHP e Asterisk. |
| **`ipbx-logrotate.sh`** | *Auxiliar* | Regras customizadas de rotação diária de logs do Apache, Asterisk e serviços do Issabel. |
| **`ipbx-security-hardening.sh`**| *Auxiliar* | Script mestre de imunização e bloqueio de vulnerabilidades conhecidas em servidores Issabel em produção. |

---

## 🚀 Como Executar os Scripts Individualmente

Todos os scripts possuem permissão de execução (`chmod +x`) e podem ser chamados diretamente como `root`:

### 1. Banner SSH MOTD:
```bash
bash scripts/motd.sh
```

### 2. Configurar Domínio e SSL Let's Encrypt:
```bash
bash scripts/auto_dominio.sh <seu-dominio.com.br> <seu-email@dominio.com.br>
```

### 3. Limpeza Segura de Logs e Liberação de Espaço:
```bash
bash scripts/limpa_logs.sh
```

### 4. Monitor de Segurança com Alertas no Telegram:
```bash
bash scripts/monitor_prisma.sh
```

### 5. Configurar Servidor OpenVPN (EasyVPN):
```bash
bash scripts/ipbx-openvpn.sh
```

### 6. Acertar Fuso Horário e NTP Brasil (São Paulo):
```bash
bash scripts/ipbx-timezone.sh
```

---

## 🔒 Princípio de Segurança Operacional

- **Preservação de Dados:** Nenhum script desta pasta apaga histórico de chamadas (CDR), bancos de dados de clientes ou gravações de áudio.
- **Não-interrupção de Produção:** Os scripts aplicam recargas suaves (`reload`) sem reiniciar o serviço do Asterisk desnecessariamente.
