# Servidor OpenVPN (EasyVPN) - Issabel PBX 🛡️

Este documento descreve a arquitetura, instalação, resolução de problemas e configuração do **Servidor OpenVPN no Issabel PBX (CentOS 7 / Rocky Linux 8)**.

---

## ⚙️ Visão Geral e Arquitetura

O OpenVPN no Issabel permite interligar filiais, usuários remotos (softphones no Windows/Mac/Linux/Android/iOS) e **telefones IP físicos** (Yealink, Fanvil, Grandstream, Intelbras) diretamente à rede interna do PBX com túnel criptografado.

- **Porta padrão:** `1194/udp`
- **Sub-rede da VPN:** `10.8.0.0/24` (o servidor assume o IP `10.8.0.1`)
- **Protocolo de Criptografia:** `AES-256-GCM` / `AES-256-CBC` com SHA256 e autenticação TLS.
- **Roteamento de Áudio (Sem One-Way Audio):** O instalador habilita automaticamente `net.ipv4.ip_forward = 1` e regras de Masquerade (NAT) para garantir que a voz trafegue nos dois sentidos sem necessidade de abrir portas SIP/RTP na internet pública.

---

## 🚀 Instalação e Automação

A instalação pode ser feita pelo instalador geral ou pelo menu modular:

```bash
# Pelo Menu Modular:
ipbx-update  # Escolha a opção [28] Servidor OpenVPN (EasyVPN)
```

Ou diretamente pelo script:
```bash
bash scripts/ipbx-openvpn.sh
```

---

## 🔧 Correção do Bug "Create CA" no Issabel 5 (Rocky Linux 8)

### O Problema:
No Issabel 5, ao acessar a interface web (**Sistema -> Segurança -> OpenVPN**) e clicar em **Create CA**, a tela ficava travada em loop infinito devido à ausência do pacote **Easy-RSA 3.0.8** no caminho fixo esperado pelo módulo web.

### A Solução Aplicada:
Nosso script provisiona automaticamente os binários e scripts do **Easy-RSA 3.0.8** em:
- `/usr/share/easy-rsa/3.0.8/`
- `/usr/share/easy-rsa/3/`

Com permissões de execução corretas (`chmod +x`), permitindo que o botão **Create CA** gere os certificados do servidor em poucos segundos.

---

## 📱 Passo a Passo de Configuração no Painel Web

1. **Acesse o Issabel:**
   - Navegue até: **Sistema (System)** -> **Segurança (Security)** -> **OpenVPN (EasyVPN)**.
2. **Criar a Autoridade Certificadora (CA):**
   - Clique em **Create CA** (Criar CA) e aguarde a mensagem de sucesso.
3. **Iniciar o Servidor OpenVPN:**
   - Na aba de configurações do servidor, confirme a porta `1194`, protocolo `UDP` e sub-rede `10.8.0.0`.
   - Clique em **Save / Start Server**.
4. **Criar Clientes (Usuários / Ramais):**
   - Acesse a aba **Clients** -> **Add Client**.
   - Digite o nome do cliente (ex: `ramal101`, `home_office_joao`).
   - Clique em **Save**.
5. **Baixar o Arquivo de Conexão:**
   - Na lista de clientes, clique no botão de **Download** para baixar o arquivo `.ovpn` pronto para uso.

---

## 💻 Como Conectar os Clientes

### 1. Windows / macOS / Linux (OpenVPN Connect)
1. Instale o aplicativo oficial [OpenVPN Connect](https://openvpn.net/client/).
2. Abra o aplicativo e clique em **Upload File**.
3. Selecione o arquivo `.ovpn` baixado do Issabel e clique em **Connect**.

### 2. Android / iOS (Smartphones)
1. Baixe o app **OpenVPN Connect** na Google Play Store ou Apple App Store.
2. Envie o arquivo `.ovpn` para o celular (WhatsApp, E-mail ou Drive) e abra com o OpenVPN Connect.
3. No seu softphone (ex: Grandstream Wave, Zoiper, MicroSIP), registre o ramal apontando para o IP da VPN: `10.8.0.1`.

### 3. Telefones IP Yealink / Fanvil / Grandstream
1. Gere o pacote de certificados para o aparelho (`vpn.tar` contendo `vpn.cnf` / `client.ovpn`, `ca.crt`, `client.crt` e `client.key`).
2. Acesse a interface web do telefone IP:
   - **Yealink:** *Network* -> *Advanced* -> *VPN* -> Ativar e fazer Upload do arquivo `vpn.tar`.
   - **Fanvil:** *Network* -> *VPN* -> Selecionar modo *OpenVPN* e carregar o arquivo `.ovpn`.
   - **Grandstream:** *Network Settings* -> *OpenVPN Settings* -> Ativar e carregar certificados.
3. Configure a conta SIP do telefone apontando o SIP Server para `10.8.0.1`.

---

## 🛠️ Comandos Úteis do Sistema (Troubleshooting)

```bash
# Verificar status do serviço OpenVPN
systemctl status openvpn-server@server.service  # (Issabel 5)
systemctl status openvpn@server.service         # (Issabel 4)

# Reiniciar serviço OpenVPN
systemctl restart openvpn-server@server.service

# Ver logs de conexão em tempo real
journalctl -u openvpn-server@server.service -f

# Ver clientes conectados no momento
cat /etc/openvpn/openvpn-status.log 2>/dev/null || cat /var/log/openvpn/openvpn-status.log 2>/dev/null
```
