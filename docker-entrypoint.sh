#!/bin/bash
set -e

# Start cron for monthly leave accrual
cron

# Run Apache
exec apache2-foreground
