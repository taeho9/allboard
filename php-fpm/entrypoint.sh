#!/bin/sh
set -e

echo "Copying html files to /data/allboard/..."
cp -r /bootstrap-html/* /data/allboard/

echo "Starting php-fpm..."
exec php-fpm