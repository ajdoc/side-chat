.DEFAULT_GOAL := help
COMPOSE := docker compose

.PHONY: help build up down restart logs ps migrate fresh shell tinker composer artisan npm octane-build octane-up octane-down octane-logs

help: ## List available targets
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | \
		awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-12s\033[0m %s\n", $$1, $$2}'

build: ## Build all images (app first, so reverb/worker can build FROM it)
	$(COMPOSE) build app
	$(COMPOSE) build

up: build ## Build, start the whole stack, and run migrations
	$(COMPOSE) up -d
	@echo "Waiting for the database to be ready..."
	@until $(COMPOSE) exec -T postgres pg_isready -U $${POSTGRES_USER:-sidechat} >/dev/null 2>&1; do sleep 1; done
	$(COMPOSE) exec -T app php artisan migrate --force
	@echo ""
	@echo "  API      -> http://localhost:$${APP_PORT:-8000}/api/ping"
	@echo "  Frontend -> http://localhost:$${FRONTEND_PORT:-3000}  (first boot installs npm deps, give it a minute)"
	@echo "  Reverb   -> ws://localhost:$${REVERB_PORT:-8080}"

down: ## Stop and remove containers (keeps volumes/data)
	$(COMPOSE) down

restart: ## Restart all services
	$(COMPOSE) restart

logs: ## Tail logs for all services
	$(COMPOSE) logs -f --tail=100

ps: ## Show service status
	$(COMPOSE) ps

migrate: ## Run database migrations
	$(COMPOSE) exec -T app php artisan migrate --force

fresh: ## Drop everything and re-migrate (DESTROYS data)
	$(COMPOSE) exec -T app php artisan migrate:fresh --force

shell: ## Open a shell in the app container
	$(COMPOSE) exec app sh

tinker: ## Open Laravel Tinker
	$(COMPOSE) exec app php artisan tinker

composer: ## Run composer, e.g. `make composer c="require foo/bar"`
	$(COMPOSE) run --rm --entrypoint composer app $(c)

artisan: ## Run artisan, e.g. `make artisan c="make:model Message -m"`
	$(COMPOSE) exec -T app php artisan $(c)

npm: ## Run npm in the frontend, e.g. `make npm c="run build"`
	$(COMPOSE) exec frontend npm $(c)

octane-build: ## Build the opt-in Octane images (FrankenPHP worker + Swoole)
	$(COMPOSE) build app
	$(COMPOSE) --profile octane build

octane-up: octane-build ## Start the Octane worker-mode variants (frankenphp :8001, swoole :8002)
	$(COMPOSE) --profile octane up -d app-octane-frankenphp app-octane-swoole
	@echo ""
	@echo "  FrankenPHP worker -> http://localhost:$${OCTANE_FRANKENPHP_PORT:-8001}/api/ping"
	@echo "  Swoole            -> http://localhost:$${OCTANE_SWOOLE_PORT:-8002}/api/ping"
	@echo "  Classic (php-fpm-like) still on http://localhost:$${APP_PORT:-8000}"

octane-down: ## Stop and remove the Octane variants (classic app is untouched)
	$(COMPOSE) --profile octane rm -sf app-octane-frankenphp app-octane-swoole

octane-logs: ## Tail logs for the Octane variants
	$(COMPOSE) --profile octane logs -f --tail=100 app-octane-frankenphp app-octane-swoole
