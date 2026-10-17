#!/usr/bin/env python3
"""Baut ein Text-Reel (1080x1920, 30 fps, H.264 + stille Tonspur) aus <ordner>/slides.json.

Aufruf: python3 -I make_reel.py <ordner>
slides.json: {"handle": "@...", "slides": [{"kicker": "...", "big": "...", "text": "...", "dur": 3.5}, ...]}
Legt reel.mp4, cover.jpg und die Einzelbilder slide-XX.png im Ordner ab.
Musik kommt in der Instagram-App dazu (lizenzierte Bibliothek), deshalb ist die Tonspur stumm.
"""
import json, os, random, subprocess, sys, tempfile, textwrap
from PIL import Image, ImageDraw, ImageFilter, ImageFont

W, H, FPS = 1080, 1920, 30
BG, INK, PAPER, RED, MUTED = (17, 17, 17), (17, 17, 17), (237, 230, 214), (179, 38, 30), (150, 144, 132)
F = '/usr/share/fonts/opentype/inter/'
BLACK, BOLD, REG = F + 'InterDisplay-Black.otf', F + 'InterDisplay-Bold.otf', F + 'InterDisplay-Regular.otf'
MONO = '/usr/share/fonts/truetype/liberation/LiberationMono-Bold.ttf'


def font(path, size):
    return ImageFont.truetype(path, size)


def background():
    im = Image.new('RGB', (W, H), BG)
    rnd = random.Random(7)
    grain = Image.new('L', (W // 2, H // 2))
    grain.putdata([rnd.randint(0, 255) for _ in range(W * H // 4)])
    grain = grain.resize((W, H)).filter(ImageFilter.GaussianBlur(0.6))
    im = Image.blend(im, Image.merge('RGB', [grain] * 3), 0.05)
    vig = Image.new('L', (W, H), 0)
    ImageDraw.Draw(vig).ellipse((-300, -200, W + 300, H + 200), fill=255)
    vig = vig.filter(ImageFilter.GaussianBlur(220))
    return Image.composite(im, Image.new('RGB', (W, H), (0, 0, 0)), vig)


def wrap(draw, text, fnt, width):
    lines = []
    for para in text.split('\n'):
        words, cur = para.split(), ''
        for w in words:
            t = (cur + ' ' + w).strip()
            if draw.textlength(t, font=fnt) <= width:
                cur = t
            else:
                lines.append(cur); cur = w
        lines.append(cur)
    return lines


def render(slide, handle, i, n):
    im = background(); d = ImageDraw.Draw(im); x, maxw = 90, W - 180
    y = 560
    if slide.get('kicker'):
        f = font(MONO, 44)
        d.text((x, y), slide['kicker'].upper(), font=f, fill=RED); y += 90
    if slide.get('big'):
        size = slide.get('big_size', 200)
        f = font(BLACK, size)
        for line in wrap(d, slide['big'], f, maxw):
            d.text((x, y), line, font=f, fill=PAPER); y += int(size * 1.05)
        y += 20
        d.rectangle((x, y, x + 140, y + 12), fill=RED); y += 70
    if slide.get('text'):
        f = font(BOLD if not slide.get('big') else REG, slide.get('text_size', 58))
        for line in wrap(d, slide['text'], f, maxw):
            d.text((x, y), line, font=f, fill=PAPER); y += int(f.size * 1.3)
    if slide.get('source'):
        f = font(REG, 30); lines = wrap(d, slide['source'], f, maxw)
        for k, line in enumerate(lines):
            d.text((x, H - 300 - 42 * (len(lines) - k)), line, font=f, fill=MUTED)
    d.text((x, H - 250), handle, font=font(MONO, 36), fill=MUTED)
    bw = (W - 180) / n
    for k in range(n):
        d.rectangle((x + k * bw + 4, 160, x + (k + 1) * bw - 4, 166), fill=PAPER if k <= i else (60, 60, 60))
    return im


def main():
    g = sys.argv[1]
    cfg = json.load(open(os.path.join(g, 'slides.json'), encoding='utf-8'))
    slides, n = cfg['slides'], len(cfg['slides'])
    with tempfile.TemporaryDirectory() as t:
        clips = []
        for i, s in enumerate(slides):
            png = os.path.join(g, f'slide-{i + 1:02d}.png')
            render(s, cfg['handle'], i, n).save(png)
            fr, clip = int(s['dur'] * FPS), os.path.join(t, f'c{i}.mp4')
            subprocess.run(['ffmpeg', '-loglevel', 'error', '-y', '-loop', '1', '-i', png, '-vf',
                            f"scale=2160:-1,zoompan=z='min(1+0.0007*on,1.05)':x='iw/2-(iw/zoom/2)':y='ih/2-(ih/zoom/2)'"
                            f":d={fr}:s={W}x{H}:fps={FPS},format=yuv420p",
                            '-frames:v', str(fr), '-c:v', 'libx264', '-preset', 'medium', '-crf', '20', clip], check=True)
            clips.append(clip)
        inputs, fc, prev, off = [], '', '0:v', 0.0
        for c in clips:
            inputs += ['-i', c]
        for k in range(1, n):
            off = round(off + slides[k - 1]['dur'] - 0.35, 2)
            fc += f'[{prev}][{k}:v]xfade=transition=fade:duration=0.35:offset={off}[v{k}];'
            prev = f'v{k}'
        cmd = ['ffmpeg', '-loglevel', 'error', '-y', *inputs, '-f', 'lavfi', '-i', 'anullsrc=r=44100:cl=stereo']
        cmd += ['-filter_complex', fc.rstrip(';'), '-map', f'[{prev}]'] if fc else ['-map', '0:v']
        cmd += ['-map', f'{n}:a', '-shortest', '-c:v', 'libx264', '-preset', 'medium', '-crf', '20',
                '-pix_fmt', 'yuv420p', '-c:a', 'aac', '-b:a', '128k', '-movflags', '+faststart', os.path.join(g, 'reel.mp4')]
        subprocess.run(cmd, check=True)
    Image.open(os.path.join(g, 'slide-01.png')).convert('RGB').save(os.path.join(g, 'cover.jpg'), quality=92)
    print(os.path.join(g, 'reel.mp4'))


if __name__ == '__main__':
    main()
