# Nuxt / Vue / shadcn dev server. Source is bind-mounted; deps live in a named volume.
FROM node:24-alpine

WORKDIR /app

EXPOSE 3000

# Install deps on first boot if the node_modules volume is empty, then start Nuxt
# bound to 0.0.0.0 so it's reachable from the host.
CMD ["sh", "-c", "[ -d node_modules/.bin ] || npm install; exec npm run dev -- --host 0.0.0.0 --port 3000"]
