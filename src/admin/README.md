# 🎛️ Painel Administrativo do PBX (`src/admin/`)

Este diretório contém os componentes customizados da interface web administrativa do IssabelPBX / FreePBX (`/var/www/html/admin/`).

---

## 📌 Opção do Menu Interativo: **[3] Painel Admin**

Ao selecionar a opção **`[3]`** no menu **`ipbx-update`** (`ipbx-menu.sh`), esta pasta é sincronizada com o servidor web.

### 🎯 O que este módulo faz:
1. **Wrapper de Autenticação Segura:** Atualiza `issabel_issabelpbx_auth.php` para sincronizar sessões do Issabel com o módulo PBX Admin sem deslogar o operador.
2. **Correção de Views e Helpers:** Corrige caminhos e bibliotecas legadas do FreePBX no PHP 7 e PHP 8 (Rocky Linux 8 / Issabel 5).
3. **Módulos de Configuração Avançada:** Inclui os formulários de Troncos SIP/PJSIP, Rotas de Entrada, Rotas de Saída e Extensões.

### 📂 Destino no Servidor:
- `/var/www/html/admin/`

### 🔒 Permissões Aplicadas:
- Usuário/Grupo: `asterisk:asterisk`
- Permissões: `755` para diretórios e `644` para arquivos.
