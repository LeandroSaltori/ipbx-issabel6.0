#!/bin/bash
# ==============================================================================
# SINCRONIZADOR DE CERTIFICADOS E EXCLUSÃO EM TEMPO REAL - OPENVPN ISSABEL
# ==============================================================================
# Garante que, se um certificado for apagado no gerenciador da web (Create Client Certificates),
# ele seja IMEDIATAMENTE revogado no Easy-RSA, inserido na CRL (crl.pem), removido do allclients.txt
# e a conexão ativa seja DERRUBADA no mesmo instante.
# ==============================================================================

set -e

CLIENTKEYS_DIR="/etc/openvpn/clientkeys"
[ ! -d "$CLIENTKEYS_DIR" ] && CLIENTKEYS_DIR="/var/www/html/modules/easy_vpn/clientkeys"

EASYRSA_DIR="/usr/share/easy-rsa/3.0.8"
[ ! -d "$EASYRSA_DIR" ] && EASYRSA_DIR="/usr/share/easy-rsa/3"
[ ! -d "$EASYRSA_DIR" ] && EASYRSA_DIR="/etc/openvpn/easy-rsa"

ALLCLIENTS_FILE="/etc/openvpn/allclients.txt"
[ ! -f "$ALLCLIENTS_FILE" ] && ALLCLIENTS_FILE="/etc/openvpn/server/allclients.txt"

REVOKED_FILE="/etc/openvpn/revokedclients.txt"
[ ! -f "$REVOKED_FILE" ] && REVOKED_FILE="/etc/openvpn/server/revokedclients.txt"

IPP_FILE="/etc/openvpn/server/ipp.txt"
[ ! -f "$IPP_FILE" ] && IPP_FILE="/etc/openvpn/ipp.txt"

CRL_DEST="/etc/openvpn/server/crl.pem"

CHANGES_MADE=0

# 1. Se a pasta de clientkeys estiver vazia, revoga e limpa todos os clientes
if [ -d "$CLIENTKEYS_DIR" ]; then
    EXISTING_FILES=$(ls -1 "$CLIENTKEYS_DIR" 2>/dev/null | grep -E "\.zip$|\.ovpn$|\.tar\.gz$" || true)
    
    if [ -f "$ALLCLIENTS_FILE" ]; then
        TEMP_ALL=$(mktemp)
        > "$TEMP_ALL"
        
        while IFS= read -r CLIENT_NAME || [ -n "$CLIENT_NAME" ]; do
            CLIENT_NAME=$(echo "$CLIENT_NAME" | tr -d '\r' | tr -d '\n' | xargs)
            [ -z "$CLIENT_NAME" ] && continue
            [ "$CLIENT_NAME" = "server" ] && continue
            
            # Verifica se o arquivo correspondente existe na pasta clientkeys
            MATCH_FOUND=0
            for EXT in "zip" "ovpn" "tar.gz"; do
                if [ -f "$CLIENTKEYS_DIR/${CLIENT_NAME}.${EXT}" ] || [ -f "$CLIENTKEYS_DIR/${CLIENT_NAME}-client.${EXT}" ]; then
                    MATCH_FOUND=1
                    break
                fi
            done
            
            # Se não encontrou o arquivo na pasta clientkeys, significa que foi APAGADO no front!
            if [ "$MATCH_FOUND" -eq 0 ]; then
                echo "[SEGURANÇA] Cliente '$CLIENT_NAME' foi apagado da pasta de certificados web. Revogando..."
                
                # Revoga no Easy-RSA se existir o binário e a PKI
                if [ -d "$EASYRSA_DIR" ] && [ -f "$EASYRSA_DIR/easyrsa" ]; then
                    (cd "$EASYRSA_DIR" && EASYRSA_BATCH=1 ./easyrsa revoke "$CLIENT_NAME" 2>/dev/null || true)
                fi
                
                # Adiciona ao arquivo de revogados
                if [ -f "$REVOKED_FILE" ]; then
                    if ! grep -q "^${CLIENT_NAME}$" "$REVOKED_FILE"; then
                        echo "$CLIENT_NAME" >> "$REVOKED_FILE"
                    fi
                fi
                
                # Remove do ipp.txt para liberar lease
                if [ -f "$IPP_FILE" ]; then
                    sed -i "/^${CLIENT_NAME},/d" "$IPP_FILE" 2>/dev/null || true
                fi
                
                CHANGES_MADE=1
            else
                echo "$CLIENT_NAME" >> "$TEMP_ALL"
            fi
        done < "$ALLCLIENTS_FILE"
        
        /bin/cp -f "$TEMP_ALL" "$ALLCLIENTS_FILE" 2>/dev/null || true
        [ -f /etc/openvpn/server/allclients.txt ] && /bin/cp -f "$TEMP_ALL" /etc/openvpn/server/allclients.txt 2>/dev/null || true
        rm -f "$TEMP_ALL"
    fi
fi

# 2. Se houve qualquer revogação ou se o crl.pem precisa ser gerado
if [ "$CHANGES_MADE" -eq 1 ] || [ ! -f "$CRL_DEST" ]; then
    if [ -d "$EASYRSA_DIR" ] && [ -f "$EASYRSA_DIR/easyrsa" ]; then
        (cd "$EASYRSA_DIR" && ./easyrsa gen-crl 2>/dev/null || true)
        if [ -f "$EASYRSA_DIR/pki/crl.pem" ]; then
            /bin/cp -f "$EASYRSA_DIR/pki/crl.pem" /etc/openvpn/server/crl.pem 2>/dev/null || true
            /bin/cp -f "$EASYRSA_DIR/pki/crl.pem" /etc/openvpn/crl.pem 2>/dev/null || true
            chmod 644 /etc/openvpn/server/crl.pem /etc/openvpn/crl.pem 2>/dev/null || true
            chown asterisk:asterisk /etc/openvpn/server/crl.pem /etc/openvpn/crl.pem 2>/dev/null || true
        fi
    fi
    
    # 3. Recarrega o OpenVPN para desconectar na mesma hora qualquer cliente revogado
    systemctl restart openvpn-server@server.service 2>/dev/null || systemctl restart openvpn@server.service 2>/dev/null || true
fi
