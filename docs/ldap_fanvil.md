# Guia de Configuração LDAP - Fanvil & Intelbras (V62, X-Series, TIP 125, TIP 200) 📖

Este manual orienta o passo a passo completo para configurar o **Servidor LDAP de Ramais e Agenda Corporativa** nos telefones IP da **Fanvil** (X3, X4, X5, X6, X7, V62, V64, etc.) e **Intelbras** (linha TIP 125, TIP 200, TIP 425, etc.).

---

## 🌐 Caminho no Painel Web do Telefone

Acesse o IP do telefone no navegador (usuário/senha padrão: `admin`/`admin`) e vá em:
- **Fanvil:** *Phonebook* -> *Cloud Phonebook* -> *LDAP*
- **Intelbras:** *Agenda / Contatos* -> *LDAP*

---

## ⚙️ Tabela de Parâmetros para Fanvil / Intelbras

| Campo no Telefone | Valor Recomendado | Descrição / Observação |
| :--- | :--- | :--- |
| **Server Address (Endereço do Servidor)** | `macboot.ipbxprisma.cloud` *(ou IP do PBX)* | Domínio FQDN ou IP do servidor Issabel |
| **Port (Porta)** | `10389` | Porta de comunicação do `issabel-ldap` |
| **Base DN** | `dc=pbx,dc=com` | Base raiz de pesquisa |
| **User Name (Usuário)** | `cn=admin,dc=pbx,dc=com` | Usuário administrador LDAP |
| **Password (Senha)** | *Sua Senha* | Senha definida em `/etc/sysconfig/issabel-ldap` (padrão: `issabelPBX`) |
| **Name Filter (Filtro por Nome)** | `(\|(cn=%)(displayName=%))` | Filtro ao pesquisar contatos por texto |
| **Number Filter (Filtro por Número)** | `(homePhone=%)` <br> *ou* `(\|(homePhone=%)(telephoneNumber=%)(mobile=%))` | Filtro ao buscar por dígitos |
| **Name Attributes (Atributos de Nome)** | `displayName cn` | Atributo retornado para exibir o nome |
| **Number Attributes (Atributos de Número)** | `homePhone` *(ramais)* <br> *ou* `homePhone telephoneNumber mobile` | Atributos retornados com os números |
| **Display Name (Nome de Exibição)** | `%displayName%` ou `%cn%` | Formatação do nome na tela |
| **Max Hits (Máximo de Resultados)** | `1000` | Limite de contatos por consulta |
| **Search on Dialing (Buscar ao Discar)** | `Enabled (Habilitado)` | Ativa sugestão e autocomplete ao digitar |
| **Search on Incoming Call (Bina Inteligente)** | `Enabled (Habilitado)` | Identifica o nome do contato em chamadas recebidas |

---

## 🔘 Configurando Tecla de Atalho (DSS Key / Softkey) com 1 Clique

Para que os usuários acessem a agenda corporativa e os ramais diretamente na tela inicial do telefone com **um único toque**:

### 1. Configuração de DSS Key (Botões Laterais com LED):
1. Acesse o menu web: **Function Key** ➔ **DSS Key**.
2. Escolha a tecla desejada (ex: `DSS Key 1` ou `Line Key`).
3. Configure os seguintes campos:
   - **Type (Tipo):** `Key Event`
   - **Subtype (Subtipo):** `LDAP` (ou `Cloud Phonebook`)
   - **Title / Label:** `Agenda` (ou `Ramais`)
4. Clique em **Apply**. O botão na tela do telefone exibirá o rótulo "Agenda" e abrirá o diretório instantaneamente!

### 2. Configuração de Softkey (Botões de Tela Inferiores):
1. Acesse: **Function Key** ➔ **Softkey**.
2. No estado **Desktop / Standby (Tela Inicial)**, adicione o atalho `LDAP` ou `Phonebook`.
3. Clique em **Apply**.

---

## 🚀 Funcionalidades Avançadas e Usabilidade

1. **Bina Inteligente em Tempo Real (Incoming Lookup):**
   - Quando um cliente ou ramal ligar, o telefone IP consulta o servidor LDAP em milissegundos e mostra na tela LCD o **Nome Completo do Contato** cadastrado no PBX, mesmo que o número não esteja salvo na memória local do aparelho.

2. **Busca Preditiva ao Tirar do Gancho:**
   - Ao tirar o monofone do gancho ou digitar os primeiros dígitos/letras, o telefone sugere na tela os ramais e contatos correspondentes para discagem rápida.

3. **Atualização Automática sem Reboot:**
   - Qualquer ramal criado ou editado no Issabel fica imediatamente disponível para consulta em todos os telefones IP da empresa sem necessidade de reiniciar os aparelhos.
