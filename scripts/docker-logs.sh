#!/bin/bash

while getopts c:n: flag
do
    case "${flag}" in
        c) containerId=${OPTARG};;
        n) n=${OPTARG};;
    esac
done

optionalParams=()

if [ -z "$containerId" ]; then
    echo "Missing container ID -c"
    exit 1
fi

if [ ! -z "$n" ]; then
	optionalParams+=( -n "$n" )
fi

docker logs "$containerId" "${optionalParams[@]}"
