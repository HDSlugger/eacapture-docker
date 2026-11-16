FROM php:8.4-cli-alpine

# curl is built into this image; sockets is needed for tuner discovery.
# linux-headers is only needed to compile the extension.
RUN apk add --no-cache curl tzdata ca-certificates \
 && apk add --no-cache --virtual .build-headers linux-headers \
 && docker-php-ext-install sockets \
 && apk del .build-headers

COPY eacapture.php /usr/local/bin/eacapture.php
RUN chmod +x /usr/local/bin/eacapture.php

COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

ENV TZ=UTC
VOLUME /data
ENTRYPOINT ["/entrypoint.sh"]
