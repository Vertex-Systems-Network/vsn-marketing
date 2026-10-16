# Troubleshooting registry

Known runtime problems, causes and fixes. Add every new issue here with a regression check where possible.

| Symptom | Cause | Fix |
|---|---|---|
| `pull access denied for minio/mc` | MinIO images no longer pullable from Docker Hub | Disable MinIO profile and use `FILESYSTEM_DISK=local` / `OBJECT_STORE_DISK=local` for local dev |
| Postgres container exits: "in 18+ ... /var/lib/postgresql/data (unused mount/volume)" | PostgreSQL 18 image expects mount at `/var/lib/postgresql` | Mount volume at `/var/lib/postgresql`; `docker compose down -v` once |
| `port is already allocated` on 5432/6379 | Local PostgreSQL/Redis (e.g. Laragon) uses the port | Remap host ports (e.g. 15432, 16379) |
| `bind ... 127.0.0.1:8000 ... forbidden by its access permissions` (Windows) | Port reserved/excluded by Windows or used | Use another host port (e.g. 18000) and set `APP_URL` |
| `Failed opening required ... vendor/...` or composer `copy(...tmp-*.zip)` fails | app, horizon and scheduler run `composer install` in parallel on one volume | Install once (`docker compose run --rm --no-deps app composer install`), then start all |
| White screen after login page loads | Vite advertises `http://0.0.0.0:5173` via `public/hot` | Remove `public/hot` and run `npm run build`, or set Vite HMR host to `localhost` |
| 404 on `/login` | Login route is `/auth/login` | Use `/auth/login` |
