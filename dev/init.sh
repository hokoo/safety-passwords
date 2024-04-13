#!/bin/bash

# create .env from example
echo "Create .env from example"
if [ ! -f ./.env ]; then
    echo "File .env doesn't exist. Recreating..."
    cp ./dev/templates/.env.template ./.env && echo "Ok."
else
    echo "File .env already exists."
fi

# import variables from .env file
. ./.env

# configure nginx.conf
echo "nginx.conf ..."
if [ ! -f ./dev/nginx/nginx.conf ]; then
  NGINXCONFIG=$(< ./dev/templates/nginx.conf.template)
  printf "$NGINXCONFIG" $WP_DOMAIN $WP_DOMAIN $WP_DOMAIN $WP_DOMAIN $WP_DOMAIN $WP_DOMAIN > ./dev/nginx/nginx.conf
fi
echo "Ok."

echo "Creating access.log error.log  ..."
touch dev/nginx/access.log
touch dev/php-fpm/error.log
echo "Ok."