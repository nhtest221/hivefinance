# HiveFinance Backend

Laravel 12 / PHP 8.4 service for the HiveFinance modular monolith.

## M0 Scope

- Health-check API only.
- Transactional outbox foundation.
- Audit log foundation.
- Queue, scheduler, logging, environment, and CI wiring.
- No accounting features or business APIs.

## Local Runtime

The intended local runtime is Docker Compose from the repository root:

```bash
docker compose up --build
```

Then run backend commands inside the PHP container:

```bash
docker compose exec backend composer install
docker compose exec backend php artisan migrate
docker compose exec backend php artisan test
```
