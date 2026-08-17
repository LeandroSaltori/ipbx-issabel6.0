# Guia Completo de Instalação do Asternic CDR no Issabel PBX

Este documento descreve o processo passo a passo de instalação e atualização do módulo de relatórios de chamadas **Asternic CDR Report** (Versão 1.6.6) para os servidores **Issabel PBX** (versões 4 e 5).

---

## 📋 Pré-requisitos
- Servidor **Issabel PBX** instalado e em funcionamento (Asterisk + MariaDB/MySQL).
- Acesso como usuário **root** via terminal SSH.
- Conectividade de rede para download dos pacotes (caso utilize a instalação automática).

---

## 🚀 Método 1: Instalação Automatizada (Recomendado)

O repositório possui um script automatizado que realiza todo o processo de download, backup da versão anterior, cópia dos arquivos customizados, registro no banco de dados e ajuste de permissões.

### Opção A: Comando Direto em Linha Única (via SSH)
Conecte-se ao seu servidor Issabel por SSH como `root` e execute o comando abaixo:

```bash
curl -sSL https://raw.githubusercontent.com/LeandroSaltori/ipbx-issabel5/main/asternic_cdr/instalar-asternic-cdr.sh | bash
```

### Opção B: Execução Local após Clonar o Repositório
Caso você já tenha clonado o repositório no servidor:

```bash
cd /caminho/do/repositorio/ipbx-issabel5/asternic_cdr
chmod +x instalar-asternic-cdr.sh
./instalar-asternic-cdr.sh
```

---

## 🛠️ Método 2: Instalação Manual (Interface Web + CLI)

Caso prefira realizar a instalação manualmente via painel web do IssabelPBX e substituir os arquivos customizados no servidor, siga as etapas abaixo:

### Passo 1: Habilitar o Acesso Direto ao IssabelPBX
1. Acesse a interface web do Issabel (`https://<IP_DO_SEU_SERVIDOR>`).
2. No menu lateral, acesse **Segurança** (*Security*) > **Configurações Avançadas** (*Advanced Settings*).
3. Ative a opção **Acesso Direto ao IssabelPBX** (*Enable Direct Access*).
4. Clique em **Salvar** (*Save*).

### Passo 2: Upload do Módulo Base
1. Acesse o painel de administração do IssabelPBX pelo navegador:
   `https://<IP_DO_SEU_SERVIDOR>/admin`
2. No menu superior ou lateral, acesse **Configuração PBX** > **Administração** > **Administrador de Módulos** (*Module Admin*).
3. Clique na opção de **Upload de Módulo** (*Upload Module*).
4. Selecione o arquivo `asternic_cdr-1.6.6.tgz` (localizado na pasta `asternic_cdr/_Instalador/` deste repositório ou baixado de [asternic.net](https://www.asternic.net/cdrreports/download.php)).
5. Clique em **Enviar** (*Submit*).

### Passo 3: Ativação do Módulo no PBX
1. Na lista de módulos do **Module Admin**, localize **Asternic CDR Report**.
2. Clique na ação **Instalar** (*Install*).
3. Clique em **Processar** (*Process*) e confirme a instalação.
4. Clique no botão vermelho **Aplicar Alterações** (*Apply Config*) no topo da página.

### Passo 4: Substituição e Atualização das Pastas Customizadas (`asternic_cdr_OLD`)
Após a instalação do pacote base via interface web, é necessário aplicar as personalizações do seu repositório:

1. Acesse o servidor via terminal SSH como `root`.
2. **Renomeie a pasta da instalação padrão** para guardar o backup da versão original:
   ```bash
   mv /var/www/html/admin/modules/asternic_cdr /var/www/html/admin/modules/asternic_cdr_OLD
   ```
3. **Puxe/Copie a pasta `asternic_cdr` com as suas alterações** para o local da instalação:
   - *Se você tem o repositório clonado localmente no servidor:*
     ```bash
     cp -rf /caminho/do/repositorio/ipbx-issabel5/asternic_cdr /var/www/html/admin/modules/asternic_cdr
     ```
   - *Se desejar puxar diretamente do GitHub:*
     ```bash
     git clone --depth 1 https://github.com/LeandroSaltori/ipbx-issabel5.git /tmp/ipbx-repo
     cp -rf /tmp/ipbx-repo/asternic_cdr /var/www/html/admin/modules/asternic_cdr
     rm -rf /tmp/ipbx-repo
     ```

### Passo 5: Ajuste de Permissões
Ajuste o proprietário e as permissões dos arquivos para o usuário `asterisk`:

```bash
chown -R asterisk:asterisk /var/www/html/admin/modules/asternic_cdr
chmod -R 755 /var/www/html/admin/modules/asternic_cdr
```

### Passo 6: Recarregar o Asterisk
Execute no terminal para aplicar as novas configurações:

```bash
asterisk -rx "module reload"
```

---

## 🌐 Como Acessar o Painel de Relatórios

Após concluir a instalação por qualquer um dos métodos, o relatório Asternic CDR estará disponível nos seguintes caminhos:

1. **Acesso Direto (Recomendado):**
   - URL: `https://<IP_DO_SEU_SERVIDOR>/admin/config.php?display=asternic_cdr`
2. **Menu do Issabel PBX:**
   - **Configuração PBX** > **Opções Avançadas** > **Asternic CDR Report**
   - ou em **Reports** / **Relatórios** > **Asternic CDR Reports**

---

## 🔍 Solução de Problemas

- **Erro "Not found - The section you requested does not exist or you do not have access to it":**
  Execute no terminal SSH para registrar e habilitar o módulo no FreePBX CLI, atualizar as permissões do usuário admin e recarregar as rotas:
  ```bash
  amportal a ma install asternic_cdr
  amportal a ma enable asternic_cdr
  fwconsole ma install asternic_cdr 2>/dev/null
  fwconsole ma enable asternic_cdr 2>/dev/null
  
  MYSQL_PWD=$(grep -i mysqlrootpwd /etc/issabel.conf | cut -d'=' -f2 | tr -d ' ')
  mysql -u root -p"$MYSQL_PWD" asterisk -e "UPDATE ampusers SET sections='*' WHERE username='admin';"
  
  php /var/lib/asterisk/bin/retrieve_conf
  amportal a r 2>/dev/null || fwconsole reload 2>/dev/null
  ```
  *Dica:* Após executar os comandos acima, faça **Logout** e **Login** novamente no painel web para renovar a sessão do usuário.

- **Página em Branco ou Erro de Permissão em Arquivos:**
  Certifique-se de que o diretório `/var/www/html/admin/modules/asternic_cdr` pertence ao usuário e grupo `asterisk:asterisk`.
  ```bash
  chown -R asterisk:asterisk /var/www/html/admin/modules/asternic_cdr
  chmod -R 755 /var/www/html/admin/modules/asternic_cdr
  ```

---

*Vídeo de referência:* [CDR ASTERNIC FREE - YouTube](https://www.youtube.com/watch?v=6OVUhVTcm5I)