# Greyhound client framework review — 2026-09-10

For the requested Android, iPhone, and web targets, retain **React Native + Expo** and improve the existing project. It already uses React 19.1, React Native 0.81.5, Expo SDK 54, React Navigation, and React Native Web. Vite is a web build tool, not a replacement for the native mobile runtime.

Expo supports sharing components between native and web. A separate React DOM + Vite app is an option if the website later needs substantially different browser-specific behavior and the team accepts maintaining a second UI. See [Expo web documentation](https://docs.expo.dev/workflow/web/) and [Vite's guide](https://vite.dev/guide/).

## Checks completed

| Check | Result |
|---|---|
| Parse 60 application JavaScript files and resolve local imports | Passed; no syntax errors or missing local imports |
| Existing `tests/media-functional.cjs` | 11 tests passed |
| Expo dependency compatibility check | Local/offline check passed; Expo explicitly warns that offline validation is limited |
| Production web export | Passed; output in `/private/tmp/greyhound-web-framework-review` |
| Android/iOS JavaScript export with Hermes bytecode | Blocked: the installed macOS Hermes compiler exits with SIGKILL, including outside the sandbox |
| Device runtime and signed-in browser interaction | Not verified; no connected browser or device test session |

The first build also exposed missing execute bits on the copied Expo CLI and Hermes compiler in `node_modules`. Those two local permissions were repaired. Hermes still terminates; the exact OS/toolchain cause is not established. Reinstalling dependencies on this Mac and repeating native build checks is the next step. Successful web output is not evidence that native release builds pass.

## Findings and recommended order

### 1. Make the project reproducible

- `package.json` has Windows-only `set ... && start ...` commands in `start:go` and `start:tunnel`. Replace them with portable scripts or a small Node launcher. Normal `start`, `web`, `android`, and `ios` scripts already use Expo.
- Remove the blanket dependency-validation override after dependency checks are reliable.
- The app directly imports `expo-constants`, but it is not declared as a direct dependency. Declare directly imported packages with versions compatible with the selected Expo SDK.
- Add standard `test`, `lint`, `typecheck`, and export/build commands. Existing functional tests currently require an explicit Node command.
- Replace the README: it describes the retired Express/MySQL server rather than the active Expo app, and includes stray encoding characters.
- Use Greyhound product identifiers instead of `app-ui` / `com.anonymous.appui` before release.

### 2. Separate the active client from old code

The active client calls CodeIgniter `/api/v1` through `api/server.js`. Root `server.js` and `server2.js` still contain old Express/MySQL servers aimed at `fm_system`; they should be archived separately from the client. This review did not remove them.

The import graph from `App.js` does not reach `AudioScreen`, `MediaPlayerScreen`, `PersonScreen`, the TMDB helper, and several older presentation components. Review these before removing their dependencies. `api/localApiProxy.js` is also outside the UI import graph, but it is explicitly invoked by package scripts and is not automatically unused.

The unused Audio screen calls protected `/files` without authorization and expects a bare array; the backend returns a `{status, data, meta}` envelope. It cannot simply be added back to navigation without repair.

The old MediaPlayer screen uses both `react-native-video` and `expo-av`, while active playback uses `expo-video`. Expo SDK 54 documents `expo-av` as deprecated in favor of `expo-video` and `expo-audio`: [official AV documentation](https://docs.expo.dev/versions/v54.0.0/sdk/av/). Retain one primary video implementation and remove unused player packages after checking imports.

### 3. Complete the frontend/backend feature contracts

- Saved and Watch Later use account-keyed AsyncStorage in `utils/libraryStorage.js`. They are device-local and do not synchronize through the backend.
- Playlists reads a local `playlists` collection, but no creation/editing flow was found in the reviewed client.
- Notifications' Mentions tab always supplies an empty list; it is a placeholder.
- `updateProfile()` reads `payload.error.message`, while the actual PHP API error envelope uses a top-level `message`. The existing test uses the old nested shape, so it does not catch this mismatch.
- Splash always routes to Login, even when a token was previously saved. Introduce a central authenticated-session state and validate a stored token before choosing the initial screen.

These are application features and contracts; changing the bundler does not implement them.

### 4. Improve maintainability gradually

Keep Expo and React Navigation while moving toward this structure:

```text
src/
  navigation/
  features/
    auth/
    feed/
    media/
    profile/
    library/
  components/
  services/api/
  storage/
  theme/
```

Introduce TypeScript first for API payloads, navigation parameters, and shared storage. Use one typed API client with consistent error handling, cancellation, and authentication. Break dense one-line screens into named components and hooks. Add behavior tests for login, search, playback, saved items, and API failures.

### 5. Finish platform-specific verification

For web, configure URL/deep-link behavior and check reload/back navigation, keyboard access, and media playback. For Android/iPhone, verify actual device playback, permissions, background handling, token storage, and release packaging after repairing the native toolchain. The current production web bundle is approximately 2.42 MB before transfer compression; measure loading before optimizing it.

## Suggested architecture

Keep the PHP administration application and CodeIgniter API as the backend. Keep one Expo client for Android, iPhone, and web, sharing API types and feature code. Consider a separate Vite website only if concrete web requirements justify its additional maintenance.

## Earlier approved legacy cleanup

The separate backend retirement task was completed during this review:

- `legacy/` moved to the protected, git-ignored local archive `writable/backups/legacy-source-20260910/`, preserving its contents and local edits.
- `activity`, `recent_files_view`, and `view_duplicates` removed by migration `2026-09-10-000001` after a verified SQL backup.
- New schema-only setup files placed in `database/bootstrap/`; fresh setup with all 29 migrations and recovery-backup restoration passed in a disposable database.
- The live API health check returned HTTP 200 with database ready. The database now contains 28 base tables and 29 applied migrations.

The 265 stale duplicate records and 89 stale media assignments identified in the earlier audit were not part of that removal and still need a separate reconciliation.

#f if there is a proble