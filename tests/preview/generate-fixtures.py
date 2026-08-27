"""Builds the demo audio and its waveform peaks for the visual preview page."""

import base64
import math
import os
import random
import struct
import wave

HERE = os.path.dirname(os.path.abspath(__file__))
RATE = 8000
SECONDS = 20
BARS = 400

random.seed(7)

frames = bytearray()
for n in range(RATE * SECONDS):
    t = n / RATE
    envelope = 0.35 + 0.5 * abs(math.sin(t * 0.7)) + 0.15 * random.random()
    sample = envelope * math.sin(2 * math.pi * 220 * t)
    frames += struct.pack('<h', int(max(-1, min(1, sample)) * 32000))

with wave.open(os.path.join(HERE, 'demo.wav'), 'wb') as out:
    out.setnchannels(1)
    out.setsampwidth(2)
    out.setframerate(RATE)
    out.writeframes(bytes(frames))

bucket = len(frames) // 2 // BARS
peaks = []
for i in range(BARS):
    peak = 0
    for j in range(i * bucket, (i + 1) * bucket, 7):
        peak = max(peak, abs(struct.unpack_from('<h', frames, j * 2)[0]))
    peaks.append(peak / 32768)

loudest = max(peaks)
encoded = base64.b64encode(bytes(int(round(p / loudest * 255)) for p in peaks))

with open(os.path.join(HERE, 'peaks.txt'), 'w', encoding='utf-8') as out:
    out.write(encoded.decode())

print('demo.wav and peaks.txt written')
