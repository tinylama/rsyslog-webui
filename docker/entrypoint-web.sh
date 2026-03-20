#!/bin/sh
set -e
cd /var/www/html
# Ensure required composer dependencies exist in the (persisted) vendor volume.
# We key off a specific runtime class file so we don't get stuck with an
# autoload.php that was created by an earlier/broken composer run.
if [ ! -f vendor/autoload.php ] || [ ! -f vendor/spomky-labs/otphp/src/TOTP.php ]; then
  composer install --no-dev --no-interaction --optimize-autoloader
fi
# Some deployments may already have autoload.php but still miss specific
# dependencies in the persisted vendor volume.
if [ ! -f vendor/web-auth/webauthn-lib/src/PublicKeyCredentialRpEntity.php ]; then
  composer install --no-dev --no-interaction --optimize-autoloader
fi
# web-auth/webauthn-lib also depends on symfony/property-access via the serializer.
if [ ! -f vendor/symfony/property-access/PropertyAccessor.php ]; then
  composer install --no-dev --no-interaction --optimize-autoloader
fi
exec docker-php-entrypoint "$@"
