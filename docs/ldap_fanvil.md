# Guia de Configuração LDAP - Fanvil & Intelbras (V62, X-Series, TIP 125, TIP 200) 📖

Este manual orienta o passo a passo para configurar o **Servidor LDAP de Ramais e Agenda** nos telefones IP da **Fanvil** (X3, X4, X5, X6, X7, V62, V64, etc.) e **Intelbras** (linha TIP 125, TIP 200, TIP 425, etc.).

---

## 🌐 Caminho no Painel Web do Telefone

Acesse o IP do telefone no navegador e vá em:
- **Fanvil:** *Phonebook* -> *Cloud Phonebook* -> *LDAP*
- **Intelbras:** *Agenda / Contatos* -> *LDAP*

---

## ⚙️ Tabela de Parâmetros para Fanvil / Intelbras

| Campo no Telefone | Valor Recomendado | Descrição |
| :--- | :--- | :--- |
| **Server Address (Endereço do Servidor)** | `macboot.ipbxprisma.cloud` *(ou IP do PBX)* | Endereço do seu servidor Issabel |
| **Port (Porta)** | `10389` | Porta de comunicação LDAP |
| **Base DN** | `dc=pbx,dc=com` | Base de pesquisa |
| **User Name (Usuário)** | `cn=admin,dc=pbx,dc=com` | Usuário administrador LDAP |
| **Password (Senha)** | *Sua Senha* | Senha definida em `/etc/sysconfig/issabel-ldap` (padrão: `issabelPBX`) |
| **Name Filter (Filtro por Nome)** | `(\|(cn=%)(displayName=%))` | Filtro ao pesquisar contatos |
| **Number Filter (Filtro por Número)** | `(homePhone=%)` <br> *ou* `(\|(homePhone=%)(telephoneNumber=%)(mobile=%))` | Filtro ao buscar por dígitos |
| **Name Attributes (Atributos de Nome)** | `displayName cn` | Atributo retornado para exibir o nome |
| **Number Attributes (Atributos de Número)** | `homePhone` *(ramais)* <br> *ou* `homePhone telephoneNumber mobile` | Atributos retornados com os números |
| **Display Name (Nome de Exibição)** | `%displayName%` ou `%cn%` | Formatação do nome na tela |
| **Max Hits (Máximo de Resultados)** | `1000` | Limite de contatos |
| **Search on Dialing (Buscar ao Discar)** | `Enabled (Habilitado)` | Ativa sugestão ao digitar |
| **Search on Incoming Call (Buscar na Chamada Recebida)** | `Enabled (Habilitado)` | Bina o nome do ramal/contato na chamada |

---

## 📱 Tecla de Acesso Rápido no Aparelho

1. **Tecla DSS / Tecla de Atalho:**
   - Acesse *Function Key* -> *Softkey / DSS Key*.
   - Tipo: **Key Event** -> Subtipo: **LDAP**.
   - Ao pressionar este botão no telefone, ele abre imediatamente a lista de ramais atualizada em tempo real.

2. **Pelo Menu da Agenda:**
   - Pressione o botão físico **Agenda / Phonebook** no aparelho.
   - Navegue até a opção **LDAP**.
