.PHONY: up down build ssh test test-unit test-integration status fdb-status composer-install

up: ## Start all containers
	docker compose up -d --build

down: ## Stop all containers
	docker compose down

build: ## Rebuild PHP container
	docker compose build php

ssh: ## Shell into PHP container
	docker compose exec php bash

test: ## Run all tests
	docker compose exec php vendor/bin/phpunit

test-unit: ## Run unit tests only
	docker compose exec php vendor/bin/phpunit --testsuite=Unit

test-integration: ## Run integration tests only
	docker compose exec php vendor/bin/phpunit --testsuite=Integration

stan: ## Run PHPStan
	docker compose exec php vendor/bin/phpstan analyse

composer-install: ## Install composer dependencies
	docker compose exec php composer install

fdb-status: ## Show FDB cluster status
	docker compose exec fdb fdbcli --exec "status details"

fdb-cli: ## Open FDB CLI
	docker compose exec fdb fdbcli

verify: ## Verify PHP can connect to FDB via FFI
	docker compose exec php php docker/php/verify-ffi.php

help: ## Show this help
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | sort | awk 'BEGIN {FS = ":.*?## "}; {printf "\033[36m%-20s\033[0m %s\n", $$1, $$2}'

.DEFAULT_GOAL := help
