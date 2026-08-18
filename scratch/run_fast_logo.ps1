$csCode = Get-Content "c:\Users\USER\Documents\GitHub\ipbx-issabel6.0\scratch\FastLogoSafe.cs" -Raw
Add-Type -TypeDefinition $csCode -ReferencedAssemblies "System.Drawing.dll"

$srcHorizontal = "C:\Users\USER\.gemini\antigravity-ide\brain\1a56315b-d977-4888-85b0-81d76f77f397\.user_uploaded\media_1787059363109.png"
$srcIcon       = "C:\Users\USER\.gemini\antigravity-ide\brain\1a56315b-d977-4888-85b0-81d76f77f397\.user_uploaded\media_1787059363038.png"

$themeImgDir = "c:\Users\USER\Documents\GitHub\ipbx-issabel6.0\src\themes\prisma_v5\images"

Write-Host "Re-gerando logos com combinacoes corretas..."

# 1. Sidebar logo: texto branco para o menu lateral roxo escuro
[FastLogo]::Process($srcHorizontal, "$themeImgDir\logo_prisma.png", $true)
[FastLogo]::Process($srcHorizontal, "$themeImgDir\logo_prisma_2.png", $true)

# 2. Tela de Login: TEXTO PRETO ORIGINAL (pois o topo do login é branco)
[FastLogo]::Process($srcHorizontal, "$themeImgDir\logo_prisma_login.png", $false)

# 3. Logo do Rodape / CDR: texto preto limpo transparente
[FastLogo]::Process($srcHorizontal, "$themeImgDir\banner.png", $false)

# 4. Icone Mini da Sidebar
[FastLogo]::Process($srcIcon, "$themeImgDir\issabel_logo_mini.png", $false)
[FastLogo]::Process($srcIcon, "$themeImgDir\issabel_logo_mini2.png", $false)

# Copia para TODOS os modulos Asternic CDR e Admin FreePBX
$targetPaths = @(
    "c:\Users\USER\Documents\GitHub\ipbx-issabel6.0\src\modules\asternic_cdr\images\asternic_cdr_logo.jpg",
    "c:\Users\USER\Documents\GitHub\ipbx-issabel6.0\src\admin\modules\asternic_cdr\images\asternic_cdr_logo.jpg",
    "c:\Users\USER\Documents\GitHub\ipbx-issabel6.0\src\admin\images\issabelpbx_small.png",
    "c:\Users\USER\Documents\GitHub\ipbx-issabel6.0\src\admin\images\issabel_logo.png",
    "c:\Users\USER\Documents\GitHub\ipbx-issabel6.0\src\admin\modules\framework\amp_conf\var\www\html\admin\images\issabel_logo.png",
    "c:\Users\USER\Documents\GitHub\ipbx-issabel6.0\src\admin\modules\framework\amp_conf\var\www\html\admin\images\issabelpbx_small.png"
)

foreach ($path in $targetPaths) {
    $dir = Split-Path $path
    if (-not (Test-Path $dir)) { New-Item -ItemType Directory -Path $dir -Force | Out-Null }
    Copy-Item -Force "$themeImgDir\banner.png" $path -ErrorAction SilentlyContinue
}

Write-Host "LOGO DA TELA DE LOGIN RESTAURADA COM TEXTO PRETO E TODAS AS OUTRAS ATUALIZADAS!"
