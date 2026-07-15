# Background processing: supervisord runs the queue worker AND the scheduler in one
# container, restarting either if it dies. Reuses the shared PHP runtime.
FROM sidechat/php:local

USER root
RUN apk add --no-cache supervisor
COPY supervisord.conf /etc/supervisor/supervisord.conf

# supervisord runs as root so it can drop each child process to the "app" user.
CMD ["supervisord", "-c", "/etc/supervisor/supervisord.conf"]
