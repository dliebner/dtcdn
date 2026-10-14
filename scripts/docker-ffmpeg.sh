#!/bin/bash

# Parse the command-line options
while getopts d:i:o:b:w:h:st:pmF:I:Q:W:H: flag # (-H took no value until 2026-10, so the poster height was never applied)
do
	case "${flag}" in
		# Video transcoding args
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
		# Frame capture (screenshot) args
		F) frameOutFile=${OPTARG};;
		I) captureFrameIndex=${OPTARG};;
		Q) jpegQuality=${OPTARG};;
		W) frameWidth=${OPTARG};;
		H) frameHeight=${OPTARG};;
	esac
done

# Initialize directory parameters
dirParams=()

# If a directory has been specified, add it to dirParams
if [ ! -z "$dir" ]; then
	dirParams+=( -v "$dir":"$dir" )
	dirParams+=( -w "$dir" )
fi

# Initialize encoding parameters
encodeParams=()

# Initialize video filters
videoFilters=()

# Check if constrainWidth and/or constrainHeight are provided
if [ ! -z "$constrainWidth" ] && [ ! -z "$constrainHeight" ]; then
	# Both dimensions provided, constrain to those dimensions maintaining aspect ratio
	videoFilters+=( "scale='trunc(min(1,min($constrainWidth/iw,$constrainHeight/ih))*iw/2)*2':'trunc(min(1,min($constrainWidth/iw,$constrainHeight/ih))*ih/2)*2'" )
elif [ ! -z "$constrainWidth" ]; then
	# Only width provided, constrain width and calculate height to maintain aspect ratio
	videoFilters+=( "scale='trunc(min(iw,$constrainWidth)/2)*2':'trunc(min(iw,$constrainWidth)/a/2)*2'" )
elif [ ! -z "$constrainHeight" ]; then
	# Only height provided, constrain height and calculate width to maintain aspect ratio
	videoFilters+=( "scale='trunc(min(ih,$constrainHeight)*a/2)*2':trunc(min(ih,$constrainHeight)/2)*2" )
fi

if [ ! -z "$passthrough" ] && [ -z "$hlsOutput" ]; then # passthrough flag is set + HLS output is NOT set
	# Passthrough - copy the video stream directly
	encodeParams+=( -c:v:0 copy )
else
	# Encode video using h264 codec
	encodeParams+=( -fpsmax:v:0 30 )
	encodeParams+=( -c:v:0 h264 )
	encodeParams+=( -b:v:0 "$bitRate" -preset medium )
	encodeParams+=( -vf:0 $(IFS=, ; echo "${videoFilters[*]}") )
	encodeParams+=( -sws_flags bicubic )
	encodeParams+=( -movflags +faststart -pix_fmt yuv420p )
fi

# Check if mute flag is set
if [ ! -z "$mute" ]; then
	# Mute
	encodeParams+=( -an )
else
	# Audio encode- use libfdk_aac codec
	encodeParams+=( -c:a libfdk_aac )
	encodeParams+=( -vbr 2 )
	encodeParams+=( -profile:a aac_he )
fi

# Check if HLS output is required
if [ ! -z "$hlsOutput" ]; then
	# If no segment time is set for HLS output, calculate it
	if [ -z "$hlsTime" ]; then
		# aiming for 400k chunks by default
		divide=400000; (( by=bitRate/8 )); (( hlsTime=(divide+by-1)/by ))
	fi
	# Set HLS initialization time based on segment time
	if [ $hlsTime -lt 2 ]; then
		hlsInitTime=1
	else
		hlsInitTime=2
	fi
	# Add HLS-specific encoding parameters
	encodeParams+=( -f hls )
	encodeParams+=( -hls_playlist_type vod )
	encodeParams+=( -hls_init_time "$hlsInitTime" )
	encodeParams+=( -hls_time "$hlsTime" )
fi

encodeParams+=( "$outFile" )

# Capture frame?
if [ ! -z "$frameOutFile" ]; then

	# Initialize frame filters
	frameFilters=( "select=eq(n\,${captureFrameIndex:-0})" )

	# Check if frameWidth and/or frameHeight are provided
	if [ ! -z "$frameWidth" ] && [ ! -z "$frameHeight" ]; then
		# Both dimensions provided, constrain to those dimensions maintaining aspect ratio
		frameFilters+=( "scale='trunc(min(1,min($frameWidth/iw,$frameHeight/ih))*iw/2)*2':'trunc(min(1,min($frameWidth/iw,$frameHeight/ih))*ih/2)*2'" )
	elif [ ! -z "$frameWidth" ]; then
		# Only width provided, constrain width and calculate height to maintain aspect ratio
		frameFilters+=( "scale='trunc(min(iw,$frameWidth)/2)*2':'trunc(min(iw,$frameWidth)/a/2)*2'" )
	elif [ ! -z "$frameHeight" ]; then
		# Only height provided, constrain height and calculate width to maintain aspect ratio
		frameFilters+=( "scale='trunc(min(ih,$frameHeight)*a/2)*2':trunc(min(ih,$frameHeight)/2)*2" )
	fi

	# Add frame extraction command
	encodeParams+=( -vf:1 $(IFS=, ; echo "${frameFilters[*]}") )
	encodeParams+=( -vframes:v:1 1 )
	encodeParams+=( -c:v:1 mjpeg )
	encodeParams+=( -q:v:1 "$jpegQuality" )
	encodeParams+=( "$frameOutFile" )
	
fi

# Change the ownership of the directory to 'dtcdn' user
dtcdnUid=$(id -u dtcdn)
dtcdnGid=$(id -g dtcdn)
chown -R "$dtcdnUid":"$dtcdnGid" "$dir"

# Run the docker container for ffmpeg encoding with the collected parameters. It runs as the dtcdn user (since
# 2026-10; root before), so a process in the container has no root on the host side of the mount and the output it
# writes belongs to dtcdn like the directory it lands in.
# -y -nostdin: a retried job writes into the same out/ directory as the job before it, which may have left a partial
# file (killed or crashed mid-encode); without -y ffmpeg asks whether to overwrite, reads EOF from the detached
# container's stdin and exits 1, failing every retry
docker run "${dirParams[@]}" --user "$dtcdnUid":"$dtcdnGid" -d dliebner/ffmpeg-entrydefault ffmpeg -hwaccel none -y -nostdin \
-progress /dev/stdout \
-i "$inFile" \
-map 0:v:0 -map 0:a:0? \
-map_metadata -1 \
"${encodeParams[@]}"
