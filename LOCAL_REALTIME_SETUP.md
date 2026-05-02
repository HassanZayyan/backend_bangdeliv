# Local Realtime Setup

Run the backend dev stack with:

```bash
composer run dev
```

The dev script starts Laravel HTTP, queue listener, logs, Vite, and Reverb on `0.0.0.0:8080`.

For Flutter, pass the local dart defines file:

```bash
flutter run --dart-define-from-file=dart_defines.local.json
```

Keep these values aligned:

- Backend `.env`: `BROADCAST_CONNECTION=reverb`, `REVERB_HOST=localhost`, `REVERB_PORT=8080`.
- Flutter `dart_defines.local.json`: `PUSHER_APP_KEY` must match backend `REVERB_APP_KEY`, `WS_PORT=8080`, and `API_BASE_URL` must point to the host address reachable by the emulator/device.
