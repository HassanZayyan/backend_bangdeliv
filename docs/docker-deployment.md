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

If Firebase push is enabled, place the credential file at the same path used by
`FIREBASE_CREDENTIALS`, for example:

```bash
mkdir -p storage/app
nano storage/app/firebase-credentials-FCM.json
```

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

## Useful Docker Commands

```bash
docker compose ps
docker compose logs -f app
docker compose logs -f nginx
docker compose logs -f queue
docker compose logs -f reverb
docker compose exec app php artisan migrate:status
```

