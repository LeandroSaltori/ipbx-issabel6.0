# 🔒 Configuração de Domínio e SSL Let's Encrypt (`auto_dominio.sh`)

Este script automatiza a configuração de um **domínio próprio com certificado SSL gratuito (Let's Encrypt)** no Issabel PBX.

---

## 🎯 O que o script faz:

1. **Instalação do Certbot:** Instala e atualiza o Certbot e o plugin Apache no CentOS 7, Rocky Linux 8 e 9.
2. **Criação do VirtualHost:** Gera a configuração HTTP na porta 80 em `/etc/httpd/conf.d/<DOMINIO>.conf`.
3. **Validação do Apache:** Testa a sintaxe com `apachectl configtest` antes de aplicar.
4. **Emissão do Certificado:** Solicita o certificado oficial HTTPS gratuito junto ao Let's Encrypt.
5. **Integração com Asterisk WebRTC (WSS):** Copia as chaves criptográficas (`privkey.pem`, `fullchain.pem` e `webrtc.pem`) para `/etc/asterisk/keys/` para permitir que o **Webphone** funcione sem erros de segurança de microfone/áudio.
6. **Renovação Automática:** Cria agendamento no crontab (`certbot renew`) com recarga automática de serviços.

---

## 🚀 Como Executar:

### Modo 1: Via Menu Interativo
```bash
ipbx-update
# Escolha a opção [26] Configurar Domínio e SSL
```

### Modo 2: Comando Direto no Terminal
```bash
bash /root/ipbx-issabel6.0/scripts/auto_dominio.sh
```

### Modo 3: Passando Parâmetros Diretos
```bash
bash /root/ipbx-issabel6.0/scripts/auto_dominio.sh pbx.minhaempresa.com.br financeiro@minhaempresa.com.br
```

---

## 📋 Pré-requisitos:
- O domínio (ex: `pbx.minhaempresa.com.br`) deve estar com o **DNS tipo A** apontando para o IP público da VPS.
- As portas **80 (HTTP)** e **443 (HTTPS)** devem estar liberadas no Firewall/Provedor.
