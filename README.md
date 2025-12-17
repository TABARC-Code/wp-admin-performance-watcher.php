<p align="center">
  <img src=".branding/tabarc-icon.png" width="180" alt="TABARC-Code Icon">
</p>

# WP Admin Performance Watcher
Because the admin getting slower is treated like weather.
It is not weather. Something caused it, and it usually has a plugin name.
This plugin samples admin requests and stores basic performance metrics over time. It gives me slowest screens, worst outliers, and a rolling history so I can stop guessing.

## What it does
Adds:
Tools
Admin Performance
Tracks (sampled):
Total load time (ms)
Query count
Peak memory usage
Screen id and hook suffix
Active plugins hash (so I can correlate changes)
Optional slow query capture if SAVEQUERIES is enabled
Keeps rolling history (default 14 days) and cleans up old records daily.
Exports JSON for diffing and ticket evidence.

## What it does not do
No front end tracking
No auto fixes
No plugin disabling
No database “repair”
It is evidence. You still have to act like an adult.

## Requirements
WordPress 6.0+ recommended
PHP 8.0+ recommended
Database access to create two small tables
## Notes
Slow query capture requires SAVEQUERIES enabled in wp-config.php.
That adds overhead. Decide like a grown up.
Sampling is the whole point. Logging every admin request forever is how you make a new performance problem while solving the old one.
## License
GPL-3.0-or-later. See LICENSE.
