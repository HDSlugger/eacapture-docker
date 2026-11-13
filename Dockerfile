FROM php:8.4-cli-alpine

# curl is built into this image; sockets is needed for tuner discovery.
RUN docker-php-ext-install sockets \
 && apk add --no-cache curl tzdata ca-certificates

COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh

ENV TZ=UTC
VOLUME /data
ENTRYPOINT ["/entrypoint.sh"]
