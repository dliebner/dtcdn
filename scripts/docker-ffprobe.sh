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

docker run "${dirParams[@]}" dliebner/ffmpeg-entrydefault ffprobe -v quiet -print_format json -show_format -show_streams "$filename"
