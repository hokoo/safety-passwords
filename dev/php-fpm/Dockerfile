ARG PHP_VER

FROM wodby/wordpress-php:$PHP_VER

LABEL maintaner="Igor Tron <public@itron.pro>"

WORKDIR /srv/web/

USER root

RUN echo 'alias ll="ls -l --color"' >>  /etc/bash.bashrc
RUN echo 'alias ll="ls -l --color"' >>  /home/wodby/.bashrc

COPY ./php.ini /usr/local/etc/php/conf.d/zzz-php.ini
# change owner wodby:wodby /usr/local/etc/php/conf.d/zzz-php.ini
RUN chown wodby:wodby /usr/local/etc/php/conf.d/zzz-php.ini

RUN touch /var/log/php-error.log
RUN chown wodby:wodby /var/log/php-error.log

USER wodby
