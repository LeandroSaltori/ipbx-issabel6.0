# Instalação do Módulo CDR Asternic (Versão Gratuita) no Issabel

Este guia descreve o processo de instalação do módulo de relatórios de chamadas (CDR) da Asternic no servidor Issabel PBX.

## Pré-requisitos
- Servidor Issabel PBX instalado e em execução.
- Módulo CDR Asternic (versão gratuita para Issabel) baixado previamente do site oficial da Asternic.

## Passo a Passo da Instalação

### 1. Configurações de Segurança
Antes de instalar módulos externos, é necessário permitir o acesso direto a partir da interface do Issabel:
1. Acesse o menu **Segurança** (Security).
2. Vá para **Configurações Avançadas** (Advanced Settings).
3. Habilite a opção **Acesso direto** (Direct Access).
4. Clique em **Salvar**.

### 2. Acesso ao Administrador de Módulos
1. Navegue até o menu **Configuração PBX** (PBX Configuration).
2. Selecione **Issabel PBX** e vá para **Administração** (Administration).
3. Dentro de Administração, clique em **Administrador de Módulos** (Module Admin).

### 3. Upload do Módulo
1. No menu de Administração de Módulos, localize a seção de upload ou instalação de módulos externos.
2. Selecione o arquivo `.tar.gz` ou a pasta do módulo que você baixou.
3. Clique em **Enviar** (Submit) para carregar o módulo no servidor.

### 4. Instalação e Ativação
1. Após o upload bem-sucedido, ainda no **Administrador de Módulos** (Module Admin), procure pelo módulo **Asternic CDR Report** na lista.
2. Selecione a opção **Instalar** (Install).
3. Clique em **Processar** (Process) e **Confirmar** (Confirm) para concluir a instalação.
4. Clique em **Aplicar Alterações** (Apply Config) para garantir que as configurações sejam salvas.

### 5. Acesso ao Módulo
1. Após a instalação, o módulo estará disponível em **Configuração PBX** > **Opções Avançadas** > **Asternic CDR Report**.
2. A partir daí, você poderá visualizar estatísticas de chamadas, tempos de duração, detalhes por extensão e relatórios diários.

---
*Fonte: Tutorial baseado no vídeo [CDR ASTERNIC FREE](https://www.youtube.com/watch?v=6OVUhVTcm5I).*