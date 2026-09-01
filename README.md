# 🚀 AI-Powered Monitoring Service

A lightweight, self-hosted monitoring service for websites, servers, log files and Docker
containers. It runs your checks on a schedule, asks a local (OpenAI-compatible) LLM to
explain what went wrong, and delivers a short, human-readable verdict to Telegram.

Built with **Symfony 8.1**, **PHP 8.4+**, **Doctrine ORM (SQLite)** and **Docker**.

```
                 ┌─────────────┐
  cron (1 min)   │  Scheduler  │  reads config/monitors.yaml,
  ──────────────▶│             │  picks the checks whose interval elapsed
                 └──────┬──────┘
                        │
                 ┌──────▼──────┐
                 │  Checkers   │  http · ssl · ssh_log · docker · script
                 └──────┬──────┘
                        │ CheckOutcome (ok/failed, message, context)
                 ┌──────▼──────┐
                 │ State machine│ opens / closes an incident, applies the cooldown
                 └──────┬──────┘
                        │ only when an alert is actually going out
                 ┌──────▼──────┐      ┌──────────────┐
                 │ LLM analyzer│─────▶│   Telegram   │
                 └─────────────┘      └──────────────┘
                        │
                 ┌──────▼──────┐
                 │ SQLite + API│  /api/checks · /api/errors · /api/stats …
                 └─────────────┘
```

---

## Table of contents

1. [Features](#-features)
2. [Requirements](#-requirements)
3. [Quick start](#-quick-start-docker)
4. [Environment variables](#-environment-variables)
5. [Check types (`config/monitors.yaml`)](#-check-types)
   - [`script` — server report scripts](#script--server-report-scripts)
   - [`http` — websites and APIs](#http--websites-and-apis)
   - [`ssl` — certificate expiry](#ssl--certificate-expiry)
   - [`ssh_log` — remote log files](#ssh_log--remote-log-files)
   - [`docker` — remote containers](#docker--remote-containers)
6. [Walkthrough: monitoring a server with `monitor-*` scripts](#-walkthrough-monitoring-a-server-with-monitor--scripts)
7. [Telegram setup](#-telegram-setup)
8. [LLM setup](#-llm-setup)
9. [Alerting behaviour](#-alerting-behaviour)
10. [Console commands](#-console-commands)
11. [Scheduled jobs](#-scheduled-jobs)
12. [REST API](#-rest-api)
13. [Logging](#-logging)
14. [Running without Docker](#-running-without-docker)
15. [Testing](#-testing)
16. [Troubleshooting](#-troubleshooting)
17. [Security notes](#-security-notes)
18. [Extending: writing your own checker](#-extending-writing-your-own-checker)

---

## 🔥 Features

- **Five check types out of the box** — HTTP/HTTPS endpoints, SSL certificate expiry,
  remote log files over SSH, remote Docker containers, and arbitrary diagnostic scripts.
- **Modular architecture** — add a new check type by implementing one interface;
  it is discovered and wired automatically.
- **Local LLM diagnostics** — works with any OpenAI-compatible runtime (Ollama, LM Studio,
  LocalAI, OpenWebUI, vLLM). The model returns a structured verdict: summary, probable
  cause, severity (`LOW`/`MEDIUM`/`HIGH`/`CRITICAL`) and recommended actions, in the
  language you configure.
- **Fail-safe** — if the LLM is offline, slow, or answers with malformed JSON, the alert is
  still delivered; the analysis section simply says the analyzer was unavailable.
- **Smart anti-spam** — a newly detected outage is *always* announced immediately;
  reminders about an incident that is still open are throttled to one per
  `NOTIFICATION_COOLDOWN` minutes. Recovery is announced instantly.
- **Periodic server digest** — a short "how is the server doing right now?" summary posted
  to Telegram on a schedule, even when nothing is broken.
- **Daily summary** — uptime ratio, run counts and the list of checks that failed.
- **REST API** — JSON endpoints ready for a Vue/Nuxt/React dashboard.
- **Single container** — SQLite storage, built-in cron, no external services required.

---

## 📦 Requirements

| | |
|---|---|
| **Docker deployment** | Docker 20.10+ and Docker Compose v2 |
| **Bare-metal deployment** | PHP **8.4+** with `pdo_sqlite`, `ctype`, `iconv`; Composer 2; `openssh-client` |
| **Telegram** | A bot token and a chat ID (see [Telegram setup](#-telegram-setup)) |
| **LLM** *(optional)* | Any OpenAI-compatible `/v1/chat/completions` endpoint |

The LLM is optional: without it every alert is still delivered, just without the
diagnostic block.

---

## 🛠 Quick start (Docker)

### 1. Get the code

```bash
git clone https://github.com/YOUR_USERNAME/YOUR_REPOSITORY.git monitoring
cd monitoring
```

### 2. Create your `.env`

The repository ships a `.env` with placeholder values. Copy it and fill in your own —
**never commit real tokens**:

```bash
cp .env .env.local
```

`.env.local` is git-ignored and overrides `.env`. At minimum set:

```env
TELEGRAM_TOKEN=123456789:AAExampleExampleExampleExampleExample
TELEGRAM_CHAT_ID=987654321
```

### 3. Describe your checks

Edit `config/monitors.yaml` — see [Check types](#-check-types) for every available option.

### 4. Start it

```bash
docker compose up -d --build
docker compose logs -f
```

On boot the container:

1. generates an `APP_SECRET` (once) if none is set,
2. runs the database migrations, creating `var/data.db`,
3. installs its crontab and starts `crond`,
4. serves the REST API on port `8000`.

### 5. Verify

```bash
# Force one scheduling pass right now
docker compose exec app php bin/console app:monitor:run

# Collect the server report and print it instead of sending it
docker compose exec app php bin/console app:monitor:report --dry-run

# Ask for the current status via the API
curl -s http://localhost:8000/api/checks | jq
```

### `docker-compose.yml` reference

```yaml
services:
  app:
    build: .
    container_name: monitoring_app
    restart: unless-stopped
    ports:
      - "8000:8000"
    environment:
      APP_ENV: prod
    env_file:
      # .env is committed and holds placeholders only.
      # Put your real tokens in .env.local, which is git-ignored.
      - .env
      - path: .env.local
        required: false
    volumes:
      - ./var:/app/var                                                  # SQLite database & rotating logs
      - ./config/monitors.yaml:/app/config/monitors.yaml:ro             # Read-only checks config
      - ${SSH_PRIVATE_KEY_PATH:-/root/.ssh/id_rsa}:/root/.ssh/id_rsa:ro # Read-only SSH private key
    extra_hosts:
      - "host.docker.internal:host-gateway"                             # Reach an LLM bound to the host
```

> The optional `.env.local` entry needs Docker Compose 2.24+. On an older Compose, drop
> those two lines and put your real values straight into `.env` — but then keep `.env` out
> of your commits.

---

## ⚙️ Environment variables

| Variable | Default | Purpose |
|---|---|---|
| `APP_ENV` | `prod` in Docker | Symfony environment (`prod`, `dev`, `test`) |
| `APP_SECRET` | generated | Symfony secret. The entrypoint writes one into `.env.local` on first boot if it is empty |
| `TELEGRAM_TOKEN` | — | **Required.** Bot token from [@BotFather](https://t.me/BotFather) |
| `TELEGRAM_CHAT_ID` | — | **Required.** Target chat, group or channel ID |
| `LLM_ENDPOINT` | `http://host.docker.internal:11434/v1` | OpenAI-compatible base URL. `/chat/completions` is appended |
| `LLM_MODEL` | `llama3` | Model name passed to the runtime |
| `LLM_TIMEOUT` | `30` | Idle timeout in seconds. The whole exchange is capped at 3× this value |
| `LLM_LANGUAGE` | `Russian` | Language the model must answer in — e.g. `English`, `Russian`, `German` |
| `LLM_MAX_CONTEXT_CHARS` | `6000` | Log context is clamped to this size. The head (status banner) and the tail (freshest lines) are kept, the middle is dropped |
| `SSH_PRIVATE_KEY_PATH` | `/root/.ssh/id_rsa` | Private key used by the `ssh_log`, `docker` and remote `script` checks |
| `SSH_TIMEOUT` | `10` | SSH connect/run timeout in seconds; individual checks can override it |
| `DATABASE_URL` | `sqlite:///%kernel.project_dir%/var/data.db` | Doctrine DSN. Resolves to `/app/var/data.db` in the container |
| `NOTIFICATION_COOLDOWN` | `60` | Minutes between reminders about an *already open* incident. New outages ignore it |
| `SERVER_REPORT_SCHEDULE` | `0 * * * *` | Cron expression for the periodic digest, or `off` to disable. **Quote it** — a value containing spaces must be written as `"0 * * * *"` |
| `DEFAULT_URI` | `http://localhost` | Base URI used when generating URLs from the CLI |

---

## 📋 Check types

All checks live under the `checks:` key of `config/monitors.yaml`. The map key is the
**check key** — it identifies the check in alerts, in the database and in the API, so keep
it stable.

Every check accepts `interval` (seconds between runs, default `60`). The scheduler runs
once a minute, so an interval below 60 seconds effectively means "every minute".

---

### `script` — server report scripts

Runs one or more shell commands, locally or over SSH, and grades their output. The
**worst** status across all sections decides the outcome; the complete text is handed to
the LLM as context.

Diagnostic scripts come in two shapes, and both are supported:

| Shape | Example | How it is graded |
|---|---|---|
| Grades itself with a `STATUS: OK\|WARN\|CRIT` line | `monitor-logs` | The banner is read directly |
| Just prints data — `df`/`free` tables, raw log tails | `monitor-system`, `monitor-pm2-logs` | By [rules](#grading-output-that-has-no-status-line) you define |

A script that does neither is judged by its exit code alone: `0` → `OK`, anything else →
`ERROR`.

```yaml
checks:
  server_health:
    type: script
    host: 203.0.113.10     # omit to run on the machine hosting this service
    user: root
    port: 22               # optional
    interval: 3600         # once an hour
    timeout: 60            # per-command timeout in seconds
    fail_on: [WARN, CRIT]  # statuses that turn the check into an alert
    commands:

      # Shorthand: a script that grades itself needs nothing but its command
      LOGS: "sudo -u monitor sudo /usr/local/sbin/monitor-logs"

      # Long form: a script that only prints data is graded by rules
      SYSTEM:
        command: "sudo -u monitor sudo /usr/local/sbin/monitor-system"
        rules:
          - name: "Disk usage /"
            match: '(\d+)%\s+/$'
            unit: "%"
            warn_above: 80
            crit_above: 90

      # A raw log tail: keep the report short, but count over everything
      PM2_LOGS:
        command: "sudo -u monitor sudo /usr/local/sbin/monitor-pm2-logs"
        max_lines: 40
        rules:
          - name: "Fatal log lines"
            match: 'FATAL|ECONNREFUSED|UnhandledPromiseRejection'
            warn_above: 0
            crit_above: 10
```

The two forms can be mixed freely, as above. `config/monitors.yaml` in this repository
ships a complete example covering all six `monitor-*` scripts.

#### Check-level options

| Option | Default | Description |
|---|---|---|
| `host` | *(none)* | Remote host. **Omit it to run the commands locally** |
| `user` | `root` | SSH user (ignored for local execution) |
| `port` | `22` | SSH port |
| `timeout` | `SSH_TIMEOUT` | Per-command timeout in seconds |
| `fail_on` | `[WARN, WARNING, ERROR, CRIT, CRITICAL, FAIL]` | Statuses that make the check fail |
| `commands` | — | Map of `LABEL: "command"`, or a list; each entry may be a string or an object |
| `command` | — | Shorthand for a single command |
| `label` | derived | Label for the single-command form |

#### Per-command options

Used when a command is written as an object rather than a bare string:

| Option | Default | Description |
|---|---|---|
| `command` | — | **Required.** The command line to run |
| `rules` | `[]` | Rules that grade the output — see below |
| `max_lines` | *(unlimited)* | Keep only the last N lines of output in the report. **Rules still see the complete output** |
| `timeout` | check-level | Timeout override for this command |

#### Status handling

- A section's status comes from its `STATUS: X` line (case-insensitive; `STATUS = X`
  works too). If a script prints several, the worst one wins.
- Rules may only **escalate** a section, never talk it down.
- Recognised statuses, from healthiest to worst:
  `OK` → `INFO`/`NOTICE` → `UNKNOWN` → `WARN`/`WARNING` → `ERROR` → `CRIT`/`CRITICAL`/`FAIL`.
  Anything unrecognised is ranked at `ERROR`.
- A script with no `STATUS` line and no matching rule is judged by its exit code.
- A host that cannot be reached is recorded as `CRIT` for that section; the other sections
  are still collected.

#### Grading output that has no `STATUS` line

A rule always works the same way — **measure a number, then compare it**:

1. `match` is a regular expression applied to the output. It is written without
   delimiters (as in `grep -E`), anchors (`^`, `$`) bind to a line, and case is ignored
   unless you say otherwise.
2. The measured value is either **the first capture group** of the match, or **the number
   of matching lines** when the pattern has no capture group.
3. `warn_above` / `crit_above` / `warn_below` / `crit_below` decide the verdict.

| Option | Default | Description |
|---|---|---|
| `match` | — | **Required.** Regular expression, `grep -E` style, no delimiters |
| `name` | `rule #N` | Label shown in the alert and in the report |
| `value` | auto | `capture` or `count`. Defaults to `capture` when the pattern has a capture group |
| `warn_above` / `crit_above` | — | Fire when the value is **greater than** this |
| `warn_below` / `crit_below` | — | Fire when the value is **less than** this |
| `unit` | *(none)* | Suffix used when printing the value, e.g. `%` |
| `ignore_case` | `true` | Set to `false` for case-sensitive matching |
| `aggregate` | smart | `max`, `min`, `sum`, `avg` or `first` when several matches are found. Defaults to `min` for rules that only set a lower bound, `max` otherwise |
| `on_missing` | *(ignored)* | Status to report when a `capture` pattern matches nothing — e.g. `WARN`. Without it, a missing measurement is silently skipped |

**Recipes**

```yaml
rules:
  # 1. Threshold on a captured number
  #    "/dev/sda1  97G  25G  73G  26% /"  ->  26
  - name: "Disk usage /"
    match: '(\d+)%\s+/$'
    unit: "%"
    warn_above: 80
    crit_above: 90

  # 2. Fractional value
  #    "load average: 0.27, 0.34, 0.35"  ->  0.27
  - name: "Load average (1 min)"
    match: 'load average: ([0-9.]+)'
    warn_above: 4      # roughly 2x your core count
    crit_above: 8      # roughly 4x

  # 3. Count occurrences — no capture group, so matching lines are counted
  - name: "Fatal log lines"
    match: 'FATAL|ECONNREFUSED|out of memory'
    warn_above: 0      # a single occurrence is enough
    crit_above: 10

  # 4. Presence of a bad state
  - name: "Services down"
    match: 'NOT RUNNING|inactive|failed'
    warn_above: 0
    crit_above: 2

  # 5. Absence of a good state — "fewer than one match" means it is missing
  - name: "Nginx running"
    match: 'active \(running\)'
    crit_below: 1

  # 6. Lower bound across several matches (the smallest one is used)
  - name: "Free space"
    match: '(\d+)G\s+\d+%'
    unit: "G"
    warn_below: 10
```

**Writing good patterns**

- Anchor tightly. `(\d+)%\s+/$` matches only the root filesystem; without the `$` it
  would also match `/run`, `/dev/shm` and `/boot/efi`.
- Beware broad words. `error` matches the harmless `createError` in a Node stack trace,
  and `fatal` matches `fatal: true` inside a Nitro 404 payload. Prefer specific tokens
  like `ECONNREFUSED` or `UnhandledPromiseRejection`.
- Test before you trust it — `--dry-run` prints exactly what was measured:

  ```bash
  php bin/console app:monitor:report --key server_health --dry-run
  ```

  ```
  --- server_health (CRIT) ---
  === DETECTED ISSUES ===
  [SYSTEM] Disk usage /: 96% (above 90%)
  [SYSTEM] Load average (1 min): 5.9 (above 4)
  [PM2_LOGS] Fatal log lines: 2 matching line(s) (above 0)

  === SYSTEM ===
  …
  ```

Detected issues are placed at the top of the text sent to the LLM, so the model weighs
them first, and they are listed in the Telegram message as well.

#### Alternative `commands` shapes

```yaml
    # A plain list — labels are derived from the binary name
    commands:
      - "sudo -u monitor sudo /usr/local/sbin/monitor-logs"   # -> MONITOR-LOGS
      - "sudo -u monitor sudo /usr/local/sbin/monitor-nginx"  # -> MONITOR-NGINX

    # Explicit objects
    commands:
      - { name: LOGS,  command: "/usr/local/sbin/monitor-logs" }
      - { name: NGINX, command: "/usr/local/sbin/monitor-nginx" }
```

#### Single command

```yaml
  disk_space:
    type: script
    host: 203.0.113.10
    interval: 600
    command: "df -h /"
    rules:
      - name: "Disk usage /"
        match: '(\d+)%\s+/$'
        unit: "%"
        crit_above: 90
```

---

### `http` — websites and APIs

```yaml
  website:
    type: http
    url: "https://example.com"
    interval: 60
    expect_status: 200

  api_health:
    type: http
    url: "https://example.com/api/health"
    interval: 60
    expect_body_contains: "ok"
    timeout: 15
    max_redirects: 3
```

| Option | Default | Description |
|---|---|---|
| `url` | — | **Required.** Target URL |
| `expect_status` | `200` | Expected HTTP status code |
| `expect_body_contains` | *(none)* | Substring that must appear in the response body |
| `timeout` | `10` | Idle timeout in seconds; the whole request is capped at 3× this |
| `max_redirects` | `5` | Redirects to follow |

The check fails on a connection error, an unexpected status code, or a missing substring.
On failure the first 500 characters of the body are captured as LLM context.

---

### `ssl` — certificate expiry

```yaml
  example_cert:
    type: ssl
    host: example.com
    port: 443
    warning_days: 14
    interval: 86400   # once a day is plenty
```

| Option | Default | Description |
|---|---|---|
| `host` | — | Hostname. If omitted, it is taken from `url` |
| `url` | — | Alternative to `host`; the hostname is parsed out of it |
| `port` | `443` | TLS port |
| `warning_days` | `14` | Fail when fewer than this many days remain |
| `timeout` | `10` | Connection timeout in seconds |

SNI is sent, so virtual hosts return their own certificate. The check reports days
remaining, the exact expiry date and the issuer.

---

### `ssh_log` — remote log files

Tails a log file over SSH and greps it. **Any match is treated as a failure**, so write the
pattern to describe what you consider a problem.

```yaml
  nginx_errors:
    type: ssh_log
    user: root              # default user for every target
    grep: "error|crit"      # extended regex, case-insensitive
    lines: 200              # tail depth
    interval: 300
    targets:
      - host: 203.0.113.10
        file: "/var/log/nginx/error.log"
      - host: 203.0.113.11
        file: "/var/log/nginx/error.log"
        port: 2222          # optional per-target SSH port
        user: deploy        # optional per-target user override
```

The legacy single-host form is still supported:

```yaml
  syslog_errors:
    type: ssh_log
    host: 203.0.113.10
    user: root
    file: "/var/log/syslog"
    grep: "segfault|oom-killer"
    interval: 300
```

| Option | Level | Default | Description |
|---|---|---|---|
| `targets[].host` | target | — | **Required.** Hostname or IP |
| `targets[].file` | target | — | **Required.** Absolute path to the log file |
| `targets[].port` | target | `22` | SSH port |
| `targets[].user` | target | inherits | Per-target SSH user |
| `user` | check | `root` | Default SSH user |
| `grep` | check | *(none)* | Extended regex passed to `grep -E -i`. Omit it to just collect lines |
| `lines` | check | `200` | Number of tail lines to read |

Every matched line is prefixed with `[host]`, and the last 20 matches are kept as LLM
context. A log file that is missing or unreadable is reported as such — it is never
silently mistaken for "no errors found". If one host is unreachable while others are
healthy, the check fails but still reports what it managed to read.

---

### `docker` — remote containers

```yaml
  docker_host:
    type: docker
    host: 203.0.113.10
    user: root
    port: 22           # optional
    max_restarts: 3
    interval: 120
```

| Option | Default | Description |
|---|---|---|
| `host` | — | **Required.** Docker host reachable over SSH |
| `user` | `root` | SSH user |
| `port` | `22` | SSH port |
| `max_restarts` | `3` | Restart count above which a container is flagged |

A container is flagged when it is `unhealthy`, stuck `restarting`, has exceeded
`max_restarts`, or `exited` with a non-zero code. A missing Docker CLI and an unreachable
Docker daemon are reported distinctly, rather than looking like "no containers".

---

## 🧭 Walkthrough: monitoring a server with `monitor-*` scripts

This is the scenario the `script` check was designed for: a server exposing a set of
diagnostic scripts that each print a short report ending in a `STATUS:` verdict.

### 1. What the scripts look like

Any executable that prints text works. Some grade themselves, some don't — both are
handled. `/usr/local/sbin/monitor-logs` announces its own verdict:

```
=== LOG MONITOR ===
Last 1 hour: 21:14 -> 22:14
STATUS: WARN

--- NGINX ---
PHP warnings: 1037
Other errors: 0
TOP:
    928 WP_Post could not be converted to int
     60 Undefined array key "rating"

--- MYSQL ---
OK

--- AUTH ---
Failed auth attempts (last 1h): 0
=== END ===
```

`/usr/local/sbin/monitor-system` does not — it just prints tables:

```
=== UPTIME ===
 21:18:29 up 56 days,  7:14,  3 users,  load average: 0.27, 0.34, 0.35

=== MEMORY ===
               total        used        free      shared  buff/cache   available
Mem:           7.8Gi       2.6Gi       204Mi       195Mi       4.9Gi       4.6Gi

=== DISK ===
Filesystem      Size  Used Avail Use% Mounted on
/dev/sda1        97G   25G   73G  26% /
/dev/sda15      105M  6.1M   99M   6% /boot/efi
```

And `/usr/local/sbin/monitor-pm2-logs` dumps raw application logs, dozens of lines at a
time. The first kind is read from its banner; the other two are graded by
[rules](#grading-output-that-has-no-status-line).

### 2. Give the service SSH access

Create a dedicated key pair for monitoring — do not reuse a personal key:

```bash
ssh-keygen -t ed25519 -f ~/.ssh/monitoring_ed25519 -N "" -C "monitoring"
ssh-copy-id -i ~/.ssh/monitoring_ed25519.pub monitor@203.0.113.10
```

Point the service at it and mount it read-only:

```env
SSH_PRIVATE_KEY_PATH=/home/youruser/.ssh/monitoring_ed25519
```

Restrict what that key may do by pinning it to a single command in the server's
`~/.ssh/authorized_keys`:

```
command="/usr/local/sbin/monitor-run-all",restrict ssh-ed25519 AAAA... monitoring
```

### 3. Let the monitoring user run the scripts

If the scripts need elevated rights, grant exactly those and nothing more — in
`/etc/sudoers.d/monitoring`:

```
monitor ALL=(root) NOPASSWD: /usr/local/sbin/monitor-logs, \
                             /usr/local/sbin/monitor-pm2, \
                             /usr/local/sbin/monitor-pm2-logs, \
                             /usr/local/sbin/monitor-services, \
                             /usr/local/sbin/monitor-system, \
                             /usr/local/sbin/monitor-nginx
```

Verify by hand before wiring it up:

```bash
ssh -i ~/.ssh/monitoring_ed25519 monitor@203.0.113.10 \
    'sudo /usr/local/sbin/monitor-logs'
```

### 4. Declare the check

```yaml
checks:
  server_health:
    type: script
    host: 203.0.113.10
    user: monitor
    interval: 3600
    timeout: 60
    fail_on: [WARN, CRIT]
    commands:

      # Grades itself — nothing else needed
      LOGS: "sudo /usr/local/sbin/monitor-logs"

      # Prints df/free/uptime tables — graded by thresholds
      SYSTEM:
        command: "sudo /usr/local/sbin/monitor-system"
        rules:
          - name: "Disk usage /"
            match: '(\d+)%\s+/$'
            unit: "%"
            warn_above: 80
            crit_above: 90
          - name: "Load average (1 min)"
            match: 'load average: ([0-9.]+)'
            warn_above: 4
            crit_above: 8

      # Raw log dump — trimmed in the report, counted in full
      PM2_LOGS:
        command: "sudo /usr/local/sbin/monitor-pm2-logs"
        max_lines: 40
        rules:
          - name: "Fatal log lines"
            match: 'FATAL|ECONNREFUSED|UnhandledPromiseRejection'
            warn_above: 0
            crit_above: 10

      PM2:
        command: "sudo /usr/local/sbin/monitor-pm2"
        rules:
          - name: "Processes not online"
            match: '\b(errored|stopped|launching)\b'
            warn_above: 0

      SERVICES:
        command: "sudo /usr/local/sbin/monitor-services"
        rules:
          - name: "Services down"
            match: 'NOT RUNNING|inactive|failed'
            warn_above: 0
            crit_above: 2

      NGINX:
        command: "sudo /usr/local/sbin/monitor-nginx"
        rules:
          - name: "Nginx problems"
            match: 'NOT RUNNING|test failed|emerg'
            warn_above: 0
```

Start with the thresholds above, then tighten them once you have seen a few real reports.

### 5. Try it without sending anything

```bash
docker compose exec app php bin/console app:monitor:report --key server_health --dry-run
```

You should see each section's raw output and the computed overall status.

### 6. Send it for real

```bash
docker compose exec app php bin/console app:monitor:report --key server_health
```

Telegram receives a compact digest:

```
🔥 Server Report: server_health

Overall: CRIT
Time: 2026-09-01 22:14:03 UTC

Sections:
🔥 SYSTEM — CRIT
⚠️ LOGS — WARN
✅ SERVICES — OK
✅ NGINX — OK

Detected issues:
• [SYSTEM] Disk usage /: 96% (above 90%)
• [SYSTEM] Load average (1 min): 5.9 (above 4)

🧠 LLM Diagnostic Analysis:
Summary: 1037 PHP warnings in the last hour, 928 of them from a single WordPress bug.
Probable Cause: A plugin or theme casts a WP_Post object to int; the other services are healthy.
Assigned Severity: MEDIUM
Recommendations:
• Locate the plugin producing "WP_Post could not be converted to int"
• Silence the warning at the source rather than in php.ini
• Re-check after the next hourly report
```

From now on the digest is posted on the `SERVER_REPORT_SCHEDULE` cron, and
`app:monitor:run` additionally raises an immediate alert whenever the status crosses
into `fail_on` territory.

---

## 💬 Telegram setup

1. Open [@BotFather](https://t.me/BotFather), send `/newbot` and follow the prompts.
   You receive a token that looks like `123456789:AAE...`.
2. Send any message to your new bot (a bot cannot write to you first).
3. Fetch your chat ID:

   ```bash
   curl -s "https://api.telegram.org/bot<YOUR_TOKEN>/getUpdates" | jq '.result[].message.chat.id'
   ```

4. For a **group**, add the bot to the group and repeat step 3 — group IDs are negative
   (e.g. `-1001234567890`). For a **channel**, add the bot as an administrator.
5. Put both values in `.env.local`:

   ```env
   TELEGRAM_TOKEN=123456789:AAE...
   TELEGRAM_CHAT_ID=-1001234567890
   ```

Messages are sent with `parse_mode=HTML`, escaped, and truncated to Telegram's
4096-character limit without leaving broken markup behind. Delivery failures are logged
to `var/log/telegram.log` and never abort a monitoring run.

---

## 🧠 LLM setup

Any OpenAI-compatible `/v1/chat/completions` endpoint works.

### Ollama on the Docker host

```bash
curl -fsSL https://ollama.com/install.sh | sh
ollama pull llama3
# Make it reachable from containers
OLLAMA_HOST=0.0.0.0 ollama serve
```

```env
LLM_ENDPOINT=http://host.docker.internal:11434/v1
LLM_MODEL=llama3
```

### Other runtimes

| Runtime | Typical `LLM_ENDPOINT` |
|---|---|
| LM Studio | `http://host.docker.internal:1234/v1` |
| LocalAI | `http://host.docker.internal:8080/v1` |
| vLLM | `http://host.docker.internal:8000/v1` |
| OpenAI-compatible cloud API | the provider's `/v1` base URL |

### How the analyzer behaves

- The model is asked for a **single JSON object** with `summary`, `probable_cause`,
  `severity` and `recommendations`, written in `LLM_LANGUAGE`.
- Responses wrapped in markdown fences, prefixed with prose, or returning
  `recommendations` as one string are all normalised.
- An unknown severity falls back to `MEDIUM`.
- If the runtime rejects `response_format`, the request is retried once without it.
- Log context is clamped to `LLM_MAX_CONTEXT_CHARS`.
- **Any** failure — connection refused, timeout, unparseable answer — degrades to a
  fallback verdict, and the alert still goes out.

Every prompt and raw response is written to `var/log/llm.log` and stored in the
`llm_analyses` table.

---

## 🔔 Alerting behaviour

| Situation | What happens |
|---|---|
| Check fails and no incident is open | Incident opened, LLM analysis run, alert sent **immediately** |
| Check keeps failing, incident still open | Alert repeated only after `NOTIFICATION_COOLDOWN` minutes |
| Check fails again shortly after recovering | Treated as a **new** incident → alert sent immediately |
| Check succeeds while an incident is open | Incident resolved, recovery message sent with the downtime duration |
| Check succeeds and nothing was open | Nothing is sent |
| A checker throws an exception | Recorded as a failed run (`Checker crashed: …`) and alerted like any other failure |

The cooldown deliberately keys off the *open incident*, not the last notification, so a
flapping service cannot silence itself.

---

## 🖥 Console commands

Prefix with `docker compose exec app` when running inside the container.

### `app:monitor:run`

Runs every check whose interval has elapsed. This is what the per-minute cron calls.

```bash
php bin/console app:monitor:run
```

### `app:monitor:report`

Runs the `script` checks regardless of their schedule and posts a short LLM digest —
including when everything is healthy.

```bash
php bin/console app:monitor:report                      # every script check
php bin/console app:monitor:report --key server_health  # just one
php bin/console app:monitor:report --dry-run            # print, don't send
```

| Option | Description |
|---|---|
| `--key`, `-k` | Only run the check with this key (works for any check type) |
| `--dry-run` | Print the collected report to the console instead of calling the LLM and Telegram |

### `app:monitor:daily-summary`

Compiles the last 24 hours — total runs, success rate, and which checks failed — and posts
it to Telegram.

```bash
php bin/console app:monitor:daily-summary
```

---

## ⏰ Scheduled jobs

The container writes its crontab on every boot, so restarting never duplicates entries:

| Schedule | Job |
|---|---|
| `* * * * *` | `app:monitor:run` — run due checks |
| `${SERVER_REPORT_SCHEDULE}` (default hourly) | `app:monitor:report` — server digest |
| `59 23 * * *` | `app:monitor:daily-summary` |
| `0 3 * * 0` | Delete rotated log files older than 7 days |
| `5 3 * * 0` | Truncate `var/cron.log` |

Disable the digest with `SERVER_REPORT_SCHEDULE=off`, or change its cadence:

```env
SERVER_REPORT_SCHEDULE="0 */6 * * *"   # every six hours
```

Cron output lands in `var/cron.log`.

---

## 📡 REST API

All endpoints return JSON and are served on port `8000`.

| Endpoint | Returns |
|---|---|
| `GET /api/checks` | Every configured check with its latest status, latency and metadata. A check that has never run reports `"success": null` |
| `GET /api/checks/{key}` | Full configuration, the last 50 runs, open incidents and their LLM analyses |
| `GET /api/errors` | Open incidents plus the 50 most recently resolved ones |
| `GET /api/alerts` | The last 100 notifications that were sent |
| `GET /api/stats` | Last 24 hours: run and failure counts, success rate, average latency and incident count per check |

```bash
curl -s http://localhost:8000/api/checks | jq
curl -s http://localhost:8000/api/checks/server_health | jq '.active_errors'
curl -s http://localhost:8000/api/stats | jq
```

> The API is unauthenticated. Keep port `8000` on a private network, or put it behind a
> reverse proxy with authentication.

---

## 📝 Logging

Monolog writes one file per concern into `var/log/`, rotated daily with the last 7 days kept:

| File | Level | Contents |
|---|---|---|
| `application.log` | `warning`+ | Symfony application logs |
| `monitor.log` | `info`+ | Scheduler decisions and check outcomes |
| `llm.log` | `info`+ | Prompts sent to the model and its raw responses |
| `telegram.log` | `info`+ | Delivery attempts, outcomes and errors |
| `cron.log` | — | Raw stdout/stderr of the cron jobs |

```bash
docker compose exec app tail -f var/log/monitor.log
```

---

## 🧰 Running without Docker

```bash
composer install
cp .env .env.local        # then edit .env.local

php bin/console doctrine:migrations:migrate --no-interaction
php bin/console app:monitor:run

# Serve the API
php -S 0.0.0.0:8000 -t public
```

Add the schedule to your own crontab:

```cron
* * * * *  cd /path/to/monitoring && php bin/console app:monitor:run  >> var/cron.log 2>&1
0 * * * *  cd /path/to/monitoring && php bin/console app:monitor:report >> var/cron.log 2>&1
59 23 * * * cd /path/to/monitoring && php bin/console app:monitor:daily-summary >> var/cron.log 2>&1
```

Installing the service directly on the monitored machine lets you drop `host:` from your
`script` checks and run the diagnostics locally, with no SSH involved.

---

## 🧪 Testing

```bash
# Inside the container
docker compose run --rm app vendor/bin/phpunit

# Or against any PHP 8.4 runtime
vendor/bin/phpunit
```

The suite is self-contained: unit tests use mocked HTTP/SSH clients, and the kernel tests
build their schema in an in-memory SQLite database. No network access, no real Telegram
messages, no LLM calls.

---

## 🔧 Troubleshooting

**No Telegram messages arrive**
Check `var/log/telegram.log`. A `chat not found` means the bot has never been messaged
(or was not added to the group); `401 Unauthorized` means the token is wrong.

**Every SSH check fails with "SSH private key not found"**
`SSH_PRIVATE_KEY_PATH` points at a path *inside the container*. The compose file mounts
your key to `/root/.ssh/id_rsa`; keep the variable and the mount in sync.

**SSH checks fail with "Permission denied (publickey)"**
Test the exact command by hand:
`ssh -i <key> -o BatchMode=yes user@host 'echo ok'`. The service never falls back to a
password prompt — `BatchMode=yes` is always set.

**`ssh_log` reports "log file missing or not readable"**
The SSH user cannot read the file. Add it to the `adm` group, or adjust the path.

**The LLM block says the analyzer failed**
`var/log/llm.log` has the reason. Common causes: the runtime is bound to `127.0.0.1`
instead of `0.0.0.0`, `host.docker.internal` is not mapped (`extra_hosts`), or
`LLM_TIMEOUT` is too short for the model.

**A check never runs**
`interval` is measured from the last recorded run. Confirm the key appears in
`GET /api/checks`, then look for parse errors in `var/log/monitor.log` — a malformed
`monitors.yaml` is logged and skipped rather than crashing the run.

**Alerts are too noisy / too quiet**
Raise `NOTIFICATION_COOLDOWN` for fewer reminders. For `script` checks, narrow `fail_on`
to `[CRIT]` so warnings only show up in the periodic digest.

---

## 🔐 Security notes

- **Never commit real secrets.** `.env` holds placeholders; put real values in
  `.env.local`, which is git-ignored.
- **Use a dedicated SSH key** for monitoring, mounted read-only, ideally restricted with
  `command="…",restrict` in `authorized_keys`.
- **Grant the narrowest sudo rights** the diagnostic scripts need — list the exact
  binaries, never `ALL`.
- **The REST API has no authentication.** Do not expose port `8000` to the internet
  without a proxy in front of it.
- Prompts and raw model responses are stored in the database and in `var/log/llm.log`.
  If your logs contain sensitive data, lower `LLM_MAX_CONTEXT_CHARS` or narrow the `grep`
  patterns.
- `StrictHostKeyChecking=no` is used so a rebuilt host does not break monitoring. On an
  untrusted network, pre-populate `known_hosts` and remove that option in
  `src/Service/SshExecutor.php`.

---

## 🧩 Extending: writing your own checker

Drop a class into `src/Checker/` implementing `CheckerInterface`. It is tagged and
injected automatically — no configuration needed.

```php
<?php

namespace App\Checker;

class RedisChecker implements CheckerInterface
{
    public function supports(string $type): bool
    {
        return $type === 'redis';
    }

    public function check(array $config): CheckOutcome
    {
        $start = microtime(true);

        // ... perform the check ...

        return new CheckOutcome(
            success: true,
            message: 'OK',
            responseTime: round(microtime(true) - $start, 3),
            extra: ['output' => $rawReportForTheLlm]
        );
    }
}
```

The `extra` array feeds both the dashboard and the LLM. The analyzer picks its context
from the first key it finds, in this order: `output`, `matched_lines`, `problematic`,
`body_preview`.

To reuse the threshold engine in your own checker, inject `OutputRuleEvaluator` and call
`evaluate($rules, $text)`; it returns a status plus the list of findings.

---

## 📄 License

Proprietary by default — see `composer.json`. Replace this section with your own license
before publishing.
