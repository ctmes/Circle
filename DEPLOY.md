# Deploying Circle

Running this for real, with clients in it. Roughly two hours if the accounts
already exist, most of it waiting for DNS.

The order below is not arbitrary. Each step is either something a later step
needs, or something you want true *before* a client's documents are in the
system rather than after.

---

## What you need first

Four things Circle cannot supply, and one it will refuse to run without.

| | What | Why it blocks |
|---|---|---|
| 1 | **A domain** | Invitations contain links. A link to `localhost` is not an invitation. |
| 2 | **A host** | Any VPS with 4 GB and Docker. The stack is Postgres, Redis, MinIO, PHP, Node and Caddy; 2 GB will run out during a media job. |
| 3 | **An SMTP provider** | Without one, invitations and every other notification are written to a log file and nobody is told anything. Postmark, Resend, SES, Mailgun — they are equivalent for this. |
| 4 | **A verified sending domain** | Set up SPF, DKIM and DMARC with that provider. Skipping it means the invitation lands in spam, and the client concludes the software is broken. |
| 5 | **Your legal entity** | See *Before a client's documents arrive*, below. This is the one that is not a technical step and is the one most likely to be skipped. |

Optional but worth having on day one: an `ANTHROPIC_API_KEY`, or the Steward
fails loudly on every run.

---

## 1 · DNS

Two records, both pointing at the host:

```
A    circle.example.com          -> 203.0.113.10
A    files.circle.example.com    -> 203.0.113.10
```

The second is object storage. The browser uploads originals straight to it with
a short-lived signed URL, so it has to be reachable from the client's laptop —
the bytes never pass through the API, which is what keeps a 2 GB site video off
the application server entirely.

Let both resolve before step 3. Caddy asks Let's Encrypt for certificates on
first boot, and a name that does not resolve yet burns an attempt.

## 2 · The host

```bash
git clone <your repo> /srv/circle
cd /srv/circle
cp api/.env.production.example api/.env
```

Then fill in `api/.env`. Every value in it is either blank or a placeholder;
none of them have safe defaults. The ones that are easy to get wrong:

- **`APP_KEY`** — generate it once, on the server, after the containers exist:
  `docker compose -f docker-compose.prod.yml run --rm api php artisan key:generate`.
  Changing it later makes every encrypted value unreadable.
- **`AWS_ENDPOINT` vs `AWS_PUBLIC_ENDPOINT`** — the first is how the API reaches
  storage from inside the Docker network (`http://minio:9000`), the second is how
  the *browser* reaches it (`https://files.circle.example.com`). They differ and
  both are needed, because an S3 signature covers the host it was signed against.
  A single wrong value here produces uploads that appear to work and downloads
  that 403.
- **`CORS_ALLOWED_ORIGINS`** — your one origin. Never `*` on a reachable host.
- **`APP_DEBUG=false`** — a stack trace names your paths, your queries and
  sometimes your secrets.

And a `.env` for the compose file itself, next to `docker-compose.prod.yml`:

```bash
cat > .env <<'EOF'
CIRCLE_DOMAIN=circle.example.com
ACME_EMAIL=you@example.com
POSTGRES_PASSWORD=<long random string>
MINIO_ROOT_USER=<long random string>
MINIO_ROOT_PASSWORD=<long random string>
EOF
chmod 600 .env api/.env
```

Use real random values. These are reachable from inside the network and from
nowhere else, but "nowhere else" is a property of the compose file, not a law.

## 3 · Bring it up

```bash
docker compose -f docker-compose.prod.yml up -d --build
docker compose -f docker-compose.prod.yml run --rm api composer install --no-dev --optimize-autoloader
docker compose -f docker-compose.prod.yml run --rm api php artisan key:generate
docker compose -f docker-compose.prod.yml run --rm api php artisan migrate --force
docker compose -f docker-compose.prod.yml run --rm api php artisan config:cache
docker compose -f docker-compose.prod.yml run --rm api php artisan route:cache
```

Create the storage bucket once, matching `AWS_BUCKET`:

```bash
docker compose -f docker-compose.prod.yml exec minio sh -c '
  mc alias set local http://127.0.0.1:9000 "$MINIO_ROOT_USER" "$MINIO_ROOT_PASSWORD"
  mc mb --ignore-existing local/circle-evidence
  mc anonymous set none local/circle-evidence
'
```

That last line matters. Originals are private and every access is brokered
through a signed URL issued after a policy check; a public bucket bypasses the
entire access gate for anyone who learns a key.

**Do not seed.** `php artisan db:seed` loads the pilot fixtures — JWA Mats, a
rail access package, five demo accounts with a shared password. The only seeder
you want is the agent blueprint, which `migrate --seed` runs and which nothing
else depends on:

```bash
docker compose -f docker-compose.prod.yml run --rm api php artisan db:seed --class=AgentBlueprintSeeder --force
```

## 4 · Check it

```bash
curl -sS https://circle.example.com/up          # Laravel health check
docker compose -f docker-compose.prod.yml logs caddy --tail 30   # certificates issued?
docker compose -f docker-compose.prod.yml exec worker php artisan horizon:status  # supervisors up?
```

Then, in a browser: create your account, create your organisation, open a
Circle, upload one file, and watch it reach `ready`. That exercises the signed
upload, the media pipeline, the queue and object storage in one pass — if the
`AWS_PUBLIC_ENDPOINT` is wrong, this is where it shows.

Send yourself an invitation last. It proves the mail path, which is the part
with the most moving pieces outside your control.

---

## Before a client's documents arrive

Not optional, and not technical.

**Fill in `web/src/lib/site.ts`.** Thirteen placeholders: legal entity, ABN,
registered address, data region, the domain, six email aliases. Until they are
filled, `robots.txt` disallows the whole site and every legal page carries a
visible warning banner and `noindex` — two guards that exist precisely so this
cannot be skipped by accident. The Terms, the Privacy Policy and the Data
Processing Addendum are written against Australian law and are real documents;
they are also unenforceable when they name `[Legal entity name] Pty Ltd`.

If you are taking other companies' confidential documents, you need an entity
that can carry the obligation, and they will eventually ask who it is.

**Decide about the Steward before you turn it on.** With `ANTHROPIC_API_KEY`
set, extracted text from items explicitly marked agent-readable is sent to a
model provider. That is disclosed in your sub-processor list. Whether your
client's own contract with *their* client permits it is a question worth asking
once, now, rather than after.

**Turn on backups.** See below. A month of client work with no backup is the
kind of mistake that ends the relationship rather than the week.

---

## Backups

```bash
./scripts/backup.sh --verify     # take one and prove it restores
crontab -e
# 0 3 * * * cd /srv/circle && ./scripts/backup.sh >> /var/log/circle-backup.log 2>&1
```

It dumps Postgres and mirrors the evidence vault. Both, because either alone is
useless: the database holds the hash chain, object storage holds the bytes the
chain attests to, and a chain with no files is a set of digests with nothing to
check.

**This writes to the same machine.** That protects you from a bad migration and
from nothing else. Copy `./backups` off the box — to storage in another region,
or anywhere that is not this disk — or you have a second copy, not a backup.

Run `--verify` occasionally and read the output. It restores the dump into a
scratch database and counts the audit events that came back. An untested backup
is a belief.

---

## Updating

```bash
cd /srv/circle
git pull
docker compose -f docker-compose.prod.yml run --rm api php artisan migrate --force
docker compose -f docker-compose.prod.yml up -d --build
docker compose -f docker-compose.prod.yml run --rm api php artisan config:cache
docker compose -f docker-compose.prod.yml exec worker php artisan horizon:terminate
```

Take a backup first if the pull contains a migration. `config:cache` after every
`.env` change, without exception — a cached config does not read the file.

`horizon:terminate` last, because the workers booted before `config:cache` ran
and are holding the previous config in memory. It is a graceful stop — each
worker finishes the job in hand — and the container's `restart: unless-stopped`
brings Horizon straight back on the new config.

---

## What this deployment does not give you

Stated plainly, because each one is a real limit and finding them out later is
worse.

- **One host, no redundancy.** If the box dies, Circle is down until you rebuild
  it. Your backups are what make that an afternoon rather than a catastrophe.
- **No error monitoring.** Nothing tells you when a request 500s. Add Sentry or
  equivalent before you have clients depending on it; until then, the answer to
  "is it broken" is that somebody emails you.
- **No SSO, SCIM, or independent security attestation.** Any client with a real
  IT function will ask, and the honest answer is no. That is fine for your own
  clients and fatal in an enterprise procurement, which is the same thing the
  spec has said about itself from the start.
- **Object storage is on the same disk as everything else.** Fine to begin with.
  Move to managed storage — S3, R2, Spaces — once the contents matter: it is
  five `AWS_*` values and no code, and it makes durability another company's
  problem.
- **Uptime is whatever your host's is.** There is no status page and no alerting.
- **No Horizon dashboard in production.** It exists, but nothing has decided who
  may see it, so Horizon's default applies and the route is open to `local`
  only. Queue depth, failed jobs and throughput are all real operational
  information and none of it is worth exposing to whoever guesses the URL.
  Define a `viewHorizon` gate when you decide the answer; `horizon:status` and
  the container logs cover it until then.
