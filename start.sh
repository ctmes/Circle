#!/usr/bin/env bash
# Brings the whole Circle stack up and leaves a working app at http://localhost:4321.
# Idempotent — safe to re-run against an already-running stack.
#
#   ./start.sh              start (or resume) everything
#   ./start.sh --fresh      destroy volumes first
#   ./start.sh --rebuild    force a rebuild of the PHP image
#   ./start.sh --demo       run demo.py (KEEP_OPEN=1) once healthy
#   ./start.sh --no-browser don't open a browser
set -euo pipefail
cd "$(dirname "$0")"

WEB_URL=http://localhost:4321
API_URL=http://localhost:8000/api
MINIO_URL=http://localhost:59001
DEMO_USER=gm@jwamats.test
DEMO_PASS=correct-horse-battery

FRESH= REBUILD= DEMO= NO_BROWSER=
for arg in "$@"; do
  case "$arg" in
    --fresh) FRESH=1 ;;
    --rebuild) REBUILD=1 ;;
    --demo) DEMO=1 ;;
    --no-browser) NO_BROWSER=1 ;;
    *) echo "Unknown option: $arg" >&2; exit 2 ;;
  esac
done

step() { printf '\n\033[36m== %s\033[0m\n' "$1"; }
info() { printf '\033[90m   %s\033[0m\n' "$1"; }
die()  { printf '\n\033[31mX  %s\033[0m\n' "$1" >&2; exit 1; }

# Any HTTP response counts as up — a 401 from the API proves it is listening.
wait_url() {
  local url=$1 timeout=$2 label=$3 elapsed=0
  while [ "$elapsed" -lt "$timeout" ]; do
    if curl -s -o /dev/null -m 5 "$url"; then return 0; fi
    sleep 3; elapsed=$((elapsed + 3))
  done
  die "$label did not come up within ${timeout}s. Try: docker compose logs"
}

step 'Checking Docker'
docker info >/dev/null 2>&1 || die 'Docker is not running. Start Docker Desktop and try again.'
info 'Docker is running.'

[ -f api/.env ] || { info 'api/.env missing — copying api/.env.example'; cp api/.env.example api/.env; }

if [ -n "$FRESH" ]; then
  step 'Tearing down existing stack and volumes'
  docker compose down -v --remove-orphans
fi

if [ -n "$REBUILD" ]; then
  step 'Rebuilding the PHP image'
  docker compose build --pull api
fi

if [ ! -f api/vendor/autoload.php ]; then
  step 'Installing PHP dependencies (first run — this takes a minute)'
  docker compose run --rm --no-deps api composer install --no-interaction --prefer-dist --no-progress
fi

if ! grep -qE '^APP_KEY=.+' api/.env; then
  step 'Generating application key'
  docker compose run --rm --no-deps api php artisan key:generate --force
fi

step 'Starting the stack'
docker compose up -d --remove-orphans
info 'postgres · redis · minio · api · worker · scheduler · web'

step 'Waiting for the API'
wait_url "$API_URL/circles" 180 'API'
info "$API_URL is answering."

step 'Applying migrations and seeding the agent blueprint'
docker compose exec -T api php artisan migrate --force --seed

# Organisation setup is an administrative act, not a self-service endpoint, so a
# usable sign-in has to be provisioned here. Same accounts demo.py uses.
step 'Provisioning the demo organisation and accounts'
docker compose exec -T api php artisan circle:provision-org "JWA Mats" \
  --user="gm@jwamats.test:Dana Okafor (GM):$DEMO_PASS" \
  --user="commercial@jwamats.test:Priya Raman (Commercial):$DEMO_PASS" \
  --user="technical@jwamats.test:Tom Alvarez (Technical):$DEMO_PASS" \
  --user="logistics@jwamats.test:Kim Novak (Logistics):$DEMO_PASS" \
  --user="external@northernrail.test:Sam Bright (Client):$DEMO_PASS" \
  --external=external@northernrail.test

step 'Waiting for the web server (npm install runs inside the container on first boot)'
wait_url "$WEB_URL" 420 'Web'
info "$WEB_URL is answering."

if [ -n "$DEMO" ]; then
  step 'Running the First Demo Script'
  if command -v python3 >/dev/null 2>&1; then KEEP_OPEN=1 python3 demo.py || true
  elif command -v python >/dev/null 2>&1; then KEEP_OPEN=1 python demo.py || true
  else info 'No python on PATH — skipping.'
  fi
fi

printf '\n\033[32m  Circle is up.\033[0m\n\n'
printf '  Web            %s\n' "$WEB_URL"
printf '  API            %s\n' "$API_URL"
printf '  MinIO console  %s   (circle / circlecircle)\n' "$MINIO_URL"
printf '\n  Sign in as     %s\n  Password       %s\n\n' "$DEMO_USER" "$DEMO_PASS"
printf '  Logs           docker compose logs -f\n  Stop           docker compose down\n\n'

if [ -z "$NO_BROWSER" ]; then
  if   command -v xdg-open >/dev/null 2>&1; then xdg-open "$WEB_URL" >/dev/null 2>&1 &
  elif command -v open     >/dev/null 2>&1; then open "$WEB_URL" >/dev/null 2>&1 &
  elif command -v start    >/dev/null 2>&1; then start "$WEB_URL" >/dev/null 2>&1 &
  fi
fi
