FROM php:8.3-cli-alpine3.22
RUN apk add --no-cache nodejs python3 oniguruma libxml2 libcurl \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS curl-dev oniguruma-dev libxml2-dev \
    && docker-php-ext-install curl mbstring dom xml xmlwriter \
    && apk del .build-deps
ENV HOME=/scratch TMPDIR=/scratch
USER 65534:65534
