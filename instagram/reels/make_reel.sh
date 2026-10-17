#!/usr/bin/env bash
# Baut ein Reel (1080x1920, 30 fps, H.264 + stille Tonspur) aus gerenderten Karussell-Slides.
# Aufruf: make_reel.sh <grafik-ordner> "<dauer1> <dauer2> ..."   (eine Dauer pro Slide in Sekunden)
# Entfernt «Swipe →» (Slide 1) und den Zähler unten rechts, legt reel.mp4 und reel-cover.jpg im Ordner ab.
set -euo pipefail
G=$1; read -ra durs <<< "$2"; T=$(mktemp -d)
python3 -I - "$G" "$T" <<'PY'
import sys, glob, os
from PIL import Image, ImageDraw
G, T = sys.argv[1:3]
for f in sorted(glob.glob(f'{G}/0*.jpg')):
    im = Image.open(f).convert('RGB'); d = ImageDraw.Draw(im); n = os.path.basename(f)
    if n == '01.jpg': d.rectangle((300, 1140, 780, 1240), fill=(49, 49, 49))
    d.rectangle((900, 1230, 1080, 1320), fill=(49, 49, 49))
    bg = Image.new('RGB', (1080, 1920), (49, 49, 49)); bg.paste(im, (0, 285)); bg.save(f'{T}/{n[:-4]}.png')
PY
n=0; for f in "$T"/0*.png; do d=${durs[$n]}; fr=$(python3 -c "print(int($d*30))")
  ffmpeg -loglevel error -y -loop 1 -i "$f" -vf "scale=2160:-1,zoompan=z='min(1+0.0009*on,1.06)':x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)':d=$fr:s=1080x1920:fps=30,format=yuv420p" -frames:v "$fr" -c:v libx264 -preset medium -crf 20 "$T/c$n.mp4"; n=$((n+1)); done
inputs=(); for ((k=0;k<n;k++)); do inputs+=(-i "$T/c$k.mp4"); done
fc=""; off=0; prev="0:v"; for ((k=1;k<n;k++)); do d=${durs[$((k-1))]}; off=$(python3 -c "print(round($off+$d-0.4,2))"); fc="$fc[$prev][$k:v]xfade=transition=fade:duration=0.4:offset=$off[v$k];"; prev="v$k"; done
ffmpeg -loglevel error -y "${inputs[@]}" -f lavfi -i anullsrc=r=44100:cl=stereo -filter_complex "${fc%;}" -map "[$prev]" -map "$n:a" -shortest \
  -c:v libx264 -preset medium -crf 20 -pix_fmt yuv420p -c:a aac -b:a 128k -movflags +faststart "$G/reel.mp4"
python3 -I -c "from PIL import Image; Image.open('$T/01.png').convert('RGB').save('$G/reel-cover.jpg', quality=92)"
rm -rf "$T"; echo "$G/reel.mp4"
