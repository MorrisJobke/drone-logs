# Drone log fetcher for Nextcloud

This is a little script to properly extract logs from drone and render them in GitHub-flavored Markdown.

You only need to provide the drone token from https://drone.nextcloud.com/account/token inside `process.php` and then you can call it like this:

```bash
$ php process.php
... prints all logs of failed jobs that are against master until $MINIMUM_JOB_ID is reached

$ php process.php 12345
... prints logs of failed jobs from https://drone.nextcloud.com/nextcloud/server/12345
```
