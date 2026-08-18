Add-Type -AssemblyName System.Drawing

$srcHorizontal = "C:\Users\USER\.gemini\antigravity-ide\brain\1a56315b-d977-4888-85b0-81d76f77f397\.user_uploaded\media_1787059363109.png"
$srcIcon       = "C:\Users\USER\.gemini\antigravity-ide\brain\1a56315b-d977-4888-85b0-81d76f77f397\.user_uploaded\media_1787059363038.png"

# Function to remove white background and make it transparent
function Remove-WhiteBackground {
    param([string]$inputPath, [string]$outputPath, [bool]$makeWhiteText = $false)

    $img = [System.Drawing.Bitmap]::FromFile($inputPath)
    $bmp = new-object System.Drawing.Bitmap $img.Width, $img.Height, [System.Drawing.Imaging.PixelFormat]::Format32bppArgb

    for ($y = 0; $y -lt $img.Height; $y++) {
        for ($x = 0; $x -lt $img.Width; $x++) {
            $pixel = $img.GetPixel($x, $y)
            $cR = $pixel.R
            $cG = $pixel.G
            $cB = $pixel.B

            # Check if pixel is near white (threshold > 235)
            if ($cR -gt 230 -and $cG -gt 230 -and $cB -gt 230) {
                # Transparent
                $bmp.SetPixel($x, $y, [System.Drawing.Color]::FromArgb(0, 0, 0, 0))
            } else {
                if ($makeWhiteText) {
                    # Convert dark text to white for dark headers if requested, keeping triangle colors
                    # Triangle colors: Green (R 130-190, G 180-210, B 40-100), Blue (R 0-50, G 110-170, B 180-220), Teal (R 60-120, G 140-180, B 150-180)
                    if ($cR -lt 80 -and $cG -lt 80 -and $cB -lt 80) {
                        # Dark text -> White
                        $bmp.SetPixel($x, $y, [System.Drawing.Color]::FromArgb(255, 255, 255, 255))
                    } else {
                        $bmp.SetPixel($x, $y, $pixel)
                    }
                } else {
                    $bmp.SetPixel($x, $y, $pixel)
                }
            }
        }
    }

    $img.Dispose()
    $bmp.Save($outputPath, [System.Drawing.Imaging.ImageFormat]::Png)
    $bmp.Dispose()
}

Write-Host "Processando logos com fundo transparente..."

$themeImgDir = "c:\Users\USER\Documents\GitHub\ipbx-issabel6.0\src\themes\prisma_v5\images"
$adminImgDir = "c:\Users\USER\Documents\GitHub\ipbx-issabel6.0\src\admin\assets\images"
$srcDir      = "c:\Users\USER\Documents\GitHub\ipbx-issabel6.0\src"

# 1. Logo Principal com fundo transparente
Remove-WhiteBackground -inputPath $srcHorizontal -outputPath "$themeImgDir\logo_prisma.png" -makeWhiteText $false
Remove-WhiteBackground -inputPath $srcHorizontal -outputPath "$themeImgDir\logo_prisma_2.png" -makeWhiteText $true

# 2. Logo da Tela de Login (Alta resolucao transparent)
Remove-WhiteBackground -inputPath $srcHorizontal -outputPath "$themeImgDir\logo_prisma_login.png" -makeWhiteText $false

# 3. Ícone Mini da Sidebar e Favicon
Remove-WhiteBackground -inputPath $srcIcon -outputPath "$themeImgDir\issabel_logo_mini.png" -makeWhiteText $false
Remove-WhiteBackground -inputPath $srcIcon -outputPath "$themeImgDir\issabel_logo_mini2.png" -makeWhiteText $false

# 4. Copiar para o raiz de temas e admin
Copy-Item -Force "$themeImgDir\logo_prisma.png" "$srcDir\themes\tenant\images\logo_prisma.png" -ErrorAction SilentlyContinue

Write-Host "Logos processadas com sucesso!"
