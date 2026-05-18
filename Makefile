.PHONY: help build up down install shell migrate scrape worker logs

COMPOSE = docker compose
PHP = $(COMPOSE) exec php

help:
	@grep -E '^[a-zA-Z_-]+:.*?## .*$$' $(MAKEFILE_LIST) | awk 'BEGIN {FS = ":.*?## "}; {printf "  \033[36m%-12s\033[0m %s\n", $$1, $$2}'

build: ## Собрать образы
	$(COMPOSE) build

up: ## Запустить все сервисы
	$(COMPOSE) up -d

down: ## Остановить сервисы
	$(COMPOSE) down

install: build up ## Первичная установка: composer + миграции
	$(COMPOSE) run --rm php composer install
	$(MAKE) migrate

shell: ## PHP-контейнер (bash)
	$(COMPOSE) exec php bash

migrate: ## Применить миграции БД
	$(PHP) php bin/console doctrine:migrations:migrate --no-interaction

scrape: ## Поставить парсинг каталога в очередь
	$(PHP) php bin/console app:scrape:catalog

worker: ## Запустить воркер очереди (foreground)
	$(COMPOSE) up worker

scheduler: ## Запустить планировщик
	$(COMPOSE) --profile scheduler up -d scheduler

logs: ## Логи всех сервисов
	$(COMPOSE) logs -f
