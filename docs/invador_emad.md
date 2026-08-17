🛡️ Guia de Segurança e Monitoramento - Issabel (Caso Emad)
Este guia documenta o processo de limpeza e a criação de um sistema de alerta preventivo contra a injeção de webshells por espelhamento.

1. Limpeza Cirúrgica (Remoção da Assinatura)
Para remover apenas os arquivos maliciosos sem afetar o sistema, utilizamos a assinatura específica encontrada no código invasor:

Bash
# Remove todos os arquivos com a assinatura, exceto o index.php do SMSS
grep -rl "Emad__Was__Here" /var/www/html/ | grep -v "index.php" | xargs rm -f

# Saneia o arquivo legítimo do módulo SMSS sem deletá-lo
sed -i '/Emad__Was__Here/d' /var/www/html/admin/modules/smss/index.php

# Limpa o cache de templates (Obrigatório para remover injeções em memória)
rm -rf /var/www/html/var/templates_c/*

2. Blindagem de Diretório
Após a limpeza, travamos a raiz do diretório web para impedir que novos arquivos sejam criados via exploit PHP:

Bash
# Trava o diretório (Imutabilidade)
chattr +i /var/www/html/

# Nota: Para manutenções futuras, use chattr -i /var/www/html/

3. Script de Monitoramento e Alerta (Telegram)
Script criado para rodar via cron e notificar a equipe da Prisma Telecom caso qualquer rastro do invasor reapareça.

Caminho: /root/monitor_emad.sh

Bash
#!/bin/bash

# --- CONFIGURAÇÕES ---
TOKEN="7558673015:AAEk7FbRtOXCB2xiiQ1fRT0Hwi-KEjf4JlI"
CHAT_ID="-1003562264947"
URL="https://api.telegram.org/bot$TOKEN/sendMessage"
DIR="/var/www/html/"
TERM="Emad__Was__Here"
HOSTNAME=$(hostname)

# --- EXECUÇÃO ---
FILES_FOUND=$(grep -rl "$TERM" "$DIR")

if [ ! -z "$FILES_FOUND" ]; then
    LIST=$(echo "$FILES_FOUND" | head -n 10)
    MESSAGE="🚨 *ALERTA DE SEGURANÇA - $HOSTNAME* 🚨%0A%0AInvasor '*Emad*' detectado!%0AArquivos infectados encontrados:%0A%0A\`$LIST\`%0A%0A⚠️ *Ação:* Verifique se o chattr foi burlado e limpe o templates_c."

    curl -s -X POST "$URL" -d chat_id="$CHAT_ID" -d text="$MESSAGE" -d parse_mode="Markdown" > /dev/null
    echo "$(date): Invasão detectada: $FILES_FOUND" >> /var/log/monitor_emad.log
fi


4. Agendamento Automático (Cron)
Configuração para rodar a verificação a cada 5 minutos:

Bash
# Comando para injetar no crontab sem abrir editor:
(crontab -l 2>/dev/null; echo "*/5 * * * * /root/monitor_emad.sh") | crontab -


✅ Checklist Pós-Incidente
[ ] Alterar senhas de usuários no painel Issabel (prisma / suporte).

[ ] Validar se as rotas de saída de telefonia não possuem destinos internacionais suspeitos.

[ ] Restringir portas 80/443 via Firewall (MikroTik/pfSense).

Servidor Invicta limpo e monitorado. Deseja que eu gere um comando de teste para você disparar uma mensagem "Fake" agora e confirmar se o alerta chega no seu grupo do Telegram?