Запуск
cd ..
make install    # build + up + composer + migrate
# или по шагам:
docker compose up -d
docker compose exec php composer install
make migrate
