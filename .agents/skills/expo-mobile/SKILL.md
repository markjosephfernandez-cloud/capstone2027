---
name: expo-mobile
description: >-
  Use this skill when managing the Greyhound Expo React Native mobile application,
  starting or restarting the Metro bundler, diagnosing API connectivity between mobile and
  CodeIgniter 4, or testing mobile views.
---

# Expo React Native Mobile Skill

This skill provides procedures for developing and debugging the Greyhound mobile client (`greyhound-app-2025-main`).

## Directory
Mobile app root:
`/Applications/XAMPP/xamppfiles/htdocs/greyhound-admin-2025-final_greyhound_admin_2025/greyhound-app-2025-main`

## Commands

### 1. Launch Expo Dev Server
- **Standard Start (Port 8082 with clean cache)**:
  ```bash
  cd greyhound-app-2025-main && npx expo start --go --lan --clear --port 8082
  ```
- **Tunnel Mode (Remote Testing)**:
  ```bash
  cd greyhound-app-2025-main && npx expo start --go --tunnel --clear --port 8082
  ```

### 2. API Proxy
- **Start Local API Proxy**:
  ```bash
  cd greyhound-app-2025-main && node api/localApiProxy.js
  ```

### 3. Clear Metro Cache
If encountering module resolution or bundle caching errors:
```bash
cd greyhound-app-2025-main && npx expo start -c
```

### 4. Network and Backend Connectivity
The mobile app communicates with the CodeIgniter 4 backend:
- Backend URL: Configured in `.env` (`app.baseURL = 'http://172.16.152.45:8080/'`)
- Ensure both servers are on the same local subnet or connected via tunnel.
- Check CORS settings in `.env` (`greyhound.corsOrigins`).
