#!/bin/bash

# Read or kill a transcoding container, for TranscodingJob::containerState() / killContainer() (run via sudo; see
# install/dtcdn-sudoers). Options: -c <container id>, -a inspect | kill (default inspect).
#
# inspect prints one line: "<status> <exit code> <finished at>", e.g. "exited 0 2026-01-02T03:04:05.123456789Z" or
# "running 0 0001-01-01T00:00:00Z", and exits non-zero with docker's message when the container doesn't exist.

action=inspect

while getopts c:a: flag
do
	case "${flag}" in
		c) containerId=${OPTARG};;
		a) action=${OPTARG};;
	esac
done

if [ -z "$containerId" ]; then
	echo "Missing container ID -c"
	exit 1
fi

# Only a container id (docker prints the short 12 or full 64 hex form), never a name or an option
if ! [[ "$containerId" =~ ^[0-9a-f]{12,64}$ ]]; then
	echo "Invalid container ID -c"
	exit 1
fi

case "$action" in
	inspect)
		docker inspect --type container --format '{{.State.Status}} {{.State.ExitCode}} {{.State.FinishedAt}}' "$containerId"
		;;
	kill)
		# only a container this install started (the label from docker-ffmpeg.sh / docker-ffprobe.sh), never another
		# service's on the same host
		if [ "$(docker inspect --type container --format '{{index .Config.Labels "dtcdn.managed"}}' "$containerId" 2>/dev/null)" != "1" ]; then
			echo "Not a dtcdn container"
			exit 1
		fi
		docker kill "$containerId"
		;;
	*)
		echo "Unknown action -a $action"
		exit 1
		;;
esac
