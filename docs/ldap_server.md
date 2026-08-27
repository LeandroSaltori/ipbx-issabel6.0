# Servidor LDAP de Ramais e Agenda (issabel-ldap) 🗄️

O **Issabel LDAP Directory** é um serviço leve desenvolvido para disponibilizar a lista de ramais do PBX e os contatos da Agenda Telefônica (*Address Book*) para dispositivos externos via protocolo LDAP.

---

## ⚙️ Como Funciona

- **Porta padrão:** `10389/tcp`
- **Origem dos dados:** Consulta em tempo real as tabelas de ramais do Asterisk/IssabelPBX (`asterisk.devices` / `asterisk.sip` / `asterisk.users`) e a base SQLite do Address Book (`/var/www/db/address_book.db`).
- **Autenticação:**
  - **Anônimo (Padrão):** Se não for definida senha, permite buscas públicas/anônimas diretamente da rede local.
  - **Com Autenticação:** Usuário `cn=admin,dc=pbx,dc=com` com senha configurada no arquivo `/etc/sysconfig/issabel-ldap`.

### Campos Retornados nas Consultas LDAP:
- `displayName`: Nome de exibição completo
- `cn`: Nome comum
- `gn`: Primeiro nome
- `sn`: Sobrenome
- `telephoneNumber`: Número de telefone principal
- `mobile`: Celular
- `homePhone`: Ramal interno
- `mail`: E-mail

---

## 🚀 Instalação e Gerenciamento

O servidor LDAP é instalado automaticamente pelo `install.sh` ou individualmente pelo menu modular:

```bash
# Pelo Menu Modular:
ipbx-update  # Escolha a opção [18] Servidor LDAP
```

### Comandos de Gerenciamento do Serviço (Systemd):
```bash
# Verificar status do LDAP
systemctl status issabel-ldap.service

# Reiniciar serviço
systemctl restart issabel-ldap.service

# Parar serviço
systemctl stop issabel-ldap.service

# Ver logs em tempo real
journalctl -u issabel-ldap.service -f
```

---

## 🔒 Parâmetros e Configuração de Acesso

O arquivo de configuração fica localizado em `/etc/sysconfig/issabel-ldap`.

Para definir usuário e senha de autenticação:
```bash
OPTIONS="-ldapuser cn=admin,dc=pbx,dc=com -ldappass MinhaSenhaForte"
```

Para habilitar modo anônimo (sem necessidade de senha nos telefones):
```bash
OPTIONS=""
```

Após alterar o arquivo, reinicie o serviço:
```bash
systemctl restart issabel-ldap.service
```

---

## 🛡️ Firewall

O instalador já libera a porta automaticamente no `firewalld`. Caso utilize regras manuais de `iptables`:
```bash
iptables -A INPUT -p tcp --dport 10389 -j ACCEPT
```

---

## 📱 Configuração em Telefones IP

### 1. Yealink (T19P, T21P, T27G, T29G, T4X, T5X, etc.)
- **Menu Web:** *Directory* -> *LDAP*
- **LDAP Server:** `IP_DO_SEU_PBX`
- **Port:** `10389`
- **Base DN:** `dc=asterisk` ou `ou=issabel` (ou deixar vazio)
- **User Name / Password:** Deixar vazio (se anônimo) ou preencher conforme `/etc/sysconfig/issabel-ldap`
- **LDAP Name Attributes:** `displayName cn`
- **LDAP Number Attributes:** `telephoneNumber mobile homePhone`
- **LDAP Display Name:** `%displayName%`
- **LDAP Search Filter:** `(&(objectClass=*)(|(cn=%)(displayName=%)(telephoneNumber=%)(homePhone=%)))`

### 2. Grandstream (GXP16XX, GXP21XX, GRP26XX)
- **Menu Web:** *Contacts* -> *LDAP*
- **Server Address:** `IP_DO_SEU_PBX`
- **Port:** `10389`
- **Base DN:** `dc=asterisk`
- **LDAP Name Attributes:** `displayName cn`
- **LDAP Number Attributes:** `telephoneNumber homePhone mobile`

### 3. Intelbras / Fanvil (TIP 125, TIP 200, V62, X3, X4, etc.)
- **Menu Web:** *Agenda / Contatos* -> *LDAP*
- **Servidor:** `IP_DO_SEU_PBX`
- **Porta:** `10389`
- **Atributos de Nome:** `displayName`
- **Atributos de Telefone:** `homePhone telephoneNumber mobile`
