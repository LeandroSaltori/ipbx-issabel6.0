Add-Type -AssemblyName System.Drawing

$folder = "C:\Users\USER\.gemini\antigravity-ide\brain\1a56315b-d977-4888-85b0-81d76f77f397\.user_uploaded"
$files = Get-ChildItem "$folder\media_1787059363*"

foreach ($f in $files) {
    $bmp = [System.Drawing.Bitmap]::FromFile($f.FullName)
    Write-Host "$($f.Name) : $($bmp.Width) x $($bmp.Height)"
    $bmp.Dispose()
}
