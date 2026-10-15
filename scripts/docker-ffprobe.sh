#!/bin/bash

while getopts d:f: flag
do
    case "${flag}" in
        d) dir=${OPTARG};;
        f) filename=${OPTARG};;
    esac
done

dirParams=()

if [ ! -z "$dir" ]; then
	dirParams+=( -v "$dir":"$dir" )
	dirParams+=( -w "$dir" )
fi

# No network: a probe needs none. Root inside the container stays, since the upload temp file is 0600 www-data.
docker run "${dirParams[@]}" --network none --label dtcdn.managed=1 dliebner/ffmpeg-entrydefault ffprobe -v quiet -print_format json -show_format -show_streams "$filename"
