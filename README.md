# Matomo Slack Reporter

A single-file PHP CLI script that connects to Matomo, rotates through eligible sites, and posts one site report at a time into Slack.

## Purpose

This script is intended to:

* run from cron every minute
* decide for itself whether the current minute is a valid reporting slot
* pull the list of Matomo sites the configured token can access
* exclude deleted sites
* rotate deterministically through the eligible site list without using SQL or flat-file state
* post a readable Slack report for one site at a time

## Current behavior

The script currently:

* uses a Matomo API auth token sent as a POST parameter
* uses a Slack webhook for delivery
* uses `America/Los\_Angeles` timezone
* filters out deleted sites using the site name pattern `(DELETED-##)`
* adapts reporting frequency based on the number of eligible sites and monthly slot capacity
* supports a forced test run from CLI
* reports:

  * today so far
  * yesterday full day
  * week to date
  * month to date
  * optional top channel
  * optional top page
  * rotation details

## File

* `matomo\_slack\_report.php`

## Configuration

All editable variables are kept in the config section at the top of the script.

Examples include:

* Matomo base URL
* Matomo token
* Slack webhook URL
* Slack channel
* timezone
* weekday restrictions
* deleted-site filter regex
* reporting display options
* schedule ladder

## CLI usage

Normal cron usage:

```bash
php /path/to/matomo\_slack\_report.php
```

Force a send immediately:

```bash
php /path/to/matomo\_slack\_report.php --force
```

Force a send without posting to Slack:

```bash
php /path/to/matomo\_slack\_report.php --force --dry-run
```

## Cron example

Run every minute:

```cron
\* \* \* \* \* /usr/bin/php /path/to/matomo\_slack\_report.php >> /dev/null 2>\&1
```

The script does not post every minute. It only posts when the current time matches a valid slot or when `--force` is used.

## Rotation model

This script does not store progress in SQL or a file.

Instead it:

1. fetches the eligible site list
2. sorts it in a stable order
3. calculates the valid reporting slots for the current month
4. chooses the current slot index
5. maps that slot to one site using modulo arithmetic

This allows deterministic rotation without persisted state.

## Deleted sites

Deleted sites are excluded by name pattern.

Default regex:

```php
'/\\(DELETED-\\d+\\)/i'
```

This is intended to skip names such as:

* `Tocsin Data Net (DELETED-30)`

## Requirements

* PHP with cURL enabled
* access to the Matomo Reporting API
* a valid Matomo auth token
* a Slack incoming webhook

## Notes

* The Matomo token is sent as a POST field, not in the query string
* The script is designed as a standalone CLI file with no external config file
* Output helpers are included so the script does not depend on `STDOUT` or `STDERR` being defined

## Suggested future improvements

* `--site-id=` CLI override for testing a specific site
* optional include/exclude lists by site ID or name
* optional quieter Slack mode for zero-traffic sites
* optional rolling 7-day mode instead of Monday-through-now week mode
* optional richer Slack block formatting

