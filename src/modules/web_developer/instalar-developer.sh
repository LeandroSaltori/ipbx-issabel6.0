#!/bin/bash

echo "📥 Baixando módulos do GitHub..."

# Caminho temporário
TMP_DIR="/tmp/issabel-dev-install"
REPO="https://github.com/LeandroSaltori/ipbx-issabel6.0.git"

# Remove se já existe
rm -rf "$TMP_DIR"
mkdir -p "$TMP_DIR"
cd "$TMP_DIR" || exit 1

# Clona o repositório
git clone "$REPO" .

# Copia os módulos para o Issabel
for MODULE in build_module delete_module language_admin; do
    if [ -d "web_developer/$MODULE" ]; then
        echo "✅ Instalando módulo: $MODULE"
        rm -rf "/var/www/html/modules/$MODULE"
        cp -r "web_developer/$MODULE" /var/www/html/modules/
        chown -R asterisk:asterisk "/var/www/html/modules/$MODULE"
    else
        echo "⚠️ Módulo $MODULE não encontrado no repositório."
    fi
done

echo "✅ Instalação concluída!"
