## INSTALAÇÃO PAINEL OPERADOR V5

Github
https://github.com/ISSABELPBX/panel-issabel5

   - Copie a pasta "control_panel" para o diretorio:  
         /var/www/html/modules/control_panel

   - sqlite3 /var/www/db/acl.db   (já vai cair na linha de comando)
   - INSERT INTO acl_resource (name, description) VALUES ('control_panel', 'IPbx Panel');
     Enter + "ctrl + d" para sair
     
   - sqlite3 /var/www/db/menu.db 
    INSERT INTO menu (id, IdParent, Link, Name, Type, order_no) VALUES ('control_panel', 'pbxconfig', '', 'IPbx Panel', 'module', 8);

Dê as pemissões dos usuarios:
   - Users -> Group Permissions -> pbxconfig-> Control Panel
  
# ALTERAR AREA DO NOVO PAINEL IPBX
var/www/html/modules/control_panel/themes/default/panel.tpl

Para abrir e fechar comentarios 
   {* 
      Tudo que fica comentado aqui dentro, é excluido do código.
      *}

      Lista del trocales - With 100%

      # ALTERAR AREA DO NOVO PAINEL IPBX
