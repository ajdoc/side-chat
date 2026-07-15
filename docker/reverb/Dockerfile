# WebSocket server. Reuses the shared PHP runtime; only the command differs.
FROM sidechat/php:local

EXPOSE 8080

CMD ["php", "artisan", "reverb:start", "--host=0.0.0.0", "--port=8080"]
