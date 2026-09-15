#!/bin/bash
# ==============================================================================
# IPBX ISSABEL (PRISMA TELECOM) - GERENCIADOR DE ÁUDIOS E SONS PT-BR
# ==============================================================================
# Este script resolve problemas de áudios em inglês no Asterisk (como troncos
# ocupados, números incorretos ou indisponíveis), sincronizando o pacote de
# idioma pt_BR, gerando áudios faltantes e configurando o Asterisk globalmente.
#
# Opções:
#   1) Correção Cirúrgica (Preserva gravações existentes + corrige faltantes)
#   2) Geração/Tradução Geral (Síntese neural de voz em português)
#   3) Restaurar backup original (en_old)
# ==============================================================================

# Desativa aliases do root para operações limpas de arquivos
unalias cp 2>/dev/null || true
unalias mv 2>/dev/null || true
unalias rm 2>/dev/null || true
shopt -s expand_aliases 2>/dev/null || true

# --- CORES PARA LOGS ---
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
CYAN='\033[0;36m'
WHITE='\033[1;37m'
NC='\033[0m'

log_info()    { echo -e "${CYAN}[INFO]${NC} $1"; }
log_success() { echo -e "${GREEN}[SUCESSO]${NC} $1"; }
log_warn()    { echo -e "${YELLOW}[AVISO]${NC} $1"; }
log_error()   { echo -e "${RED}[ERRO]${NC} $1"; }

if [ "$EUID" -ne 0 ]; then
    log_error "Este script precisa ser executado como root!"
    exit 1
fi

SOUNDS_DIR="/var/lib/asterisk/sounds"
PT_DIR="$SOUNDS_DIR/pt_BR"
EN_DIR="$SOUNDS_DIR/en"
BACKUP_EN="$SOUNDS_DIR/en_old"

# Criação de Snapshot de Segurança antes de alterar áudios
create_backup_snapshot() {
    local timestamp=$(date '+%Y-%m-%d_%H%M%S')
    local snapshot_dir="/var/backup/ipbx/backup_${timestamp}"
    mkdir -p "$snapshot_dir/asterisk" 2>/dev/null || true
    echo "Audios PT-BR - $(date '+%d/%m/%Y às %H:%M:%S')" > "$snapshot_dir/manifesto.txt"
    [ -f /etc/asterisk/asterisk.conf ] && cp -pf /etc/asterisk/asterisk.conf "$snapshot_dir/asterisk/" 2>/dev/null || true
    log_info "Snapshot de segurança criado em $snapshot_dir"
}

# Configura defaultlanguage = pt_BR no asterisk.conf
configurar_asterisk_conf() {
    log_info "Configurando idioma global pt_BR no /etc/asterisk/asterisk.conf..."
    if [ -f /etc/asterisk/asterisk.conf ]; then
        if grep -qE "defaultlanguage[[:space:]]*=[[:space:]]*en" /etc/asterisk/asterisk.conf 2>/dev/null; then
            sed -i 's/defaultlanguage[[:space:]]*=[[:space:]]*en/defaultlanguage = pt_BR/g' /etc/asterisk/asterisk.conf 2>/dev/null || true
        elif grep -q "defaultlanguage" /etc/asterisk/asterisk.conf 2>/dev/null; then
            sed -i 's/^.*defaultlanguage.*/defaultlanguage = pt_BR/' /etc/asterisk/asterisk.conf 2>/dev/null || true
        else
            sed -i '/\[options\]/a defaultlanguage = pt_BR' /etc/asterisk/asterisk.conf 2>/dev/null || true
        fi
        log_success "Idioma padrão do Asterisk configurado para pt_BR."
    fi
}

# Garante links simbólicos case-insensitive
garantir_links_simbolicos() {
    log_info "Garantindo links simbólicos de compatibilidade..."
    mkdir -p "$PT_DIR" "$EN_DIR" 2>/dev/null || true
    [ ! -e "$SOUNDS_DIR/pt-br" ] && ln -sfn "$PT_DIR" "$SOUNDS_DIR/pt-br" 2>/dev/null || true
    [ ! -e "$SOUNDS_DIR/br" ] && ln -sfn "$PT_DIR" "$SOUNDS_DIR/br" 2>/dev/null || true
    [ ! -e "$SOUNDS_DIR/pt" ] && ln -sfn "$PT_DIR" "$SOUNDS_DIR/pt" 2>/dev/null || true
    log_success "Links simbólicos de idiomas configurados."
}

# ------------------------------------------------------------------------------
# 1. CORREÇÃO CIRÚRGICA (PRESERVA EXISTENTES + CORRIGE FALTANTES)
# ------------------------------------------------------------------------------
executar_correcao_cirurgica() {
    create_backup_snapshot
    configurar_asterisk_conf
    garantir_links_simbolicos

    log_info "Executando correção cirúrgica e sincronização preservativa..."

    # 1. Backup da pasta 'en' caso ainda não exista
    if [ ! -d "$BACKUP_EN" ] && [ -d "$EN_DIR" ]; then
        log_info "Criando backup dos áudios originais em inglês em $BACKUP_EN..."
        /bin/cp -rf "$EN_DIR" "$BACKUP_EN" 2>/dev/null || true
    fi

    # 2. Resolução de áudios essenciais que faltam no pacote pt_BR
    # Arquivo crítico: you-dialed-wrong-number (reproduzido quando o tronco rejeita número)
    if [ ! -f "$PT_DIR/you-dialed-wrong-number.gsm" ] && [ ! -f "$PT_DIR/you-dialed-wrong-number.wav" ]; then
        log_warn "Áudio 'you-dialed-wrong-number' ausente em pt_BR. Mapeando versão em português..."
        if [ -f "$PT_DIR/ss-noservice.gsm" ]; then
            /bin/cp -f "$PT_DIR/ss-noservice.gsm" "$PT_DIR/you-dialed-wrong-number.gsm"
        elif [ -f "$PT_DIR/all-circuits-busy-now.gsm" ]; then
            /bin/cp -f "$PT_DIR/all-circuits-busy-now.gsm" "$PT_DIR/you-dialed-wrong-number.gsm"
        fi
    fi

    # 3. Sincroniza arquivos de pt_BR para en e raiz (sem deletar arquivos únicos em inglês)
    if [ -d "$PT_DIR" ]; then
        log_info "Sincronizando áudios em português para as pastas de busca do Asterisk..."
        /bin/cp -rf "$PT_DIR"/* "$EN_DIR/" 2>/dev/null || true
        /bin/cp -rf "$PT_DIR"/* "$SOUNDS_DIR/" 2>/dev/null || true
    fi

    chown -R asterisk:asterisk "$SOUNDS_DIR"
    chmod -R 755 "$SOUNDS_DIR"

    # Recarrega o Asterisk
    log_info "Recarregando configurações do Asterisk..."
    asterisk -rx "core reload" 2>/dev/null || true
    log_success "Correção cirúrgica finalizada! Gravações preservadas e fallbacks eliminados."
}

# ------------------------------------------------------------------------------
# 2. TRADUÇÃO E GERAÇÃO GERAL (TTS NEURAL EM PORTUGUÊS)
# ------------------------------------------------------------------------------
executar_geracao_tts() {
    create_backup_snapshot
    configurar_asterisk_conf
    garantir_links_simbolicos

    log_info "Iniciando gerador de áudios neurais em Português Brasileiro..."

    # Script Python inline para síntese e conversão para telefonia (WAV 8kHz + GSM)
    python3 - << 'PYEOF'
import os, sys, urllib.request, urllib.parse, subprocess

AUDIOS = {
    "you-dialed-wrong-number": "O número discado não existe ou está incorreto. Por favor, verifique o número e tente novamente.",
    "all-circuits-busy-now": "Todos os circuitos estão ocupados no momento. Por favor, tente sua chamada mais tarde.",
    "pls-try-call-later": "Por favor, tente sua chamada mais tarde.",
    "cannot-complete-as-dialed": "Sua chamada não pode ser completada como discada. Por favor, verifique o número e disque novamente.",
    "ss-noservice": "O número para o qual você ligou não está em serviço.",
    "number-not-in-service": "O número discado não está em serviço.",
    "an-error-has-occurred": "Ocorreu um erro ao processar a sua chamada.",
    "check-number-dial-again": "Por favor, verifique o número discado e tente novamente.",
    "the-number-u-dialed": "O número discado",
    "has-changed": "mudou.",
    "no-route-exists": "Não existe rota disponível para o número discado."
}

PT_DIR = "/var/lib/asterisk/sounds/pt_BR"
EN_DIR = "/var/lib/asterisk/sounds/en"
ROOT_DIR = "/var/lib/asterisk/sounds"

os.makedirs(PT_DIR, exist_ok=True)
os.makedirs(EN_DIR, exist_ok=True)

print(" -> Processando lista de áudios do sistema...")

for name, text in AUDIOS.items():
    pt_gsm = os.path.join(PT_DIR, f"{name}.gsm")
    pt_wav = os.path.join(PT_DIR, f"{name}.wav")
    
    # Se já existir gravação própria em pt_BR, preserva e apenas sincroniza
    if os.path.exists(pt_gsm) or os.path.exists(pt_wav):
        print(f"    [PRESERVADO] {name} já existe em português.")
        if os.path.exists(pt_gsm):
            subprocess.run(f"/bin/cp -f {pt_gsm} {EN_DIR}/{name}.gsm", shell=True)
            subprocess.run(f"/bin/cp -f {pt_gsm} {ROOT_DIR}/{name}.gsm", shell=True)
        if os.path.exists(pt_wav):
            subprocess.run(f"/bin/cp -f {pt_wav} {EN_DIR}/{name}.wav", shell=True)
            subprocess.run(f"/bin/cp -f {pt_wav} {ROOT_DIR}/{name}.wav", shell=True)
        continue

    print(f"    [SINTETIZANDO] {name}...")
    encoded = urllib.parse.quote(text)
    url = f"http://translate.google.com/translate_tts?ie=UTF-8&client=tw-ob&tl=pt-BR&q={encoded}"
    mp3_tmp = f"/tmp/{name}.mp3"
    wav_tmp = f"/tmp/{name}.wav"

    req = urllib.request.Request(url, headers={'User-Agent': 'Mozilla/5.0'})
    try:
        with urllib.request.urlopen(req) as resp, open(mp3_tmp, 'wb') as f:
            f.write(resp.read())
        
        # Converte para WAV 8kHz 16-bit Mono (padrão telefonia)
        cmd = f"ffmpeg -y -i {mp3_tmp} -ar 8000 -ac 1 -acodec pcm_s16le {wav_tmp} >/dev/null 2>&1 || sox {mp3_tmp} -r 8000 -c 1 -b 16 {wav_tmp} >/dev/null 2>&1"
        subprocess.run(cmd, shell=True)

        if os.path.exists(wav_tmp) and os.path.getsize(wav_tmp) > 0:
            for d in [PT_DIR, EN_DIR, ROOT_DIR]:
                subprocess.run(f"/bin/cp -f {wav_tmp} {d}/{name}.wav", shell=True)
                subprocess.run(f"asterisk -rx 'file convert {wav_tmp} {d}/{name}.gsm' >/dev/null 2>&1", shell=True)

        if os.path.exists(mp3_tmp): os.remove(mp3_tmp)
        if os.path.exists(wav_tmp): os.remove(wav_tmp)
    except Exception as e:
        print(f"    [ALERTA] Falha em {name}: {e}")

PYEOF

    chown -R asterisk:asterisk "$SOUNDS_DIR"
    chmod -R 755 "$SOUNDS_DIR"
    asterisk -rx "core reload" 2>/dev/null || true
    log_success "Geração de áudios em Português Brasileiro concluída com sucesso!"
}

# ------------------------------------------------------------------------------
# 3. RESTAURAR ÁUDIOS ORIGINAIS EM INGLÊS (EN_OLD)
# ------------------------------------------------------------------------------
executar_restauracao() {
    if [ ! -d "$BACKUP_EN" ]; then
        log_error "Nenhum backup em $BACKUP_EN foi encontrado para restaurar."
        return 1
    fi

    log_info "Restaurando áudios originais em inglês de $BACKUP_EN..."
    /bin/cp -rf "$BACKUP_EN"/* "$EN_DIR/" 2>/dev/null || true
    /bin/cp -rf "$BACKUP_EN"/* "$SOUNDS_DIR/" 2>/dev/null || true
    chown -R asterisk:asterisk "$SOUNDS_DIR"
    log_success "Áudios originais em inglês restaurados com sucesso."
}

# ------------------------------------------------------------------------------
# MODO INTERATIVO (MENU PRÓPRIO)
# ------------------------------------------------------------------------------
menu_audios() {
    while true; do
        clear
        echo -e "${BLUE}╔══════════════════════════════════════════════════════════════════════╗${NC}"
        echo -e "${BLUE}║${WHITE}     IPBX PRISMA TELECOM - GERENCIADOR DE ÁUDIOS E SONS PT-BR         ${BLUE}║${NC}"
        echo -e "${BLUE}╠══════════════════════════════════════════════════════════════════════╣${NC}"
        echo -e "${BLUE}║${NC}                                                                    ${BLUE}║${NC}"
        echo -e "${BLUE}║${NC}   ${WHITE}[1]${NC}  ${GREEN}Correção Cirúrgica (Preservativa)${NC}                          ${BLUE}║${NC}"
        echo -e "${BLUE}║${NC}        Preserva gravações existentes em pt_BR, corrige mensagens   ${BLUE}║${NC}"
        echo -e "${BLUE}║${NC}        faltantes (ex: you-dialed-wrong-number) e sincroniza.       ${BLUE}║${NC}"
        echo -e "${BLUE}║${NC}                                                                    ${BLUE}║${NC}"
        echo -e "${BLUE}║${NC}   ${WHITE}[2]${NC}  ${YELLOW}Geração e Tradução Geral (TTS Neural)${NC}                       ${BLUE}║${NC}"
        echo -e "${BLUE}║${NC}        Sintetiza todas as frases de erro e sistema com voz         ${BLUE}║${NC}"
        echo -e "${BLUE}║${NC}        brasileira nos formatos oficiais de telefonia (WAV/GSM).     ${BLUE}║${NC}"
        echo -e "${BLUE}║${NC}                                                                    ${BLUE}║${NC}"
        echo -e "${BLUE}║${NC}   ${WHITE}[3]${NC}  ${CYAN}Restaurar Áudios Originais em Inglês (en_old)${NC}              ${BLUE}║${NC}"
        echo -e "${BLUE}║${NC}                                                                    ${BLUE}║${NC}"
        echo -e "${BLUE}║${NC}   ${WHITE}[0]${NC}  ${RED}Voltar / Sair${NC}                                               ${BLUE}║${NC}"
        echo -e "${BLUE}╚══════════════════════════════════════════════════════════════════════╝${NC}"
        echo ""
        echo -ne "${CYAN}Escolha uma opção: ${NC}"
        read -r OPCAO_AUDIO

        case "$OPCAO_AUDIO" in
            1) executar_correcao_cirurgica; echo ""; read -p "Pressione ENTER para continuar..." ;;
            2) executar_geracao_tts; echo ""; read -p "Pressione ENTER para continuar..." ;;
            3) executar_restauracao; echo ""; read -p "Pressione ENTER para continuar..." ;;
            0) break ;;
            *) echo -e "${RED}Opção inválida!${NC}"; sleep 1 ;;
        esac
    done
}

# --- PROCESSAMENTO DE ARGUMENTOS ---
case "$1" in
    --cirurgico|--quick)
        executar_correcao_cirurgica
        ;;
    --tts-geral|--full)
        executar_geracao_tts
        ;;
    --restore)
        executar_restauracao
        ;;
    *)
        menu_audios
        ;;
esac
