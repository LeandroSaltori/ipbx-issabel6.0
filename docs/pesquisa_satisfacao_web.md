# IPBX-Prisma Telecom

## Instalação de Pesquisa de Satisfação

 - Adicionar pelo Web Developer "Pesquisa de Avaliação"

    Module Name:  Pesquisa de Satistação
   	Your Name: * Leandro
    Your e-mail: * suporte@prismatelecom.com

    Group Permission:  administrator

    Module Level:  Level 2
    Level 1 Parent Exists: Yes   (Para colocar dentro de MENU existente)
    Level 1 Parent: Reports

    Module Type: * Grid

    	Field Name: *  Operador / Fila / Data / Hora / Telefone / Avaliação / Situação

# NO TERMINAL - PUTTY
 - sudo dnf install epel-release -y
 - sudo dnf install phpmyadmin -y

 Acessar phpmyadmin e colcar o arquivo
     nano /etc/httpd/conf.d/phpMyAdmin.conf

 # ------------- COLAR ARQUIVO ABAIXO -----------------------#
# Configuração para Pesquisa de Satisfação no Issabel
Alias /pesquisa /var/www/html/modules/pesquisa_de_satisfacao
Alias /pesquisa_satisfacao /var/www/html/modules/pesquisa_de_satisfacao

<Directory /var/www/html/modules/pesquisa_de_satisfacao/>
   AddDefaultCharset UTF-8
   Options -Indexes +FollowSymLinks
   AllowOverride None

   <IfModule mod_authz_core.c>
       # Apache 2.4
       <RequireAny>
           Require ip 127.0.0.1
           Require ip ::1
           # Redes locais
	    Require ip 192.168.0.0/24
           Require ip 192.168.1.0/24
           Require ip 192.168.2.0/24
           Require ip 192.168.3.0/24
           Require ip 192.168.88.0/24
           # Rede externa (com cautela)
           Require ip 177.35.0.0/24
       </RequireAny>
   </IfModule>

   <IfModule !mod_authz_core.c>
       # Apache 2.2
       Order Deny,Allow
       Deny from all
       Allow from 127.0.0.1
       Allow from ::1
	Allow from 192.168.0.0/24
       Allow from 192.168.1.0/24
       Allow from 192.168.2.0/24
       Allow from 192.168.3.0/24
       Allow from 192.168.88.0/24
       Allow from 177.35.0.0/24
   </IfModule>
</Directory>

# Bloqueio de diretórios sensíveis
<Directory /var/www/html/modules/pesquisa_de_satisfacao/includes/>
    Require all denied
</Directory>

<Directory /var/www/html/modules/pesquisa_de_satisfacao/config/>
    Require all denied
</Directory>

 # ------------- FIM ABAIXO -----------------------#

  Obs: Para editar com o vim, para salvar ESC  : wq!

# Reiniciar  
 systemctl restart httpd

ls -ld /var/www/html/modules/pesquisa_de_satisfacao
sudo chown -R asterisk:asterisk /var/www/html/modules/pesquisa_de_satisfacao
sudo systemctl restart httpd

Tenta acessar pela web https://192.168.0.146/phpMyAdmin, se nao acessar dar esses comandos.

sudo ln -s /usr/share/phpMyAdmin /var/www/html/phpmyadmin
sudo chown -R asterisk:asterisk /var/www/html/phpmyadmin
sudo systemctl restart httpd

Acessar painel WEB, usuario root senha do sql

# Enviar arquivos
 /var/lib/asterisk/sounds/custom
