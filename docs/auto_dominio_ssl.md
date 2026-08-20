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

## 🚪 2. Portas Obrigatórias de Rede e Firewall

Tanto na VPS quanto no Proxmox / Roteador do cliente, as seguintes portas precisam estar **liberadas e redirecionadas (NAT/Port Forward)** para a VM do Issabel:

| Porta | Protocolo | Para que serve | Obrigatória para SSL? |
| :--- | :--- | :--- | :---: |
| **80** | TCP | Validação e emissão do certificado Let's Encrypt + Redirecionamento HTTP ➡️ HTTPS | **SIM (Crítica)** |
| **443** | TCP | Acesso seguro à interface WEB do Issabel com cadeado verde | **SIM** |
| **8089** | TCP / WSS | Conexão WebRTC segura para o **Webphone** funcionar sem travar microfone | Recomendada |
| **5060** | UDP / TCP | Sinalização SIP de Ramais e Troncos | PBX |
| **10000 a 20000** | UDP | Áudio das ligações (RTP / Voz bidirecional) | PBX |

> **⚠️ Atenção Proxmox:** Se a VM estiver atrás de um roteador/firewall (MikroTik, pfSense, modem da operadora), crie as regras de **NAT / Port Forward** direcionando as portas **80 e 443** externas para o IP local da VM (ex: `192.168.1.200`).

---

## 📋 3. Passo a Passo do Apontamento de DNS no Hostinger

Toda a gestão é feita **100% dentro do painel da Hostinger onde você já tem o domínio `ipbxprisma.cloud`**, sem precisar de ferramentas externas:

### A) Para VPS na Nuvem ou Proxmox com IP Fixo:

1. Acesse o painel da **Hostinger** ➡️ **Domínios** ➡️ Selecione `ipbxprisma.cloud` ➡️ **DNS / Nameservers**
2. Em **"Gerenciar registros DNS"**, adicione:
   - **Tipo:** `A`
   - **Nome:** `nome_do_cliente` (ex: `jaguimar`, `fullcont`, `mineirao`)
   - **Valor:** `IP_Publico_da_VPS_ou_do_Cliente` (ex: `187.127.56.37`)
   - **TTL:** `14400`
3. Clique em **"Adicionar registro"**

---

### B) Para Proxmox com IP Dinâmico (Substituindo a Winco 100% no Hostinger):

Se o cliente no Proxmox não tiver IP fixo e usar um DDNS gratuito de roteador (ex: MikroTik Cloud `xxxxxx.sn.mynetname.net` ou similar):

1. No painel da **Hostinger**, crie um registro do tipo **`CNAME`**:
   - **Tipo:** `CNAME`
   - **Nome:** `nome_do_cliente` (ex: `cliente01`)
   - **Valor:** `xxxxxx.sn.mynetname.net` *(o endereço dinâmico do roteador do cliente)*
   - **TTL:** `14400`
2. Clique em **"Adicionar registro"**
3. **Resultado:** Sempre que a internet do cliente mudar de IP, a Hostinger responde automaticamente com o novo IP!
4. Na VM do Issabel, basta rodar o instalador SSL normalmente:
   ```bash
   curl -sSL https://raw.githubusercontent.com/LeandroSaltori/ipbx-issabel6.0/main/scripts/auto_dominio.sh | bash -s cliente01.ipbxprisma.cloud leandro@prismatelecom.com
   ```

---

## 🔍 4. Como Testar se o DNS já Propagou

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
