$root = 'C:\xampp\htdocs\projectjapan\backend\srceen'
Get-ChildItem -Path $root -Filter '*.html' | ForEach-Object {
    $dest = [System.IO.Path]::ChangeExtension($_.FullName, '.php')
    if (-not (Test-Path $dest)) {
        Copy-Item -Path $_.FullName -Destination $dest
        Write-Host "Copied $($_.Name) -> $(Split-Path $dest -Leaf)"
    } else {
        Write-Host "Exists: $(Split-Path $dest -Leaf)"
    }
}
