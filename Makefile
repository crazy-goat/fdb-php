.PHONY: up down build ssh test test-unit test-integration lint lint-fix stan cs cs-fix rector rector-fix composer-install fdb-status fdb-cli verify help

up: ## Start all containers
	docker compose up -d --build

down: ## Stop all containers
	docker compose down

build: ## Rebuild PHP container
	docker compose build php

ssh: ## Shell into PHP container
	docker compose exec php bash

test: ## Run all tests
	docker compose exec php composer test

test-unit: ## Run unit tests only
	docker compose exec php composer test:unit

test-integration: ## Run integration tests only
	docker compose exec php composer test:integration

lint: ## Run all linters (phpcs + rector dry-run + phpstan)
	docker compose exec php composer lint

lint-fix: ## Fix lint issues (rector + phpcbf)
	docker compose exec php composer lint:fix

stan: ## Run PHPStan
	docker compose exec php composer phpstan

cs: ## Run PHP CodeSniffer
	docker compose exec php composer cs

cs-fix: ## Fix CodeSniffer issues
	docker compose exec php composer cs-fix

rector: ## Run Rector (dry-run)
	docker compose exec php composer rector

rector-fix: ## Run Rector (apply changes)
	docker compose exec php composer rector:fix

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
