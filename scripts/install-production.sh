#!/bin/sh
set -eu
cd "$(dirname "$0")/.."
test -f .env.production || { echo 'Generate .env.production with setup-production.py first.' >&2; exit 1; }
dc() { docker compose --env-file .env.production -f compose.production.yaml "$@"; }
dc config --quiet
dc config --format json | python3 -c 'import json,sys; c=json.load(sys.stdin); assert c["name"] not in ["smh-learning-dev", "smh-learning-test"]; e=c["services"]["app"]["environment"]; assert e["APP_ENV"] == "production" and e["APP_DEBUG"] == "false"; assert e["DB_DATABASE"] not in ["smh_learning_dev", "smh_learning_test"] and e["DB_USERNAME"] != "root"; assert e["APP_URL"].startswith("https://") and e["APP_KEY"] and e["DB_PASSWORD"]'
dc build app nginx
dc up -d --wait mariadb redis
# Never use migrate:fresh or reset an existing password.
dc run --rm --no-deps app php artisan migrate --force
dc run --rm --no-deps app php artisan db:seed --force
dc up -d --wait
python3 scripts/verify-production-http.py
echo 'Services started. Configure host HTTPS, sign in as admins and change the initial password.'
echo 'Then run ops:release-check and ops:check --queue --tls as described in the installation guide.'
