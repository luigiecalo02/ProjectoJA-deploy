# projectJAFront

SPA Vue 3 + TypeScript + PrimeVue — Fase 1 (login y usuarios).

## Arranque

```powershell
cd projectJAFront
copy .env.example .env
# Completa VITE_FIREBASE_* desde la consola Firebase (projectja-2d55d)
npm install
npm run dev
```

App: http://localhost:5173  
API esperada: `VITE_API_URL=http://127.0.0.1:8000`

## Firebase

Proyecto: **ProjectJA** (`projectja-2d55d`).  
Las fotos se suben a Storage; en MySQL solo se guarda la URL.

Configura las claves en:

`projectJAFront/.env`

```env
VITE_FIREBASE_API_KEY=...
VITE_FIREBASE_AUTH_DOMAIN=projectja-2d55d.firebaseapp.com
VITE_FIREBASE_PROJECT_ID=projectja-2d55d
VITE_FIREBASE_STORAGE_BUCKET=projectja-2d55d.appspot.com
VITE_FIREBASE_MESSAGING_SENDER_ID=...
VITE_FIREBASE_APP_ID=...
```

Dónde sacarlas: [Firebase Console](https://console.firebase.google.com/) → proyecto `projectja-2d55d` → ⚙️ Project settings → Your apps → Web app → SDK setup and configuration.

Después de editar `.env`, reinicia `npm run dev`.

También activa **Storage** en Firebase y publica estas reglas (Build → Storage → Rules):

```
rules_version = '2';
service firebase.storage {
  match /b/{bucket}/o {
    match /users/{userId}/avatar/{fileName} {
      allow read: if true;
      allow write: if request.resource.size < 5 * 1024 * 1024
                   && request.resource.contentType.matches('image/.*');
    }
    match /{allPaths=**} {
      allow read, write: if false;
    }
  }
}
```

El archivo local equivalente está en `storage.rules`. Si el CLI está autenticado:

```bash
npx firebase login
npx firebase deploy --only storage
```

## Repo

https://github.com/luigiecalo02/projectJAFront-.git
