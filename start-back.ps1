# Arranque backend ProjectJA (PowerShell)
$env:Path = "C:\php\php84;" + ($env:Path -replace 'C:\\xampp\\php;?', '')
Set-Location "$PSScriptRoot\ProjectJABack"
Write-Host "PHP:" (php -v | Select-Object -First 1)

# Cola: las notificaciones (push + campanita) se procesan aquí.
$queue = Start-Process -FilePath "php" -ArgumentList "artisan","queue:listen","--tries=3" -PassThru -NoNewWindow
Write-Host "Queue worker PID:" $queue.Id
try {
  php -d memory_limit=512M -d upload_max_filesize=100M -d post_max_size=110M artisan serve --host=127.0.0.1 --port=8000
} finally {
  if ($queue -and -not $queue.HasExited) {
    Stop-Process -Id $queue.Id -Force -ErrorAction SilentlyContinue
  }
}
