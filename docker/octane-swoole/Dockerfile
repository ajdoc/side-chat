# Swoole worker mode via Laravel Octane. Opt-in — started only with the
# "octane" compose profile. Adds the swoole PHP extension on top of the base
# image, plus Node + chokidar for Octane's --watch.
FROM sidechat/php:local

USER root
RUN install-php-extensions swoole \
    && apk add --no-cache nodejs npm \
    && npm install -g chokidar \
    && npm cache clean --force
# Let Octane's file-watcher.js resolve the globally-installed chokidar.
ENV NODE_PATH=/usr/local/lib/node_modules
USER app

EXPOSE 8000

CMD ["php", "artisan", "octane:start", "--server=swoole", "--host=0.0.0.0", "--port=8000", "--watch"]
