# 🚨 Monitor de Segurança & Alertas Telegram (`monitor_prisma.sh`)

Este script realiza o monitoramento contínuo de segurança do servidor Issabel PBX e envia **notificações instantâneas via Telegram** em caso de anomalias ou ameaças.

---

## 🎯 O que o monitor verifica:

1. **🕵️ Detecção de Invasão e Web Shells:**
   - Varre a pasta `/var/www/html/` em busca de assinaturas conhecidas de invasores e backdoors (como `Emad__Was__Here`, `c99shell`, `r57shell`, injeções PHP `eval(base64_decode`, etc.).
   - Dispara alerta imediato no grupo do Telegram com o caminho exato do arquivo infectado.

2. **🛡️ Monitor de Firewall (Status e Regras):**
   - Verifica se o serviço do Firewall (`iptables` ou `firewalld`) está ativo.
   - Caso o Firewall caia ou a quantidade de regras esteja anormalmente baixa, dispara alerta e **tenta reativar o Firewall automaticamente**.

3. **👤 Monitor de Novos Usuários Web (SQLite):**
   - Monitora a tabela `acl_user` em `/var/www/db/acl.db`.
   - Se um novo usuário com acesso à interface WEB for criado (legítimo ou por exploit), envia alerta informando o Login, Descrição, Servidor e Horário.

---

## 🚀 Como Configurar e Executar:

### Modo 1: Via Menu Interativo
```bash
ipbx-update
# Escolha a opção [20] Monitor Segurança Telegram
```

### Modo 2: Execução Manual para Teste
```bash
bash /root/ipbx-issabel6.0/scripts/monitor_prisma.sh
```

### Modo 3: Agendamento no Crontab (A cada minuto)
```bash
(crontab -l 2>/dev/null; echo "* * * * * /usr/local/bin/monitor_issabel_users.sh") | crontab -
```

---

## ⚙️ Personalização de Cliente / Token:
Dentro do script `scripts/monitor_prisma.sh`, você pode configurar o nome do cliente:
```bash
CLIENTE="IPBX - Nome do Cliente"
```
Se não for preenchido, o script usa automaticamente o **hostname** da VPS.
