# 🚀 AI-Powered Monitoring Service

A lightweight, extensible, production-ready monitoring service for websites, servers, and Docker containers, featuring automatic anomaly diagnostics using a local (OpenAI-compatible) LLM and instant alerts sent to Telegram.

Built with **Symfony 8.1+**, **PHP 8.5+**, **Doctrine ORM (SQLite)**, and **Docker**. Designed for self-hosted home server deployments.

---

## 🔥 Key Features

1. **Modular Architecture**: Add new checkers easily by implementing the `CheckerInterface`.
2. **Supported Checkers**:
   - **HTTP/HTTPS**: Verifies status codes, response latency, redirect counts, and body content matching (`expect_body_contains`).
   - **SSL Certificates**: Native SSL certificate expiration checking using PHP stream sockets (triggers warnings N days before expiration).
   - **SSH Log Checker**: Connects via SSH key to remote host logs, retrieves the last N lines, and parses them using grep/regex error patterns.
   - **Docker Checker**: Agentless remote container monitoring via SSH: tracks container states (running/exited), unhealthy statuses, and high restart counts.
3. **Local LLM Integration**:
   - Supports any OpenAI-compatible API runtime (Ollama, LocalAI, OpenWebUI, LM Studio).
   - Automatically analyzes error contexts and log lines with an LLM.
   - Returns a structured diagnostic summary: description, probable root cause, severity classification (LOW, MEDIUM, HIGH, CRITICAL), and recommended actions.
   - **Fail-safe Resilience**: If the LLM goes offline, standard alerts are still delivered to Telegram immediately.
4. **Anti-Spam Deduplication**: Configurable cooldown period. If a service goes down, an alert is sent instantly; repeated alerts are throttled to once every N minutes. On recovery, a recovery notification is sent immediately.
5. **Daily Summaries**: Compiles daily checking metrics (total runs, uptime percentage, and a list of failed hosts) and posts a report to Telegram.
6. **REST API Dashboard**: Ready-to-use JSON endpoints suitable for a Nuxt/Vue dashboard frontend.

---

## 🛠 Quick Start (Docker Ready)

To deploy the service on your production server, all you need is Docker and Docker Compose.

### 1. Prepare Configuration

Create a working directory on your server and set up the following configuration files:

#### File `.env`
Configure secrets and environment variables:
```env
TELEGRAM_TOKEN=your_bot_token
TELEGRAM_CHAT_ID=your_chat_id

# LLM Configuration (e.g. Ollama running on host)
LLM_ENDPOINT=http://host.docker.internal:11434/v1
LLM_MODEL=llama3
LLM_TIMEOUT=30 # Response generation timeout in seconds (useful for slow local LLMs)

# SSH Credentials for remote monitoring
SSH_PRIVATE_KEY_PATH=/root/.ssh/id_rsa
SSH_TIMEOUT=10

# Database URL for SQLite (mapped inside container)
DATABASE_URL=sqlite:////app/var/data.db

# Notification cooldown time in minutes
NOTIFICATION_COOLDOWN=60
```

#### File `config/monitors.yaml`
Describe the checks you want to run:
```yaml
checks:
  # HTTP website check
  my_site:
    type: http
    url: https://example.com
    interval: 60
    expect_status: 200

  # API check with response content validation
  my_api:
    type: http
    url: https://example.com/api/health
    interval: 60
    expect_body_contains: "ok"

  # Nginx log error checking on remote host
  nginx_errors:
    type: ssh_log
    host: 192.168.1.50
    user: root
    file: /var/log/nginx/error.log
    lines: 200
    grep: "error|crit"
    interval: 300

  # SSL certificate expiration check
  my_ssl_check:
    type: ssl
    host: example.com
    warning_days: 14
    interval: 86400 # run once a day

  # Remote Docker container health check
  docker_check:
    type: docker
    host: 192.168.1.50
    user: root
    max_restarts: 3
    interval: 120
```

### 2. Deployment

Create a `docker-compose.yml` file pointing directly to this GitHub repository:

```yaml
services:
  app:
    # Build directly from the GitHub repository and branch
    build: https://github.com/YOUR_USERNAME/YOUR_REPOSITORY.git#main
    container_name: monitoring_app
    ports:
      - "8000:8000"
    volumes:
      - ./var:/app/var                                                  # SQLite database & rotating logs
      - ./config/monitors.yaml:/app/config/monitors.yaml:ro            # Read-only checks config
      - ./.env:/app/.env                                                # Environment secrets
      - ${SSH_PRIVATE_KEY_PATH:-/root/.ssh/id_rsa}:/root/.ssh/id_rsa:ro # Read-only SSH private key
    extra_hosts:
      - "host.docker.internal:host-gateway"                             # Allows connecting to host-bound LLM
    restart: unless-stopped
```

Start the containerized service:
```bash
docker compose up -d
```

Upon boot, the container automatically:
1. Runs database migrations (`doctrine:migrations:migrate`) to create SQLite tables.
2. Initializes the background `crond` daemon to run due checks every minute.
3. Exposes the Dashboard REST API on port `8000`.

---

## 📡 REST API Endpoints

All endpoints return JSON responses:

* **`GET /api/checks`** — Returns all configured checks, their live health status, response latencies, and metadata.
* **`GET /api/checks/{key}`** — Detailed execution log (last 50 runs), active incidents, and linked LLM diagnostics for a check.
* **`GET /api/errors`** — Incident lifecycle log (active outages and last 50 resolved incidents).
* **`GET /api/alerts`** — Logs of the last 100 sent Telegram notifications.
* **`GET /api/stats`** — Compiled performance stats over the last 24 hours (uptime ratios, run/failure counts, average response times).

---

## 📝 Logging & Rotation

Logs are routed into dedicated channels using Monolog and rotate weekly (retaining last 7 log files) to prevent disk consumption:

* `var/log/application.log` — Standard Symfony application logs.
* `var/log/monitor.log` — Monitor scheduler logs and check run statuses.
* `var/log/llm.log` — Log of LLM prompt requests and raw model responses (and configuration parameters).
* `var/log/telegram.log` — Dedicated log channel for Telegram notification delivery requests, outcomes, payloads, and error traces.

---

## 🧪 Testing

To run the unit and integration test suite inside the container:
```bash
docker compose run --rm app vendor/bin/phpunit
```
Test cases run isolated in-memory SQLite instances, constructing database schemas dynamically via Doctrine `SchemaTool`.
