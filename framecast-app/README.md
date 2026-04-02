# Framecast App

Phase 0 monorepo scaffold for Framecast.

## Structure

- `api/` Laravel 11 JSON API
- `web/` Vue 3 + Vite SPA
- `docker-compose.yml` local development stack

## Services

- `api` Laravel HTTP server
- `worker` queue worker
- `scheduler` Laravel scheduler
- `reverb` websocket server
- `horizon` Horizon dashboard process
- `web` Vite dev server
- `postgres` PostgreSQL 16
- `redis` Redis 7

## Start

1. Copy `api/.env.example` to `api/.env` if needed.
2. Run `docker compose up --build` from this directory.
3. Open `http://localhost:5173` for the SPA and `http://localhost:8000/api/v1/health` for the API.

## Notes

- This is the beginning of phase 0, not the completed auth flow.
- B2, JWT, auth sessions, and domain migrations are still to be implemented.
