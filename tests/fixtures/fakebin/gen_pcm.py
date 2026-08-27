import sys, struct, math
# 8 kHz mono s16le: 1s at 25% amplitude, then 1s at full amplitude.
out = sys.stdout.buffer
for second, amp in ((0, 0.25), (1, 1.0)):
    for n in range(8000):
        v = int(amp * 32767 * math.sin(2 * math.pi * 440 * n / 8000))
        out.write(struct.pack('<h', v))
out.flush()
