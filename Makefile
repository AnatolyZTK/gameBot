.PHONY: help build up down install deploy deploy-prod shell migrate scrape worker logs

COMPOSE_FILE ?= docker-compose.yml
COMPOSE = COMPOSE_FILE=$(COMPOSE_FILE) docker compose
PHP = $(COMPOSE) exec php

help:
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-14s\033[0m %s\n", $$1, $$2}'

build: ## Собрать образы
	$(COMPOSE) build

up: ## Запустить все сервисы
	$(COMPOSE) up -d

down: ## Остановить сервисы
	$(COMPOSE) down

install: build ## Первичная установка (локально, без prod overlay)
	@$(MAKE) _install COMPOSE_FILE=docker-compose.yml

deploy: ## Развернуть на сервере (prod)
	chmod +x scripts/deploy.sh
	./scripts/deploy.sh

deploy-prod: ## То же, что deploy (prod overlay по умолчанию в deploy.sh)
	$(MAKE) deploy

_install: ## internal: инфра + init + приложение
	$(COMPOSE) up -d mysql redis minio manticore
	$(COMPOSE) up minio-init
	$(COMPOSE) run --rm init
	$(COMPOSE) up -d

shell: ## PHP-контейнер (bash)
	$(COMPOSE) exec php bash

migrate: ## Применить миграции БД
	$(COMPOSE) run --rm init

scrape: ## Поставить парсинг каталога в очередь
	$(PHP) php bin/console app:scrape:catalog

scrape-ea: ## Парсинг EA FUT (headed через xvfb)
	$(COMPOSE) exec -e PANTHER_NO_HEADLESS=1 php xvfb-run -a php bin/console app:scrape:ea

sync-prices: ## Синхронизация цен Futbin (избранные)
	$(PHP) php bin/console app:sync:prices --favorites-only

futbin-auth: ## Сохранить Futbin cookies для app:sync:prices
	$(COMPOSE) exec -e PANTHER_NO_HEADLESS=1 php xvfb-run -a php bin/console app:futbin:auth

fut-market-test: ## Проверка FUT transfer market (coins + search)
	$(PHP) php bin/console app:fut:market-test

account-add: ## Добавить EA-аккаунт в БД
	$(PHP) php bin/console app:account:add

account-list: ## Список EA-аккаунтов
	$(PHP) php bin/console app:account:list

account-seed: ## Заполнить 3 тестовых EA-аккаунта
	$(PHP) php bin/console app:account:seed

account-login: ## Логин всех аккаунтов (сохранить профили)
	$(COMPOSE) exec -e PANTHER_NO_HEADLESS=1 php xvfb-run -a php bin/console app:account:login --all

transfer-plan: ## План перевода (dry-run)
	$(PHP) php bin/console app:transfer:plan

transfer-run: ## Запустить перевод (очередь)
	$(PHP) php bin/console app:transfer:run

transfer-list: ## Список переводов
	$(PHP) php bin/console app:transfer:list

transfer-pair-test: ## Тест sell+snipe между аккаунтами
	$(COMPOSE) exec -e PANTHER_NO_HEADLESS=1 php xvfb-run -a php bin/console app:transfer:pair-test

worker: ## Запустить воркер очереди (foreground)
	$(COMPOSE) up worker

scheduler: ## Запустить планировщик
	$(COMPOSE) --profile scheduler up -d scheduler

logs: ## Логи всех сервисов
	$(COMPOSE) logs -f

admin: ## Открыть URL админки
	@echo "http://localhost:$${NGINX_HTTP_PORT:-8080}/admin"
