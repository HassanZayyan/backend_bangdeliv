# Docker Deployment

Deployment source is `origin/main`. The VPS polls GitHub and deploys only when
`origin/main` changes.

## First Server Setup

```bash
sudo apt update
sudo apt install -y ca-certificates curl git
curl -fsSL https://get.docker.com | sudo sh
sudo usermod -aG docker ubuntu
```

Log out and log back in, then verify:

```bash
docker --version
docker compose version
```

Stop native Nginx if it is already using port 80:

```bash
sudo systemctl disable --now nginx || true
```

Clone the repo:

```bash
sudo mkdir -p /opt
sudo chown ubuntu:ubuntu /opt
cd /opt
git clone -b main https://github.com/HassanZayyan/backend_bangdeliv.git bangdeliv
cd /opt/bangdeliv
```

Create the production env:

```bash
cp .env.docker.example .env
nano .env
```

Fill all production secrets, especially database password, API keys, Reverb
credentials, and `BANGDELIV_ADMIN_*`.

If Firebase push is enabled, upload the Firebase service account JSON to the VPS
outside the Git repo, then generate the production-only Docker override:

```bash
scp storage/app/firebase-credentials-FCM.json ubuntu@43.129.55.16:/tmp/firebase-credentials-FCM.json
cd /opt/bangdeliv
chmod +x scripts/setup-production-runtime.sh scripts/verify-production-realtime.sh
./scripts/setup-production-runtime.sh
```

The script stores the credential at
`/opt/bangdeliv-secrets/firebase-credentials-FCM.json`, mounts it read-only to
`/run/secrets/firebase-credentials-FCM.json`, and updates `.env` so FCM and
Reverb use production-safe paths. Do not commit the JSON credential.

Run the first deploy:

```bash
chmod +x deploy.sh scripts/auto-deploy.sh
./deploy.sh
```

Open:

```text
http://43.129.55.16
```

## Enable Auto Deploy Polling

```bash
sudo cp deploy/systemd/bangdeliv-auto-deploy.service /etc/systemd/system/
sudo cp deploy/systemd/bangdeliv-auto-deploy.timer /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now bangdeliv-auto-deploy.timer
```

Check timer status:

```bash
systemctl list-timers | grep bangdeliv
journalctl -u bangdeliv-auto-deploy.service -n 80 --no-pager
```

## Manual Deploy

```bash
cd /opt/bangdeliv
./deploy.sh
```

## Verify Firebase and Reverb

Run the server-side checks:

```bash
cd /opt/bangdeliv
./scripts/verify-production-realtime.sh
```

Send one Firebase test notification to the latest active device token:

```bash
docker compose exec app php artisan tinker --execute='$token = App\Models\DeviceToken::where("is_active", true)->latest()->value("token"); dump($token ? "TOKEN_OK" : "NO_TOKEN"); if ($token) { app(Kreait\Firebase\Contract\Messaging::class)->send(Kreait\Firebase\Messaging\CloudMessage::new()->toToken($token)->withNotification(Kreait\Firebase\Messaging\Notification::create("Tes BangDeliv", "Tes notifikasi production"))->withData(["type" => "driver_order_available", "route" => "/driver/orders"])); dump("FCM_SENT"); }'
```

From Windows PowerShell, verify the public WebSocket port:

```powershell
Test-NetConnection 43.129.55.16 -Port 8080
```

`TcpTestSucceeded` must be `True`. If it is false while the VPS is listening on
8080, check the Tencent Cloud Lighthouse firewall rule for TCP 8080.

To confirm Flutter uses real WebSocket instead of fallback polling, temporarily
set `REALTIME_DIAGNOSTICS=true` in the production Dart defines before launching
the app. The Flutter debug console should show connection and subscription logs,
for example `connection established` and `subscription succeeded`.

## Useful Docker Commands

```bash
docker compose ps
docker compose logs -f app
docker compose logs -f nginx
docker compose logs -f queue
docker compose logs -f reverb
docker compose exec app php artisan migrate:status
./scripts/verify-production-realtime.sh
```
