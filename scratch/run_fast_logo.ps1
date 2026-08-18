$csCode = Get-Content "c:\Users\USER\Documents\GitHub\ipbx-issabel6.0\scratch\FastLogoSafe.cs" -Raw
Add-Type -TypeDefinition $csCode -ReferencedAssemblies "System.Drawing.dll"

$srcHorizontal = "C:\Users\USER\.gemini\antigravity-ide\brain\1a56315b-d977-4888-85b0-81d76f77f397\.user_uploaded\media_1787059363109.png"
$srcIcon       = "C:\Users\USER\.gemini\antigravity-ide\brain\1a56315b-d977-4888-85b0-81d76f77f397\.user_uploaded\media_1787059363038.png"

$themeImgDir = "c:\Users\USER\Documents\GitHub\ipbx-issabel6.0\src\themes\prisma_v5\images"

Write-Host "Generando logos..."
# Sidebar logo: texto branco para o menu lateral roxo escuro
[FastLogo]::Process($srcHorizontal, "$themeImgDir\logo_prisma.png", $true)
[FastLogo]::Process($srcHorizontal, "$themeImgDir\logo_prisma_2.png", $true)
[FastLogo]::Process($srcHorizontal, "$themeImgDir\logo_prisma_login.png", $true)

# Logo do Rodape: texto escuro limpo transparente
[FastLogo]::Process($srcHorizontal, "$themeImgDir\banner.png", $false)

# Icone Mini da Sidebar
[FastLogo]::Process($srcIcon, "$themeImgDir\issabel_logo_mini.png", $false)
[FastLogo]::Process($srcIcon, "$themeImgDir\issabel_logo_mini2.png", $false)

# Copia para os modulos e raiz do tema
Copy-Item -Force "$themeImgDir\logo_prisma.png" "c:\Users\USER\Documents\GitHub\ipbx-issabel6.0\src\modules\asternic_cdr\images\asternic_cdr_logo.jpg" -ErrorAction SilentlyContinue

Write-Host "LOGOS ATUALIZADAS E PROCESSADAS COM SUCESSO!"
