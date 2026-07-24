# Minimal PHP example

From this directory:

```bash
composer install
export ANALYTICS_INGEST_API_KEY="..."
export ANALYTICS_INGEST_URL="https://app.armature.tech/api/mcp-analytics/ingest"
php server.php
```

During local development inside the monorepo, replace the public dependency
with a Composer path repository pointing at `../..`.
