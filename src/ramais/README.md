# 📞 Módulo e API de Ramais

Este módulo fornece interface e scripts PHP para comunicação AMI (Asterisk Manager Interface), testes de registros de ramais e status do PABX.

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
