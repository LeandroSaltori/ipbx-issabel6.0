# 🔒 Manual Completo: Configuração de Domínio e Certificado SSL Let's Encrypt

Este manual descreve o passo a passo completo para configurar um **domínio próprio com SSL gratuito (Let's Encrypt)** em qualquer VPS Issabel PBX, garantindo o **cadeado verde** no navegador e o funcionamento perfeito do **Webphone WebRTC (WSS)** sem bloqueios de microfone ou erros de segurança.

---

## ⚡ 1. Instalador Direto em 1 Comando (Independente)

Para executar apenas a configuração de domínio e SSL em qualquer VPS (sem precisar rodar o instalador geral):

### Modo Interativo (Pergunta na tela):
```bash
curl -sSL https://raw.githubusercontent.com/LeandroSaltori/ipbx-issabel6.0/main/scripts/auto_dominio.sh | bash
```

### Modo Direto (Passando parâmetros):
```bash
curl -sSL https://raw.githubusercontent.com/LeandroSaltori/ipbx-issabel6.0/main/scripts/auto_dominio.sh | bash -s <subdominio.dominio.com> <email@empresa.com>
```

*Exemplo real:*
```bash
curl -sSL https://raw.githubusercontent.com/LeandroSaltori/ipbx-issabel6.0/main/scripts/auto_dominio.sh | bash -s jaguimar.ipbxprisma.cloud leandro@prismatelecom.com
```

> **Dentro do Menu Modular:** Também disponível a qualquer momento digitando `ipbx-update` ➡️ Opção `[26] Configurar Domínio e SSL`.

---

## 📋 2. Passo a Passo do Apontamento de DNS

Antes de rodar o instalador na VPS, é **obrigatório** criar a entrada DNS no painel onde o domínio está registrado (Hostinger, Cloudflare, Registro.br, GoDaddy, etc.).

### Tabela de Configuração DNS:

| Campo | O que preencher | Exemplo |
| :--- | :--- | :--- |
| **Tipo** | Selecione sempre **`A`** | `A` |
| **Nome (Host / Subdomínio)** | O prefixo exclusivo do cliente | `jaguimar` ou `mineirao` |
| **Valor (Aponta para / IP)** | O endereço IPv4 fixo da VPS do cliente | `187.127.56.37` |
| **TTL** | Padrão | `14400` (ou `3600` / `Automático`) |

---

### 🖥️ Exemplo Prático no Hostinger:

1. Acesse o painel da **Hostinger** ➡️ **Domínios** ➡️ Selecione `ipbxprisma.cloud`
2. No menu lateral esquerdo, clique em **DNS / Nameservers**
3. Role até a seção **"Gerenciar registros DNS"**
4. Preencha os campos:
   - **Tipo:** `A`
   - **Nome:** `jaguimar` *(o Hostinger completa automaticamente para `jaguimar.ipbxprisma.cloud`)*
   - **Valor:** `187.127.56.37` *(coloque o IP da VPS)*
   - **TTL:** `14400`
5. Clique no botão roxo **"Adicionar registro"**

---

## 🔍 3. Como Testar se o DNS já Propagou

Antes de rodar o comando SSL, você pode verificar no seu computador ou no terminal da VPS se o nome já responde para o IP correto:

```bash
ping jaguimar.ipbxprisma.cloud
```
*Se a resposta mostrar o IP da VPS (ex: `187.127.56.37`), a propagação está pronta!*

---

## 🛠️ 4. O que o Script Executa Automaticamente:

Quando você roda o `auto_dominio.sh`, ele realiza 6 operações técnicas por baixo dos panos:

1. **Instalação do Certbot:** Instala pacotes oficiais no CentOS 7, Rocky Linux 8 e 9 (`epel-release`, `certbot`, `python3-certbot-apache`).
2. **Criação do VirtualHost HTTP:** Gera o arquivo `/etc/httpd/conf.d/<DOMINIO>.conf` na porta 80.
3. **Validação do Apache:** Testa a sintaxe com `apachectl configtest` para garantir que o Apache não quebre.
4. **Emissão do Certificado HTTPS:** Executa o Certbot contra os servidores do Let's Encrypt e gera os certificados em `/etc/letsencrypt/live/<DOMINIO>/`.
5. **Integração com o Asterisk WebRTC (WSS):**
   - Cria a pasta `/etc/asterisk/keys/`
   - Copia a chave privada para `webrtc.key`
   - Copia a cadeia completa para `webrtc.crt`
   - Cria o arquivo unificado `webrtc.pem`
   - Aplica permissões restritas `600` e proprietário `asterisk:asterisk`
   - Recarrega o servidor HTTP do Asterisk (`asterisk -rx "http reload"`)
6. **Agendamento de Renovação Automática:**
   - Cria uma entrada no crontab do root para rodar todo dia às 03:00 da manhã.
   - Sempre que o certificado for renovado, ele atualiza as chaves do WebRTC e recarrega o Apache automaticamente.

---

## 🌐 5. Testando o Acesso após a Instalação

Abra o navegador e acesse com **HTTPS**:
👉 **`https://jaguimar.ipbxprisma.cloud`**

### O que você deve observar:
- ✅ **Cadeado verde/fechado** ao lado da URL (Conexão 100% segura).
- ✅ **Webphone WebRTC:** Ao abrir o softphone web, o navegador solicita permissão de microfone sem emitir erros de segurança WSS.
- ✅ **Sem telas vermelhas** de aviso de certificado autoassinado.

---

## 🔄 6. Teste de Renovação Manual

Os certificados Let's Encrypt são válidos por **90 dias** e se renovam automaticamente a cada 60 dias. Para simular a renovação a qualquer momento:

```bash
certbot renew --dry-run
```

---

## 🚨 7. Resolução de Problemas (Troubleshooting)

### Erro 1: *"Ocorreu um erro ao emitir o certificado"*
- **Causa:** O DNS ainda não propagou ou o IP informado no Hostinger está diferente do IP da VPS.
- **Solução:** Verifique com `ping <dominio>` se o IP retornado é o da máquina.

### Erro 2: *"Could not bind to port 80 / Connection refused"*
- **Causa:** Porta 80 ou 443 bloqueada no Firewall da VPS ou no painel da nuvem (Oracle, AWS, etc.).
- **Solução:** Libere as portas no firewall:
  ```bash
  iptables -I INPUT -p tcp --dport 80 -j ACCEPT
  iptables -I INPUT -p tcp --dport 443 -j ACCEPT
  service iptables save 2>/dev/null || true
  ```
