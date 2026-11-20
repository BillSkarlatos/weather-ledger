# Weather Ledger

Once a day, this backend fetches forecasts from **Open-Meteo** and **WeatherAPI.com**. It stores
hourly and daily temperature and precipitation in PostgreSQL as an append-only record, so you can
compare providers and see how each forecast changed as the target day approached.

## 1. Installation and setup

**Prerequisites:** Docker with Compose v2, and a free [WeatherAPI key](https://www.weatherapi.com/signup.aspx).
To run without Docker: PHP 8.3 (`pdo_pgsql`, `curl`, `mbstring`) and PostgreSQL 14+.

```bash
cp .env.example .env      # set the DB_*_PASSWORD values, WEATHERAPI_KEY and API_TOKENS (openssl rand -hex 32)
git config core.hooksPath .githooks   # once per clone: blocks commits containing secrets
docker compose up -d --build
curl -H "Authorization: Bearer <token>" "http://localhost:8081/api/v1/compare?location_id=1&variable=temperature_2m_min"
```

What starts:
- `db` runs PostgreSQL.
- `setup` creates the database roles, applies the migrations and adds the first location, then exits.
- `scheduler` fetches daily at `SCHEDULE_TIME`, catching up immediately if it starts later.
- `api` serves on port 8081.

```bash
docker compose exec scheduler php bin/console fetch [--date=YYYY-MM-DD] [--force]   # fetch now
docker compose run --rm setup php bin/console location:add "North field" 40.64 22.94 Europe/Athens
docker compose run --rm -e TEST_INTEGRATION=1 setup php tests/run.php              # tests
```

**Without Docker:** export the variables below, plus `DB_HOST`, `DB_USER` and `DB_PASSWORD`. Then
run `php bin/console setup`, `php bin/console scheduler`, and
`php -S 127.0.0.1:8081 -t public public/index.php`.

| Variable | Default | Purpose |
|---|---|---|
| `DB_NAME`, `DB_OWNER_USER` | `weather`, `weather_owner` | Database and schema owner |
| `DB_OWNER_PASSWORD`, `DB_INGEST_PASSWORD`, `DB_READER_PASSWORD` | **required** | Passwords for the owner, the append-only role and the read-only role |
| `WEATHERAPI_KEY` | **required** | WeatherAPI key |
| `WEATHERAPI_MAX_DAYS` | `3` | Days allowed by your WeatherAPI plan |
| `OPENMETEO_BASE_URL`, `OPENMETEO_API_KEY` | free endpoint, none | Open-Meteo commercial endpoint and key |
| `FORECAST_DAYS` | `3` | Days requested (capped per provider) |
| `SCHEDULE_TIME`, `SCHEDULE_TIMEZONE`, `RETRY_MINUTES` | `06:00`, `UTC`, `30` | Daily run time and retry interval |
| `DEFAULT_LOCATION_NAME`, `_LATITUDE`, `_LONGITUDE`, `_TIMEZONE` | demo | First location |
| `API_TOKENS` | **required** | Comma-separated bearer tokens |
| `API_PORT` | `8081` | Host port |

Any variable can instead be read from a file through `NAME_FILE`, which works with Docker secrets.

## 2. Implementation choices

**No framework and no third-party packages.** Plain PHP 8.3 using only extensions bundled with
the official image, on PostgreSQL 16: about 800 lines of code. Laravel, Symfony, Guzzle or PHPUnit
would each replace only a few dozen lines here. The cost would be thousands of vendored files,
supply-chain risk and upgrade work. The trade-off is a small hand-written autoloader, migrator,
router and test runner. If the scope grows, the domain code and SQL would move to Symfony or
Laravel unchanged.

**PostgreSQL**, for `jsonb`, partial unique indexes and triggers.

**Code layout** (`src/`): one class per concern.

| Class | Role |
|---|---|
| `App` | Configuration and wiring |
| `Cli`, `Api` | Entry points |
| `Scheduler` | Daily trigger |
| `Ingestor` | Fetch, validate and store |
| `Provider` → `OpenMeteo`, `WeatherApi` | Provider adapters |
| `Http` | HTTP client with retries |
| `Store` (writes), `Queries` (reads) | Database access |
| `Variable`, `Location`, `ForecastPoint` | Domain |

**Data model**
- Every fetch is a `fetch_runs` row, so nothing is overwritten. That keeps how each provider's
  forecast changed over time, which is the point of the product.
- Values live in `forecast_values` as one row per value: run, variable, `valid_at`, value, and
  `local_date`. New providers, locations or variables are new rows, never new columns.
- `valid_at` is UTC. Hourly sums cover the hour starting at `valid_at`. Daily values start at
  local midnight.
- The exact response is kept in `raw_payloads` for audit and reprocessing, with the API key
  removed from the stored URL.
- The database enforces trust. Triggers make values, payloads and finished runs immutable. A
  partial unique index allows only one in-flight run per provider, location and day.

**Provider differences** are handled inside each adapter:
- Open-Meteo gives local times with a single UTC offset per response. WeatherAPI gives UTC epochs.
- Open-Meteo labels hourly rain with the end of the hour, so it is shifted to the start.
- Open-Meteo's units are checked against its response; a mismatch rejects the payload.
- WeatherAPI is capped at the forecast days its plan allows.

**Failures**
- Network errors, 429 and 5xx are retried 3 times with back-off. Other 4xx errors fail at once.
- A malformed response fails its run but its payload is kept. Implausible values are dropped and
  counted.
- One provider failing never blocks the other.
- An incomplete day is retried every `RETRY_MINUTES`. Re-runs skip what is already stored.
- Runs left half-finished by a crash are released after 30 minutes.

**API.** `GET`, JSON, bearer token required (except `/health`).

| Route | Parameters (bold = required) |
|---|---|
| `/api/v1/providers`, `/variables`, `/locations` | – |
| `/api/v1/runs` | `location_id`, `provider`, `status`, `limit` |
| `/api/v1/forecasts` (latest run per provider) | **`location_id`, `variable`**, `provider`, `issued_on`, `from`, `to` |
| `/api/v1/compare` (providers side by side, with spread) | same as forecasts |
| `/api/v1/revisions` (every forecast for one target, with lead time) | **`location_id`, `variable`**, **`valid_at` or `date`**, `provider` |

**Security**
- Three database roles. `setup` uses the owner. The scheduler can only append. The API can only
  read. Only the scheduler receives provider keys.
- Keys are redacted from the database and logs. Outbound calls are HTTPS only.
- Tokens are compared in constant time. Input is validated and every query is a prepared statement.
- Containers run as non-root, and the database port is not exposed.

**Keeping secrets out of git**
- All secrets live only in `.env`. It is git-ignored (along with `.env.*`, `secrets/`, `*.pem` and
  `*.key`) and excluded from the Docker image. `.env.example` lists the names without values.
- The `.githooks/pre-commit` hook refuses a commit that stages a secrets file, or that contains any
  password, key or token value from your local `.env` (for example one pasted into code).
- If a secret was ever committed, treat it as leaked:
  1. Rotate it: get a new WeatherAPI key, new `API_TOKENS`, new database passwords.
  2. Run `git rm --cached .env`.
  3. Purge it from history (`git filter-repo --path .env --invert-paths`) and force-push.
- To check whether `.env` was ever committed: `git log --all --oneline -- .env` (no output means it wasn't).

## 3. Incomplete requirements

- **Not included:** MySQL support, PHPUnit and CI.
- **WeatherAPI hourly precipitation period:** WeatherAPI doesn't document it. Each value is
  treated as covering the hour starting at `time_epoch`. In a live run, the hourly values added up
  to WeatherAPI's daily totals within 0.02 mm (rounding), which supports this. It stays
  unconfirmed until a run with rain right around midnight, the only case where the two readings
  differ.

## 4. Additional notes

- **Licensing:** the free Open-Meteo API is non-commercial only (under 10,000 calls a day) and
  requires CC BY 4.0 attribution. The WeatherAPI Free plan allows commercial use (100,000 calls a
  month). This app makes 2 calls per location per day.
- **Comparison horizon:** 3 days with the WeatherAPI Free plan.
- **"Issued at"** is when the forecast was fetched, not the provider's model run time.
- **Daylight-saving days:** on DST-change days, Open-Meteo's local-day boundaries can be off by an
  hour, because it uses one offset per response.
