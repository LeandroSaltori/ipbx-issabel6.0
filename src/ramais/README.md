# 📞 Módulo e API de Ramais (`src/ramais/`)

Este módulo fornece interface e scripts PHP para comunicação AMI (Asterisk Manager Interface), testes de registros de ramais e status do PABX.

---

## 📌 Opção do Menu Interativo: **[21] Ferramentas Diagnóstico**

Ao selecionar a opção **`[21]`** no menu **`ipbx-update`** (`ipbx-menu.sh`), esta API é instalada em `/var/www/html/ramais/` juntamente com utilitários de rede (`tcpdump`, `sngrep`, `nmtui`).

## 📁 Arquivos Incluídos

- `ami_api.php`: API para consulta de ramais e eventos via AMI.
- `index.html`: Interface visual de consulta de status de ramais.
- `test_ami.php`: Script de teste rápido de conexão com a porta AMI (5038).

## 🚀 Instalação Manual

Para implantar manualmente no servidor web do Issabel:

```bash
mkdir -p /var/www/html/ramais
cp -rf * /var/www/html/ramais/
chown -R asterisk:asterisk /var/www/html/ramais
```

Acesse via navegador: `https://<IP_DO_SERVIDOR>/ramais/`
