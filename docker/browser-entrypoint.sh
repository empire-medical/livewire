#!/bin/sh
# Dusk looks for vendor/laravel/dusk/bin/chromedriver-linux; point it at the distro chromedriver.
[ -d vendor/laravel/dusk/bin ] && ln -sf /usr/bin/chromedriver vendor/laravel/dusk/bin/chromedriver-linux
exec "$@"
