#!/bin/sh
# nginx entrypoint hook: write the portal basic-auth file from the environment.
set -e
if [ -z "${STAGING_PORTAL_HTPASSWD:-}" ]; then
  echo "STAGING_PORTAL_HTPASSWD is not set — refusing to start without the /club gate" >&2
  exit 1
fi
printf '%s\n' "$STAGING_PORTAL_HTPASSWD" > /etc/nginx/staging.htpasswd
chmod 640 /etc/nginx/staging.htpasswd
chown root:nginx /etc/nginx/staging.htpasswd
