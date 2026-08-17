# Como Instalar o Asternic CDR Report

## 1. Instalação Automática via Terminal (SSH)

Execute o comando abaixo como `root` no servidor Issabel:

```bash
curl -sSL https://raw.githubusercontent.com/LeandroSaltori/ipbx-issabel5/main/asternic_cdr/instalar-asternic-cdr.sh | bash
```

---

## 2. Instalação Manual via Web (IssabelPBX)

1. **Permitir Acesso Direto:**
   Acesse **Segurança** > **Configurações Avançadas** > Marque **Acesso Direto ao IssabelPBX** e Salve.

2. **Acessar o IssabelPBX:**
   Vá para `https://{IP_DO_SERVIDOR}/admin`

3. **Upload do Módulo:**
   - Acesse **Configuração PBX** > **Administração** > **Administrador de Módulos** (*Module Admin*).
   - Faça o upload do pacote `_Instalador/asternic_cdr-1.6.6.tgz`.
   - Clique em **Instalar** > **Processar** > **Aplicar Alterações**.

4. **Substituir e Puxar as Pastas Alteradas:**
   Renomeie a pasta da instalação padrão para `asternic_cdr_OLD` e copie a sua pasta `asternic_cdr` com as alterações:
   ```bash
   # Renomeia a instalação padrão
   mv /var/www/html/admin/modules/asternic_cdr /var/www/html/admin/modules/asternic_cdr_OLD

   # Puxa/copia a pasta alterada do seu repositório
   cp -rf /caminho/do/repositorio/ipbx-issabel5/asternic_cdr /var/www/html/admin/modules/asternic_cdr

   # Ajusta as permissões de arquivo
   chown -R asterisk:asterisk /var/www/html/admin/modules/asternic_cdr
   chmod -R 755 /var/www/html/admin/modules/asternic_cdr
   ```

---

## 3. Link de Acesso ao Relatório

Após instalado, acesse o painel pelo navegador:

```text
https://{IP_DO_SERVIDOR}/admin/config.php?display=asternic_cdr
```