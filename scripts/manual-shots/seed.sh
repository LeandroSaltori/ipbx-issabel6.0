#!/bin/bash
# Dados de EXEMPLO (fictícios) só para tirar as figuras do manual no ambiente de teste.
DB=/tmp/claude-0/-home-claude/c6c31332-6c51-5b72-ac5e-03aaa26652ab/scratchpad/ut/panel/.ht_whatsapp_config.sqlite
sqlite3 "$DB" <<'SQL'
DELETE FROM crm_contacts; DELETE FROM sent_logs; DELETE FROM wa_scheduled;
UPDATE extensions_config SET agent_name='Maria Souza', whatsapp_number='5511999990001' WHERE extension='201';
UPDATE extensions_config SET agent_name='João Pereira', whatsapp_number='5511999990002', send_call_recording=1 WHERE extension='202';
UPDATE extensions_config SET agent_name='Ana Costa', whatsapp_number='5511999990003', send_ai_summary=1 WHERE extension='203';
INSERT INTO crm_contacts(name,phone,email,company,tag,source) VALUES
('Carlos Almeida','5511988880001','carlos@exemplo.com.br','Loja Aurora','Cliente','Manual'),
('Fernanda Rocha','5511988880002','fernanda@exemplo.com.br','Rocha & Filhos','Comercial','Manual'),
('Ricardo Nunes','5511988880003','','Nunes Transportes','Financeiro','PABX'),
('Patrícia Gomes','5511988880004','patricia@exemplo.com.br','','VIP','Manual'),
('Eduardo Martins','5511988880005','','Martins Eletro','Novo Lead','PABX');
INSERT INTO sent_logs(phone,call_id,extension,rule_type,message,status,created_at) VALUES
('5511988880001','c1','201','ext-prisma-no-answer','Olá! Vimos que você tentou ligar para nosso setor. Como podemos ajudar?','success',datetime('now','localtime','-1 hour')),
('5511999990002','c2','202','Notificação Interna','Atenção, João: você teve uma chamada não atendida do número 11 98888-0003.','success',datetime('now','localtime','-3 hour')),
('5511988880002','c3','203','ext-prisma-transbordo','Olá! Já iniciamos seu atendimento VIP por aqui.','success',datetime('now','localtime','-5 hour')),
('5511988880004','c4','202','ext-prisma-audio','Gravação da sua chamada','success',datetime('now','localtime','-26 hour')),
('5511988880005','c5','201','ext-prisma-no-answer','Olá! Vimos que você tentou ligar...','failed',datetime('now','localtime','-30 hour')),
('5511988880003','c6','203','Notificação Interna','Atenção, Ana: chamada não atendida.','success',datetime('now','localtime','-50 hour'));
DELETE FROM wp_dss; INSERT INTO wp_dss(uid,slot,label,number) VALUES('1',0,'João Pereira','202'),('1',1,'Ana Costa','203'),('1',2,'Carlos Lima','204'),('1',3,'Financeiro','210'),('1',4,'Recepção','100');
INSERT INTO wa_scheduled(phone,contact_name,message,send_at,source,created_by) VALUES('5511988880001','Carlos Almeida','Bom dia, Carlos! Segue o resumo de ontem.',datetime('now','localtime','+1 day'),'Chat IA','Maria Souza');
SQL
# chave FICTICIA so no ambiente de teste, para o botao do assistente aparecer nas figuras
cd /tmp/claude-0/-home-claude/c6c31332-6c51-5b72-ac5e-03aaa26652ab/scratchpad/ut/panel && php -r "require 'includes/functions.php'; saveSetting('ai_api_key','gsk_exemplo_ficticio_apenas_para_figuras');" >/dev/null 2>&1
