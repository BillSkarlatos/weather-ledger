-- Append-only forecast ledger: every fetch is a run, every value belongs to one run.

CREATE TABLE providers (
    id   smallint PRIMARY KEY,
    code text NOT NULL UNIQUE,
    name text NOT NULL
);
INSERT INTO providers VALUES (1, 'open_meteo', 'Open-Meteo'), (2, 'weatherapi', 'WeatherAPI.com');

-- Sums and extremes cover [valid_at, valid_at + 1 h) when hourly, the local day when daily.
CREATE TABLE variables (
    id          smallint PRIMARY KEY,
    code        text NOT NULL UNIQUE,
    resolution  text NOT NULL CHECK (resolution IN ('hourly', 'daily')),
    unit        text NOT NULL,
    aggregation text NOT NULL CHECK (aggregation IN ('instant', 'sum', 'min', 'max', 'mean'))
);
INSERT INTO variables VALUES
    (1, 'temperature_2m',      'hourly', '°C', 'instant'),
    (2, 'precipitation',       'hourly', 'mm', 'sum'),
    (3, 'temperature_2m_max',  'daily',  '°C', 'max'),
    (4, 'temperature_2m_min',  'daily',  '°C', 'min'),
    (5, 'temperature_2m_mean', 'daily',  '°C', 'mean'),
    (6, 'precipitation_sum',   'daily',  'mm', 'sum');

CREATE TABLE locations (
    id        integer GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    name      text NOT NULL,
    latitude  numeric(7, 4) NOT NULL CHECK (latitude BETWEEN -90 AND 90),
    longitude numeric(7, 4) NOT NULL CHECK (longitude BETWEEN -180 AND 180),
    timezone  text NOT NULL,
    active    boolean NOT NULL DEFAULT true,
    UNIQUE (latitude, longitude)
);

CREATE TABLE fetch_runs (
    id              bigint GENERATED ALWAYS AS IDENTITY PRIMARY KEY,
    provider_id     smallint NOT NULL REFERENCES providers,
    location_id     integer NOT NULL REFERENCES locations,
    run_date        date NOT NULL,
    status          text NOT NULL DEFAULT 'running' CHECK (status IN ('running', 'succeeded', 'failed')),
    started_at      timestamptz NOT NULL DEFAULT now(),
    finished_at     timestamptz,
    values_stored   integer,
    values_rejected integer,
    error           text
);
-- Lock: one in-flight run per provider, location and day.
CREATE UNIQUE INDEX fetch_runs_single_active ON fetch_runs (provider_id, location_id, run_date) WHERE status = 'running';
CREATE INDEX fetch_runs_latest ON fetch_runs (location_id, provider_id, run_date DESC) WHERE status = 'succeeded';

CREATE TABLE raw_payloads (
    fetch_run_id bigint PRIMARY KEY REFERENCES fetch_runs,
    request_url  text NOT NULL,
    body         jsonb NOT NULL,
    sha256       char(64) NOT NULL
);

CREATE TABLE forecast_values (
    fetch_run_id bigint NOT NULL REFERENCES fetch_runs,
    variable_id  smallint NOT NULL REFERENCES variables,
    valid_at     timestamptz NOT NULL,
    local_date   date NOT NULL,
    value        numeric(7, 2) NOT NULL,
    PRIMARY KEY (fetch_run_id, variable_id, valid_at)
);
CREATE INDEX forecast_values_target ON forecast_values (variable_id, valid_at);

-- Stored values and payloads never change; a finished run is sealed.
CREATE FUNCTION reject_change() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    IF TG_TABLE_NAME = 'fetch_runs' AND OLD.status = 'running' THEN
        RETURN NEW;
    END IF;
    RAISE EXCEPTION '% row is immutable', TG_TABLE_NAME;
END $$;
CREATE TRIGGER immutable BEFORE UPDATE OR DELETE ON forecast_values FOR EACH ROW EXECUTE FUNCTION reject_change();
CREATE TRIGGER immutable BEFORE UPDATE OR DELETE ON raw_payloads FOR EACH ROW EXECUTE FUNCTION reject_change();
CREATE TRIGGER immutable BEFORE UPDATE OR DELETE ON fetch_runs FOR EACH ROW EXECUTE FUNCTION reject_change();

CREATE FUNCTION utc_iso(timestamptz) RETURNS text LANGUAGE sql IMMUTABLE AS $$
    SELECT to_char($1 AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"')
$$;

-- Least privilege for the roles created by `bin/console setup`.
DO $$
BEGIN
    IF EXISTS (SELECT FROM pg_roles WHERE rolname = 'weather_ingest') THEN
        GRANT SELECT ON providers, variables, locations, fetch_runs TO weather_ingest;
        GRANT INSERT ON fetch_runs, raw_payloads, forecast_values TO weather_ingest;
        GRANT UPDATE (status, finished_at, values_stored, values_rejected, error) ON fetch_runs TO weather_ingest;
    END IF;
    IF EXISTS (SELECT FROM pg_roles WHERE rolname = 'weather_reader') THEN
        GRANT SELECT ON providers, variables, locations, fetch_runs, raw_payloads, forecast_values TO weather_reader;
    END IF;
END $$;
