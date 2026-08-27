# Servidor OpenVPN (EasyVPN) - Issabel PBX 🛡️

Este documento descreve a arquitetura, instalação, resolução de bugs e o **guia passo a passo completo** para configuração e uso do **Servidor OpenVPN no Issabel PBX (CentOS 7 / Rocky Linux 8)**.

---

## ⚙️ Visão Geral e Arquitetura

O OpenVPN no Issabel permite interligar filiais, usuários remotos (softphones no Windows/Mac/Linux/Android/iOS) e **telefones IP físicos** (Grandstream, Fanvil, Yealink, Intelbras) diretamente à rede interna do PBX com túnel criptografado seguro.

- **Porta padrão:** `1194/udp`
- **Sub-rede da VPN:** `10.8.0.0/24` (o servidor PBX assume o IP `10.8.0.1`)
- **Protocolo de Criptografia:** `AES-256-GCM` / `AES-256-CBC` com SHA256 e autenticação TLS.
- **Roteamento de Áudio Sem Falhas (No One-Way Audio):** O instalador habilita automaticamente `net.ipv4.ip_forward = 1` no kernel e regras de Masquerade (NAT) para garantir que o áudio RTP trafegue nos dois sentidos sem abrir portas SIP/RTP na internet pública.

---

## 🚀 Instalação e Automação

A instalação pode ser feita pelo instalador geral ou pelo menu modular:

```bash
# Pelo Menu Modular:
ipbx-update  # Escolha a opção [28] Servidor OpenVPN (EasyVPN)
```

Ou diretamente pelo script:
```bash
curl -sSL https://raw.githubusercontent.com/LeandroSaltori/ipbx-issabel6.0/main/scripts/ipbx-openvpn.sh | bash
```

---

## 🔧 Correção do Bug "Create CA" no Issabel 5 (Rocky Linux 8)

### O Problema:
No Issabel 5, ao acessar a interface web (**Sistema -> Segurança -> OpenVPN**) e clicar em **Create CA**, a tela ficava travada em loop infinito devido à ausência dos binários do **Easy-RSA 3.0.8** no caminho fixo esperado pelo módulo web.

### A Solução Aplicada:
Nosso script provisiona automaticamente os binários e scripts do **Easy-RSA 3.0.8** em:
- `/usr/share/easy-rsa/3.0.8/`
- `/usr/share/easy-rsa/3/`

Com permissões de execução corretas (`chmod +x`), permitindo que a geração de certificados e da CA ocorra em poucos segundos sem travamentos.

---

## 📋 Guia Passo a Passo no Painel Web do Issabel

Acesse o painel do Issabel em: **Sistema** ➔ **Segurança** ➔ **OpenVPN**.

Siga a sequência do assistente na tela:

### 1️⃣ Passo 1: Create Vars File (Arquivo de Variáveis)
- Clique na aba **1. Create Vars File**.
- Preencha as informações da sua organização:
  - **Country (País):** `BR`
  - **Province / State (Estado):** `SP` (ou seu estado)
  - **City (Cidade):** Sua cidade
  - **Organization (Empresa):** Nome da sua empresa (ex: `Prisma Telecom`)
  - **Email:** Seu e-mail de suporte
  - **Expire Days (Validade):** `3650` (10 anos)
- Clique no botão **Save Vars File**.

### 2️⃣ Passo 2: Create CA (Autoridade Certificadora)
- Clique na aba **2. Create CA**.
- Digite uma senha para a CA (ou confirme a senha padrão sugerida).
- Clique no botão **Create CA**.
- *Aguarde alguns segundos até a mensagem verde de sucesso aparecer na tela.*

### 3️⃣ Passo 3: Create Server Certificate (Certificado do Servidor)
- Clique na aba **3. Create Server Certificate**.
- Digite o nome do certificado do servidor (padrão: `server`).
- Clique em **Create Certificate**.

### 4️⃣ Passo 4: Create DH Parameters (Diffie-Hellman)
- Clique na aba **4. Create DH**.
- Escolha o tamanho da chave: `2048` bits (Recomendado).
- Clique em **Create DH**.
- *Esse processo gera as chaves de troca criptográfica.*

### 5️⃣ Passo 5: Server Settings (Configurações do Servidor)
- Clique na aba **5. Server Settings**.
- Configure os parâmetros:
  - **Server IP / Public Hostname:** IP Público ou Domínio DDNS do seu PBX (ex: `meupbx.ipbxprisma.cloud`).
  - **Port (Porta):** `1194`
  - **Protocol (Protocolo):** `UDP`
  - **Subnet (Rede Virtual):** `10.8.0.0` / `255.255.255.0`
  - **Redirect Gateway:** *Deixe desmarcado* se desejar apenas tráfego do PBX pela VPN (Split-Tunneling), ou *marcado* se desejar que toda a internet do cliente passe pelo PBX.
- Clique em **Save & Start Server**.
- O status do serviço mudará para **Running (Em execução)**!

### 6️⃣ Passo 6: Add Clients & Download .ovpn (Criar Ramais e Dispositivos)
- Clique na aba **Clients** ➔ **Add Client**.
- Digite o identificador do cliente:
  - Exemplo para softphone: `ramal101` ou `notebook_suporte`
  - Exemplo para telefone físico: `telefone_diretoria` ou `fanvil_loja1`
- Clique em **Create Client**.
- Na lista de clientes gerados, clique no ícone de **Download** para baixar o arquivo `.ovpn` pronto e auto-contido.

---

## 📱 Como Conectar os Dispositivos

### 1. Windows, macOS e Linux (Softphones e Computadores)
1. Baixe e instale o [OpenVPN Connect Oficial](https://openvpn.net/client-connect-vpn-for-windows/).
2. Abra o aplicativo, clique em **File** e arraste o arquivo `.ovpn` baixado do Issabel.
3. Clique em **Connect**.
4. No seu Softphone (MicroSIP, Zoiper, Linphone, etc.):
   - **SIP Server / Domain:** `10.8.0.1`
   - **User / Login:** Número do Ramal (ex: `101`)
   - **Secret / Password:** Senha do Ramal

---

### 2. Celulares Android e iOS (Smartphones)
1. Instale o app **OpenVPN Connect** na Google Play Store ou App Store.
2. Importe o arquivo `.ovpn` para o aplicativo e conecte.
3. No app de softphone (Grandstream Wave, Zoiper, etc.), registre o ramal apontando para `10.8.0.1`.

---

### 3. Telefones IP Grandstream (Série GRP, GXP)
1. Na interface web do Grandstream: **Network Settings** ➔ **OpenVPN Settings**.
2. **Enable OpenVPN:** `Yes`
3. Faça o upload dos certificados contidos no pacote `.ovpn` (ou importe o arquivo de configuração).
4. Em **Account 1** ➔ **General Settings**:
   - **SIP Server:** `10.8.0.1`
   - **SIP User ID / Authenticate ID:** Ramal
   - **Authenticate Password:** Senha do Ramal

---

### 4. Telefones IP Fanvil (Série X, V)
1. Na interface web do Fanvil: **Network** ➔ **VPN**.
2. **Enable VPN:** `Enabled`
3. **VPN Type:** `OpenVPN`
4. Carregue o arquivo de configuração `.ovpn` e clique em **Apply**.
5. No menu **Line** ➔ **SIP**, aponte o **Server Address** para `10.8.0.1`.

---

### 5. Telefones IP Yealink (Série T3, T4, T5)
1. Para o Yealink, compacte os arquivos em um arquivo `vpn.tar` contendo:
   - `vpn.cnf` (renomeado a partir do seu `.ovpn`)
   - `ca.crt`
   - `client.crt`
   - `client.key`
2. Na interface web do Yealink: **Network** ➔ **Advanced** ➔ **VPN**.
3. **VPN Active:** `Enabled`
4. Em **Upload VPN Config**, selecione o arquivo `vpn.tar` e clique em **Upload**.
5. Em **Account** ➔ **Register**, aponte o **SIP Server 1** para `10.8.0.1`.

---

## 🛠️ Diagnóstico e Comandos Úteis no Terminal

```bash
# Verificar status do serviço OpenVPN no Linux
systemctl status openvpn-server@server.service

# Reiniciar serviço OpenVPN
systemctl restart openvpn-server@server.service

# Ver logs de conexão em tempo real (veja clientes conectando)
journalctl -u openvpn-server@server.service -f

# Ver clientes conectados e seus IPs virtuais atribuídos
cat /var/log/openvpn/openvpn-status.log 2>/dev/null || cat /etc/openvpn/openvpn-status.log 2>/dev/null
```
