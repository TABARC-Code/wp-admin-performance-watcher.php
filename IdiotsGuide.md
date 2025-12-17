# IdiotsGuide
WP Admin Performance Watcher
This is for when your dashboard feels slow and everyone starts blaming hosting. Again.
## What this actually tracks
Admin requests only.
It measures:
How long the request took (ms)
How many database queries ran
How much memory peaked
Which admin screen it was
It samples, it does not log everything.
## Where to find it
Tools
Admin Performance
## How to read the report
Summary:
Average load time over the retention window
A rough 95th percentile estimate
Slowest screens:
These are pages that are consistently slow. That is usually worse than one weird spike.
Worst outliers:
These are the single worst requests. Useful when someone says “it hung for 10 seconds” and you want proof.
Slow queries:
Only shows if SAVEQUERIES is enabled. If it is off, ignore this section.
## What I should do with the info
If one admin screen is slow:
Open it a few times.
Check if it correlates with a plugin screen.
Try disabling suspicious plugins on staging.
If everything is slow:
Look for options table bloat, cron chaos, or a plugin that hooks into admin_init and does too much.
Yes that is vague, welcome to WordPress.
## Settings that matter
Sample rate:
Start at 25 percent. Increase if you are not getting enough data.
Retention:
14 days is usually enough to spot “it got worse last week”.
If you set 90 days, expect more data and more storage. Still not massive, just dont pretend it is free.
## Safety note
This plugin stores performance samples. It does not delete content.
If you are worried about data footprint, lower sampling and retention.
