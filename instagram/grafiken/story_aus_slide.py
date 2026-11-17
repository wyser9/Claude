# Feed-Slide (4:5) als Story (9:16) ohne Zoom: Slide mittig, Hintergrund = weichgezeichnete Slide.
# Aufruf: python3 -I instagram/grafiken/story_aus_slide.py <slide.jpg> <story.jpg>
import sys
from PIL import Image, ImageFilter, ImageEnhance
src, out = sys.argv[1:3]
s = Image.open(src).convert('RGB').resize((1080, 1350))
bg = s.resize((1536, 1920)).crop((228, 0, 1308, 1920)).filter(ImageFilter.GaussianBlur(40))
bg = ImageEnhance.Brightness(bg).enhance(0.45)
bg.paste(s, (0, 285))
bg.save(out, quality=92)
