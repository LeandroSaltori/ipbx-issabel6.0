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

---

## 📱 Manuais e Guias Passo a Passo Dedicados

- 📖 [**Guia Ilustrado de Configuração - Grandstream (GXP / GRP / GDMS)**](ldap_grandstream.md)
- 📖 [**Guia de Configuração - Fanvil & Intelbras (V62, X-Series, TIP 125, TIP 200)**](ldap_fanvil.md)

---

### Resumo Rápido por Marca:

#### 1. Grandstream (GXP16XX, GXP21XX, GRP26XX, GDMS)
Consulte o guia completo com imagens em: [**Manual Grandstream**](ldap_grandstream.md)
- **Server Address:** `IP_OU_DOMINIO_DO_PBX`
- **Port:** `10389`
- **Base:** `dc=pbx,dc=com`
- **User Name:** `cn=admin,dc=pbx,dc=com`
- **LDAP Name Attributes:** `cn displayName`
- **LDAP Number Attributes:** `homePhone` *(ramais)* ou `homePhone telephoneNumber mobile` *(ramais + contatos)*
- **LDAP Name Filter:** `(|(cn=%)(displayName=%))`
- **LDAP Number Filter:** `(homePhone=%)` ou `(|(homePhone=%)(telephoneNumber=%)(mobile=%))`

#### 2. Fanvil & Intelbras (V62, X-Series, TIP 125, TIP 200)
Consulte o guia completo em: [**Manual Fanvil / Intelbras**](ldap_fanvil.md)
- **Servidor:** `IP_OU_DOMINIO_DO_PBX`
- **Porta:** `10389`
- **Base DN:** `dc=pbx,dc=com`
- **Usuário:** `cn=admin,dc=pbx,dc=com`
- **Filtro Nome:** `(|(cn=%)(displayName=%))`
- **Filtro Número:** `(homePhone=%)` ou `(|(homePhone=%)(telephoneNumber=%)(mobile=%))`

#### 3. Yealink (T19P, T21P, T27G, T29G, T4X, T5X, etc.)
- **Menu Web:** *Directory* -> *LDAP*
- **LDAP Server:** `IP_OU_DOMINIO_DO_PBX`
- **Port:** `10389`
- **Base DN:** `dc=pbx,dc=com`
- **User Name / Password:** `cn=admin,dc=pbx,dc=com` / *SuaSenha* (ou em branco se anônimo)
- **LDAP Name Attributes:** `displayName cn`
- **LDAP Number Attributes:** `telephoneNumber mobile homePhone`
- **LDAP Display Name:** `%displayName%`
- **LDAP Search Filter:** `(&(objectClass=*)(|(cn=%)(displayName=%)(telephoneNumber=%)(homePhone=%)))`
