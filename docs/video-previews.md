# Browser video previews

An MP4 extension does not guarantee a browser can decode the video track.
For example, `dal uy final.mp4` contains MPEG-4 Part 2 video and AAC audio;
the browser was displaying only the playable audio track.

Authorized File Management and Trash video previews now inspect the codecs
with FFprobe. Compatible H.264 MP4 and VP8/VP9 WebM files are served directly.
Other formats get a private H.264/AAC MP4 preview, limited to 1280×720, with
metadata at the front for playback. Originals and original downloads are
unchanged. The same streaming response serves full files and video seek ranges
without loading the video into PHP memory.

FFmpeg with libx264 and FFprobe must be installed on the PHP server. The service
searches PATH and common installation directories. Optional absolute paths can
be set with `greyhound.ffmpegBinary` and `greyhound.ffprobeBinary` in `.env`.

Copies and codec decisions are cached in `writable/cache/video-previews` using
the source path, size, and modification/change times. Concurrent requests share
a file lock; only a completed conversion is published to the cache. Replaced
source files get a new cache key. These derived files can be removed when no
preview requests are running to reclaim disk space; the next request regenerates
them. First-time conversion may take a moment, and has a 180-second timeout.
Conversion failures display a video-specific error with the original download
still available.

Preparation temporarily gives PHP enough execution time for the bounded lock,
probe, and conversion steps (390 seconds for playback, 100 for thumbnails),
then restores the caller's time limit. FFmpeg/FFprobe retain their individual
deadlines and timed-out processes are terminated. Server Library MIME detection
inspects only the first 64 KiB, avoiding large file scans on mounted shares for
each preview or range request.

The viewer app uses `/api/v1/mobile/media/{id}/thumbnail` for small JPEG images
in the featured banner and media cards. Video frames are extracted directly
and cached separately from playback copies; thumbnail requests do not convert
or fetch the full video. The endpoint rechecks publication/deletion status
before serving cached images or returning 304. The feed supplies this URL in
`thumbnail_url`; older saved stream URLs are mapped to the same endpoint by the
viewer. Failed thumbnails show a placeholder instead of an empty card.

The viewer's `/stream` endpoint also uses the compatible playback copy and
bounded streaming, including byte-range requests. It must not build a PHP
response body with `file_get_contents()` for large videos: a fatal error returns
an error page instead of video bytes, which browsers may block as opaque media.
