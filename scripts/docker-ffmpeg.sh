#!/bin/bash

while getopts d:i:o:b:w:h:st:pm flag
do
	case "${flag}" in
		d) dir=${OPTARG};;
		i) inFile=${OPTARG};;
		o) outFile=${OPTARG};;
		b) bitRate=${OPTARG};;
		w) constrainWidth=${OPTARG};;
		h) constrainHeight=${OPTARG};;
		s) hlsOutput=1;;
		t) hlsTime=${OPTARG};;
		p) passthrough=1;;
		m) mute=1;;
	esac
done

dirParams=()

if [ ! -z "$dir" ]; then
	dirParams+=( -v "$dir":"$dir" )
	dirParams+=( -w "$dir" )
fi


encodeParams=()

if [ ! -z "$passthrough" ] && [ -z "$hlsOutput" ]; then
	# Passthrough
	encodeParams+=( -c:v copy )
else
	# Not Passthrough
	encodeParams+=( -c:v h264 )
	encodeParams+=( -b:v "$bitRate" -preset medium )
	encodeParams+=( -vf "select='eq(n,0)+if(gt(t-prev_selected_t,1/30.50),1,0)'",scale="$constrainWidth:$constrainHeight" )
	encodeParams+=( -sws_flags bicubic )
	encodeParams+=( -movflags +faststart -pix_fmt yuv420p )
fi

if [ ! -z "$mute" ]; then
	# Mute
	encodeParams+=( -an )
else
	# Audio encode
	encodeParams+=( -c:a libfdk_aac )
	encodeParams+=( -vbr 2 )
	encodeParams+=( -profile:a aac_he )
fi

if [ ! -z "$hlsOutput" ]; then
	if [ -z "$hlsTime" ]; then
		# aiming for 400k chunks by default
		divide=400000; (( by=bitRate/8 )); (( hlsTime=(divide+by-1)/by ))
	fi
	if [ $hlsTime -lt 2 ]; then
		hlsInitTime=1
	else
		hlsInitTime=2
	fi
	encodeParams+=( -f hls )
	encodeParams+=( -hls_playlist_type vod )
	encodeParams+=( -hls_init_time "$hlsInitTime" )
	encodeParams+=( -hls_time "$hlsTime" )
fi

chown -R $(id -u dtcdn):$(id -g dtcdn) "$dir"

docker run "${dirParams[@]}" -d dliebner/ffmpeg-entrydefault ffmpeg -hwaccel none \
-progress /dev/stdout \
-i "$inFile" \
-vsync 0 \
-map 0:v:0 -map 0:a:0? \
-map_metadata -1 \
"${encodeParams[@]}" \
"$outFile"
