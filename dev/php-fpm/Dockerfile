ARG PHP_VER

FROM wodby/wordpress-php:$PHP_VER

LABEL maintaner="Igor Tron <public@itron.pro>"

WORKDIR /srv/web/

USER root

RUN echo 'alias ll="ls -l --color"' >>  /etc/bash.bashrc
RUN echo 'alias ll="ls -l --color"' >>  /home/wodby/.bashrc

USER wodby