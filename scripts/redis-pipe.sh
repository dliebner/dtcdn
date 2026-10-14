#!/bin/bash

# TODO: This script should be replaced by a C++ program for increased performance

# GlobalLog "|/home/dtcdn/scripts/redis-pipe.sh" "%{end:sec}t %O %>s %v %U%q"
# ts, bytes, status, domain, URL

DTCDN_HOSTNAME=$DTCDN_HOSTNAME

while read logline; do

	parts=(${logline})
	ts=${parts[0]}
	bytes=${parts[1]}
	status=${parts[2]}
	domain=${parts[3]}

	# Cumulative chunk bandwidth
	redis-cli INCRBY dtcdn:bw_chunk ${bytes}

	# Rolling 30s bandwidth
	start=$((${ts} - 29))

	for (( i=$start; i<=$ts; i++ ))
	do

		expires=$((${i} + 30))
		redis-cli INCRBY dtcdn:bw_30sec_exp_${expires} ${bytes}
		redis-cli EXPIREAT dtcdn:bw_30sec_exp_${expires} ${expires}

	done

	# 404s
	if [ "${status}" == "404" ] && [ "$domain" == "${DTCDN_HOSTNAME}" ]; then

		uri=${parts[4]}
		redis-cli HSETNX dtcdn:404_uris "$uri" 1

	fi

done