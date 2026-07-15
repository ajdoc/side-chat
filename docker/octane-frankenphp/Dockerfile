# FrankenPHP worker mode via Laravel Octane. Opt-in — started only with the
# "octane" compose profile. The frankenphp binary + Octane code come from the
# base image / bind-mounted vendor; this layer only adds the dev file-watcher.
FROM sidechat/php:local

# Node + chokidar power Octane's --watch (auto-restart workers on file change).
USER root
RUN apk add --no-cache nodejs npm \
    && npm install -g chokidar \
    && npm cache clean --force
# Let Octane's file-watcher.js resolve the globally-installed chokidar.
ENV NODE_PATH=/usr/local/lib/node_modules
USER app

EXPOSE 8000

CMD ["php", "artisan", "octane:start", "--server=frankenphp", "--host=0.0.0.0", "--port=8000", "--watch"]
