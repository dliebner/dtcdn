#!/bin/sh

# Finishes a transcoding job the moment its container exits: every container "die" event is handed to
# scripts/docker-container-die.php, which looks the id up in transcoding_jobs and runs finishTranscode().
# Run by systemd as dtcdn-docker-events (install/dtcdn-docker-events.service). If this daemon is down when a
# container exits, cron/minute.php's sweep finishes the job within a minute instead.

docker events --filter 'type=container' --filter 'event=die' | while read event

do

	container_id=$(echo $event | sed -r -n 's/.* container die ([a-zA-Z0-9]+) .*/\1/p')

	if [ ! -z "$container_id" ]; then
		sudo -u dtcdn /usr/bin/php7.4 /home/dtcdn/scripts/docker-container-die.php "$container_id"
	fi

done
