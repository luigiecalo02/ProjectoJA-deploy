# Arranque backend ProjectJA (PowerShell)
$env:Path = "C:\php\php84;" + ($env:Path -replace 'C:\\xampp\\php;?', '')
Set-Location "$PSScriptRoot\ProjectJABack"
Write-Host "PHP:" (php -v | Select-Object -First 1)
php -d upload_max_filesize=100M -d post_max_size=110M artisan serve --host=127.0.0.1 --port=8000
