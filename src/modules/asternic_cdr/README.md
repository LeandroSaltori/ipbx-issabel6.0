# Asternic CDR Report - Módulo para Issabel PBX

O **Asternic CDR Report** é um módulo de relatórios avançados de chamadas (CDR) para servidores **Issabel PBX** (versões 4 e 5) e FreePBX.

---

## ⚡ Instalação Automática (Recomendada)

Para instalar o módulo de forma rápida e automatizada diretamente no seu servidor Issabel:

```bash
curl -sSL https://raw.githubusercontent.com/LeandroSaltori/ipbx-issabel5/main/asternic_cdr/instalar-asternic-cdr.sh | bash
```

Ou, se você já possui este repositório clonado no servidor:

```bash
cd asternic_cdr
chmod +x instalar-asternic-cdr.sh
./instalar-asternic-cdr.sh
```

---

## 🛠️ Instalação Manual

1. Habilite o **Acesso Direto ao IssabelPBX** no menu **Segurança** > **Configurações Avançadas**.
2. Acesse `https://<IP_DO_SERVIDOR>/admin` no seu navegador.
3. No **Administrador de Módulos** (*Module Admin*), faça o upload do pacote `_Instalador/asternic_cdr-1.6.6.tgz`.
4. Instale o módulo e clique em **Aplicar Alterações** (*Apply Config*).
5. Copie os arquivos customizados desta pasta para `/var/www/html/admin/modules/asternic_cdr/`.
6. Ajuste as permissões no terminal SSH:
   ```bash
   chown -R asterisk:asterisk /var/www/html/admin/modules/asternic_cdr
   chmod -R 755 /var/www/html/admin/modules/asternic_cdr
   ```

---

## 🌐 Como Acessar o Relatório

- **Link Direto:** `https://<IP_DO_SERVIDOR>/admin/config.php?display=asternic_cdr`
- **Menu Issabel:** **Configuração PBX** > **Opções Avançadas** > **Asternic CDR Report**

---

*Para um guia detalhado passo a passo, consulte o arquivo [AsternicCDR_Install.md](../AsternicCDR_Install.md) na raiz do repositório.*