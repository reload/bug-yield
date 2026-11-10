#!/bin/sh
set -e

# Cron starts jobs with an almost empty environment. Debian's cron reads
# /etc/environment through PAM, so pass the container's environment on to
# the jobs (e.g. SENDGRID_API_KEY).
printenv | grep -v -e '^HOME=' -e '^PWD=' -e '^SHLVL=' > /etc/environment

exec "$@"
