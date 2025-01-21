docker.up:
	docker-compose -p safetypasswords up -d

docker.stop:
	docker-compose -p safetypasswords stop

docker.down:
	docker-compose -p safetypasswords down

docker.build.php:
	docker-compose -p safetypasswords up -d --build php

php.log:
	docker-compose -p safetypasswords exec php sh -c "tail -f /var/log/php-error.log"

clear.all:
	bash ./install/clear.sh

connect.php:
	docker-compose -p safetypasswords exec php bash

connect.nginx:
	docker-compose -p safetypasswords exec nginx sh

connect.php.root:
	docker-compose -p safetypasswords exec --user=root php bash
