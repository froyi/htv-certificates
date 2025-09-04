#!/bin/bash
# Replace Apache port with Render's $PORT at runtime
sed -i "s/80/${PORT}/g" /etc/apache2/ports.conf /etc/apache2/sites-available/000-default.conf

exec apache2-foreground
