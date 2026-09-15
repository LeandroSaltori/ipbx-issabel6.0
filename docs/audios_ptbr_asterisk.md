# 🎧 Gerenciamento de Áudios e Idiomas no Asterisk / Issabel (PT-BR)

Este documento descreve a arquitetura interna de reprodução de áudio no Asterisk, o comportamento de *fallback* para o inglês, a diferença de tratamento entre os drivers **PJSIP** e **CHAN_SIP**, e o funcionamento das ferramentas de tradução cirúrgica e geral do **IPBX Prisma Telecom**.

---

## 1. Arquitetura de Sons no Asterisk

### 📂 Estrutura de Diretórios
Por padrão, os arquivos de áudio do Asterisk residem em `/var/lib/asterisk/sounds/`:
* `/var/lib/asterisk/sounds/` ➔ Pasta raiz de áudios (geralmente contém os áudios padrão em inglês).
* `/var/lib/asterisk/sounds/en/` ➔ Subpasta oficial de sons em inglês.
* `/var/lib/asterisk/sounds/pt_BR/` ➔ Subpasta de sons em Português do Brasil.
* `/var/lib/asterisk/sounds/custom/` ➔ Gravações personalizadas de URAs, filas e pesquisas.

### 🔍 Comportamento de Fallback Silencioso
Quando uma chamada executa uma aplicação como `Playback("you-dialed-wrong-number")` ou `Background()`:
1. O Asterisk lê o idioma atribuído ao canal ativo (`CHANNEL(language)`).
2. Se o idioma for `pt_BR`, ele busca por `/var/lib/asterisk/sounds/pt_BR/you-dialed-wrong-number.<ext>`.
3. **Regra de Fallback:** Se o arquivo **não existir** na pasta do idioma (ou estiver em formato/codec incompatível), o Asterisk **recorre silenciosamente ao arquivo em inglês** na pasta `en/` ou na raiz `/var/lib/asterisk/sounds/`.
4. Isso gera a falsa impressão de que a configuração de idioma falhou, quando na verdade o arquivo específico apenas não existia no pacote em português.

---

## 2. PJSIP vs CHAN_SIP: Por que o idioma da interface web falha em PJSIP?

No Issabel / FreePBX, a tela **PBX ➔ Configurações do Asterisk SIP** altera exclusivamente o arquivo:
```text
/etc/asterisk/sip_general_additional.conf
```
Esse arquivo configura apenas o driver legado **`chan_sip`**. 

Quando um ramal moderno **PJSIP** (`PJSIP/9001`) origina uma chamada de saída:
* Ele **ignora completamente** as diretivas do `chan_sip`.
* Se o arquivo principal `/etc/asterisk/asterisk.conf` não possuir a diretiva `defaultlanguage = pt_BR` na seção `[options]`, o canal PJSIP nasce com o idioma padrão de fábrica do Asterisk: **`en` (inglês)**.

### 🛠️ Correção Global Obrigatória (`asterisk.conf`)
No arquivo `/etc/asterisk/asterisk.conf`:
```ini
[options]
defaultlanguage = pt_BR
```
*(Requer reiniciar o Asterisk com `core restart now` para que o daemon recarregue o arquivo principal).*

---

## 3. Formatos e Codecs Exigidos pela Telefonia

Para que o Asterisk reproduza um arquivo `.wav` sem recorrer a fallback ou distorcer a voz, o formato de gravação deve ser estritamente:
* **Formato:** WAV (PCM linear s16le)
* **Taxa de Amostragem:** 8.000 Hz (8 kHz)
* **Profundidade de Bits:** 16-bit
* **Canais:** 1 canal (Mono)

Arquivos em formato **GSM** (`.gsm`) são compactados nativamente para telefonia e têm prioridade de carregamento rápido pelo Asterisk.

---

## 4. Como Usar o Utilitário de Áudios (`ipbx-sounds-ptbr.sh`)

O projeto disponibiliza um script automatizado em `scripts/ipbx-sounds-ptbr.sh`, acessível também pelo menu **`ipbx-update` (Opção [31])**:

### Opção 1: Correção Cirúrgica (Recomendada)
Preserva **100%** das gravações existentes em `pt_BR`:
* Padroniza `defaultlanguage = pt_BR` no `/etc/asterisk/asterisk.conf`.
* Cria links simbólicos de compatibilidade (`pt-br`, `br`, `pt` apontando para `pt_BR`).
* Identifica arquivos críticos que faltavam no pacote clássico (ex: `you-dialed-wrong-number`).
* Cria cópia de segurança em `en_old/` e sincroniza os áudios em português para a pasta `en/` e raiz, eliminando qualquer fallback indesejado em inglês.

Execução direta:
```bash
bash /root/ipbx-issabel6.0/scripts/ipbx-sounds-ptbr.sh --cirurgico
```

---

### Opção 2: Tradução e Geração Geral (TTS Neural)
Gera vozes neurais brasileiras ultra-realistas para todas as frases de erro e sistema:
* `you-dialed-wrong-number` (Número discado incorreto/não existe)
* `all-circuits-busy-now` (Circuitos ocupados no momento)
* `pls-try-call-later` (Tente sua chamada mais tarde)
* `cannot-complete-as-dialed` (Chamada não pode ser completada como discada)
* `ss-noservice` (Número não está em serviço)
* `an-error-has-occurred` (Ocorreu um erro ao processar a chamada)
* Converte automaticamente para WAV telefônico (8kHz 16-bit Mono) e GSM.

Execução direta:
```bash
bash /root/ipbx-issabel6.0/scripts/ipbx-sounds-ptbr.sh --tts-geral
```

---

### Opção 3: Restaurar Áudios Originais em Inglês
Restaura os arquivos originais em inglês a partir da pasta de backup `en_old/`:
```bash
bash /root/ipbx-issabel6.0/scripts/ipbx-sounds-ptbr.sh --restore
```
