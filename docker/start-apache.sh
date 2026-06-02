#!/usr/bin/env sh
set -eu

PORT="${PORT:-10000}"
DOCROOT="/var/www/public"

cat > /etc/apache2/ports.conf <<EOF
Listen ${PORT}
EOF

cat > /etc/apache2/sites-available/000-default.conf <<EOF
<VirtualHost *:${PORT}>
    ServerAdmin webmaster@localhost
    ServerName localhost
    DocumentRoot ${DOCROOT}

    <Directory ${DOCROOT}>
        AllowOverride All
        Require all granted
        Options FollowSymLinks
        DirectoryIndex index.php
        FallbackResource /index.php
    </Directory>

    SetEnvIf Authorization "(.*)" HTTP_AUTHORIZATION=\$1

    ErrorLog /proc/self/fd/2
    CustomLog /proc/self/fd/1 combined
</VirtualHost>
EOF

exec apache2-foreground
