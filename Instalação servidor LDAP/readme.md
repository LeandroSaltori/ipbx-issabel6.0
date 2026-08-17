# Issabel LDAP Directory 🗄️

Um servidor LDAP simples desenvolvido para que o Issabel PBX possa compartilhar os contatos de ramais e da agenda de endereços com dispositivos externos (telefones IP como Yealink, Grandstream, Intelbras, Fanvil, Snom, Polycom, etc.).

## ⚙️ Como Funciona

O serviço inicia o LDAP na porta **10389** e responde às requisições de busca de diretório. Ele faz isso traduzindo as requisições em consultas SQL diretamente na tabela de usuários do IssabelPBX e no banco de dados SQLite (*Address Book*) do Issabel.

* **Com Autenticação:** Se uma senha for fornecida nos parâmetros de inicialização, as consultas LDAP serão autenticadas usando o usuário `cn=admin,dc=pbx,dc=com` (por padrão).
* **Sem Autenticação (Anônimo):** Se nenhuma senha for definida, o servidor permitirá consultas sem exigir autenticação.

**Campos retornados para cada resultado:**
* `displayName` (Nome de exibição)
* `cn` (Nome comum ou nome completo)
* `gn` (Nome)
* `sn` (Sobrenome)
* `telephoneNumber` (Número de telefone principal)
* `mobile` (Celular)
* `homePhone` (Geralmente utilizado como o número do ramal)
* `mail` (E-mail)

---

## 🚀 Como Instalar

A instalação é feita via linha de comando, copiando o arquivo compactado para o servidor.

1. Baixe o arquivo `issabel-ldap-1.0.0.zip`.
2. Utilizando o **WinSCP** (ou ferramenta similar), envie o arquivo `.zip` para o caminho `/usr/src` do seu servidor Issabel.
3. Acesse o servidor via terminal SSH (Putty, MobaXterm, etc.) como `root` e execute os comandos abaixo em sequência:

```bash
cd /usr/src
unzip issabel-ldap-1.0.0.zip
cd issabel-ldap-1.0.0
make install