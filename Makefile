COMPOSE ?= docker compose
EXEC    := $(COMPOSE) exec app
APP_URL ?= http://localhost:8000
SESSION ?= ses_03

.DEFAULT_GOAL := help
.PHONY: help start up install migrate import runs summary test stan check shell logs reset-mock down

help: ## List the targets
	@grep -E '^[a-z-]+:.*## ' $(MAKEFILE_LIST) | awk -F ':.*## ' '{printf "  \033[36m%-12s\033[0m %s\n", $$1, $$2}'

start: up install migrate ## Build, install and migrate: everything needed before a first import

up: ## Build and start the containers
	$(COMPOSE) up -d --build --wait

install: ## Install the Composer dependencies
	$(EXEC) composer install

migrate: ## Run the database migrations
	$(EXEC) bin/console doctrine:migrations:migrate -n

import: ## Import the registrations from the form API
	$(EXEC) bin/console app:inscriptions:import

runs: ## List the import runs
	$(EXEC) bin/console app:inscriptions:runs

summary: ## Show a session summary from the endpoint (SESSION=ses_03)
	@curl -s -H 'Authorization: Bearer dev-ops-token' $(APP_URL)/api/sessions/$(SESSION)/inscriptions

test: ## Run PHPUnit
	$(EXEC) vendor/bin/phpunit

stan: ## Run PHPStan
	$(EXEC) vendor/bin/phpstan analyse

check: test stan ## Run PHPUnit and PHPStan

shell: ## Open a shell in the app container
	$(EXEC) sh

logs: ## Follow the container logs
	$(COMPOSE) logs -f

reset-mock: ## Restart the form API mock so it serves v1 from scratch
	$(COMPOSE) restart form-api

down: ## Stop the containers
	$(COMPOSE) down
