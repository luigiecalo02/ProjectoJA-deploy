# ProjectoJA (workspace)

Carpeta de trabajo Cursor con **dos repositorios Git independientes**:

| Carpeta | Repo | Stack |
|---------|------|--------|
| `ProjectJABack/` | https://github.com/luigiecalo02/ProjectJABack.git | Laravel + PHP 8.4 |
| `projectJAFront/` | https://github.com/luigiecalo02/projectJAFront-.git | Vue 3 + Vite |

Esta raíz **no** es un monorepo Git.

## PHP 8.4 sin tocar PHP 8.2

PHP 8.4 vive en `C:\php\php84`.

**Importante:** si usas **Git Bash**, no uses `$env:Path` (eso es de PowerShell).

### Opción fácil (scripts)

Desde la carpeta `ProjectoJA`:

**Git Bash**
```bash
./start-back.sh      # terminal 1 → API
./start-front.sh     # terminal 2 → Front
```

**PowerShell**
```powershell
.\start-back.ps1     # terminal 1 → API
cd projectJAFront; npm run dev   # terminal 2 → Front
```

### Manual en Git Bash
```bash
export PATH="/c/php/php84:$PATH"
php -v
cd ProjectJABack
php artisan serve
```

### Manual en PowerShell
```powershell
$env:Path = "C:\php\php84;" + $env:Path
php -v
cd ProjectJABack
php artisan serve
```

## Levantar Fase 1

**Terminal 1 — API**

```powershell
$env:Path = "C:\php\php84;" + $env:Path
cd ProjectJABack
php artisan serve
```

**Terminal 2 — SPA**

```powershell
cd projectJAFront
npm run dev
```

- Front: http://localhost:5173  
- API: http://127.0.0.1:8000  
- BD MySQL: `ProjetJA`  
- Firebase Storage: proyecto `projectja-2d55d`
