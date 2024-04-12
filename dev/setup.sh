#!/bin/bash

# import variables from .env file
. ./.env

# Install plugin's composer
echo -e "${RCYAN}Install plugin's composer${COLOR_OFF}"
cd ./plugin-dir && composer install && cd ../

# prepare file structure
echo -e "${RCYAN}Create wp-config.php from example${COLOR_OFF}"
if [ ! -f ./wordpress/wp-config.php ]; then
    printf "File wp-config.php doesn't exist. Recreating... "

    WPCONFIG=$(< ./dev/templates/wp-config.php.template)
    printf "$WPCONFIG" $PROJECT_BASE_URL $PROJECT_BASE_URL > ./wordpress/wp-config.php

    echo -e "${RGREEN}Ok.${COLOR_OFF}"
else
    echo -e "${RPURPLE}File wp-config.php already exists.${COLOR_OFF}"
fi

# Create symlink for the plugin in the wordpress directory
echo -e "${RCYAN}Create symlink for the plugin in the wordpress directory${COLOR_OFF}"
# Check if file exists
if [ ! -L ./wordpress/wp-content/plugins/safety-passwords ]; then
  echo -e "${RRED}Creating...${COLOR_OFF}";
  ln -s /srv/web/plugin-dir/ ./wordpress/wp-content/plugins/safety-passwords
fi
echo -e "${ICYAN}Result: ${RYELLOW}$(ls -l ./wordpress/wp-content/plugins/ | grep safety-passwords)${COLOR_OFF}"

# install&configure WP
echo -e "${RCYAN}WP database init${COLOR_OFF}"
echo -e "${RCYAN}Export current DB if exists before being reset${COLOR_OFF}"
cd ./wordpress
wp db export 2>/dev/null
wp db reset --yes && \
echo -e "${RCYAN}WP is getting installed & configured${COLOR_OFF}"
wp core install --url=$PROJECT_BASE_URL --title="${WP_TITLE}" --admin_user=$WP_ADMIN_USER --admin_password=$WP_ADMIN_PASS --admin_email=$WP_ADMIN_EMAIL --skip-email
wp plugin delete akismet hello && \
wp plugin activate --all

# Check the exit code of the last command's batch.
if [ $? -ne 0 ]; then
    echo -e "${RRED}Project installation failed :\ Wasted${COLOR_OFF}"
    exit 1
fi

# Some additional configurations.
wp option update show_on_front page && \
wp option update page_on_front 2 && \
wp post update 2 --post_title='Home Page' --post_name=frontpage && \
wp rewrite structure '/%postname%/'

echo -e "${RCYAN}The project is ready now.${COLOR_OFF}" && \
echo -e "${ICYAN}WordPress credentials:${COLOR_OFF}" && \
printf "WP User Admin: ${RYELLOW}%s \n${COLOR_OFF}WP User Pass:  ${RYELLOW}%s${COLOR_OFF}\n" $WP_ADMIN_USER $WP_ADMIN_PASS && \
echo -e "${RGREEN}Done.${COLOR_OFF}"
