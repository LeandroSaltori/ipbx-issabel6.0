# Guia de Configuração LDAP - Grandstream (GXP / GRP / GDMS) 📖

Este manual orienta a configuração do **Servidor LDAP de Ramais e Agenda** nos telefones IP e Gateways da **Grandstream** (séries GXP16xx, GXP21xx, GRP26xx e plataforma em nuvem GDMS).

---

## 📸 Imagens de Referência da Configuração

### 1. Parâmetros de Conexão e Autenticação
![Configuração LDAP Grandstream - Parte 1](images/grandstream_ldap_config_1.png)

### 2. Filtros de Busca e Identificação de Chamadas
![Configuração LDAP Grandstream - Parte 2](images/grandstream_ldap_config_2.png)

---

## ⚙️ Tabela Detalhada de Parâmetros

| Campo / Parâmetro | Valor Recomendado | Descrição / Observação |
| :--- | :--- | :--- |
| **LDAP protocol** | `LDAP` | Protocolo padrão de comunicação. |
| **Server Address** | `macboot.ipbxprisma.cloud` *(ou IP do PBX)* | Endereço FQDN/Domínio ou IP do seu servidor Issabel. |
| **Port** | `10389` | Porta padrão do serviço `issabel-ldap`. |
| **Base** | `dc=pbx,dc=com` | Base DN padrão de consulta. |
| **User Name** | `cn=admin,dc=pbx,dc=com` | Usuário administrador padrão configurado no serviço. |
| **Password** | *Sua Senha* | Senha definida em `/etc/sysconfig/issabel-ldap` (padrão: `issabelPBX`). |
| **LDAP Name Attributes** | `cn displayName` | Atributos utilizados para exibir o nome do contato. |
| **LDAP Number Attributes** | `homePhone` *(apenas ramais)* <br> **OU** <br> `homePhone telephoneNumber mobile` *(ramais + agenda)* | `homePhone`: número do ramal interno. <br> `telephoneNumber`/`mobile`: contatos externos da Agenda. |
| **LDAP Name Filter** | `(\|(cn=%)(displayName=%))` | Filtro aplicado ao pesquisar contatos por nome. |
| **LDAP Number Filter** | `(homePhone=%)` *(apenas ramais)* <br> **OU** <br> `(\|(homePhone=%)(telephoneNumber=%)(mobile=%))` | Filtro aplicado ao pesquisar por dígitos numéricos. |
| **LDAP Display Name** | `%cn` ou `%displayName` | Formato como o nome será exibido na tela do aparelho. |
| **Max. Hits** | `3000` *(ou 1000)* | Limite máximo de contatos retornados na consulta. |
| **LDAP Lookup For Dial** | `Ativado (Yes)` | Busca automática ao digitar no teclado para discar. |
| **LDAP Lookup For Incoming Call** | `Ativado (Yes)` | Identifica e mostra o nome do contato nas chamadas recebidas (Bina inteligente). |

---

## 💡 Dicas de Otimização

1. **Apenas Ramais Internos:**
   - Mantenha `LDAP Number Attributes = homePhone` e `LDAP Number Filter = (homePhone=%)`.
2. **Ramais + Contatos da Agenda Telefônica (Clientes/Fornecedores):**
   - Configure `LDAP Number Attributes = homePhone telephoneNumber mobile` e `LDAP Number Filter = (|(homePhone=%)(telephoneNumber=%)(mobile=%))`.
3. **Consulta Direta no Aparelho:**
   - No teclado do Grandstream, basta pressionar o botão **Contacts / Phonebook** e selecionar **LDAP** para navegar por todos os ramais da empresa em tempo real.
