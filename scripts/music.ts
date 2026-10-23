/**
 * An upbeat electronic track for scripts/video.ts, synthesized here sample by sample: kick,
 * clap, hi-hats, a pumping bass, chord stabs, pads and an arpeggio, in A minor at 124 BPM.
 * Nothing is sampled or copied from a recording, so the track is original and free to use
 * wherever the video goes.
 *
 * Structure, in bars: a 4-bar intro (pads and hats, the filter opening), a drop, a breakdown
 * near the middle, a 4-bar build with a snare roll and a riser, a second drop, and a 2-bar
 * outro. A soft whoosh plays at each of the given moments (the video's transitions).
 */

import { writeFileSync } from "node:fs";

const RATE = 44100;
const BPM = 124;
const BEAT = 60 / BPM;
const STEP = BEAT / 4;
const BAR = BEAT * 4;

/** The intro is 4 bars; the first drop comes right after it. */
export const INTRO_SECONDS = 4 * BAR;

/** Am, F, C, G: chord tones (MIDI) and the bass root. */
const PROGRESSION = [
  { tones: [57, 60, 64], root: 33 },
  { tones: [53, 57, 60], root: 29 },
  { tones: [55, 60, 64], root: 36 },
  { tones: [55, 59, 62], root: 31 },
];

/** The arpeggio walks the chord tones and the root an octave up, in 16ths. */
const ARP = [0, 1, 2, 3, 2, 1, 0, 1, 2, 3, 2, 1, 0, 1, 2, 3];

/** Steps of a bar (16ths) where the drop's chord stabs hit. */
const STABS = [0, 3, 6, 10, 12];

type Section = "intro" | "drop" | "breakdown" | "build" | "outro";

export interface Track {
  left: Float32Array;
  right: Float32Array;
}

function frequency(midi: number): number {
  return 440 * 2 ** ((midi - 69) / 12);
}

/** A repeatable noise source, so every render sounds the same. */
function noise(seed: number): () => number {
  let state = seed >>> 0;

  return () => {
    state = (state + 0x6d2b79f5) >>> 0;
    let value = state;
    value = Math.imul(value ^ (value >>> 15), value | 1);
    value ^= value + Math.imul(value ^ (value >>> 7), value | 61);

    return (((value ^ (value >>> 14)) >>> 0) / 4294967296) * 2 - 1;
  };
}

function lowpassAlpha(cutoff: number): number {
  return 1 - Math.exp((-2 * Math.PI * cutoff) / RATE);
}

class Mixer {
  readonly left: Float32Array;
  readonly right: Float32Array;
  /** Sidechain: the kick pushes the bass, chords and arpeggio down for a moment, so the track pumps. */
  readonly duck: Float32Array;

  constructor(readonly length: number) {
    this.left = new Float32Array(length);
    this.right = new Float32Array(length);
    this.duck = new Float32Array(length).fill(1);
  }

  /**
   * Adds a voice: sample(t, index) for t seconds after start, panned (-1 left to 1 right),
   * optionally ducked by the kick.
   */
  add(start: number, seconds: number, pan: number, ducked: boolean, sample: (time: number) => number): void {
    const first = Math.max(0, Math.round(start * RATE));
    const last = Math.min(this.length, first + Math.round(seconds * RATE));
    const left = Math.cos(((pan + 1) * Math.PI) / 4);
    const right = Math.sin(((pan + 1) * Math.PI) / 4);

    for (let index = first; index < last; index++) {
      const value = sample((index - first) / RATE) * (ducked ? this.duck[index] : 1);
      this.left[index] += value * left;
      this.right[index] += value * right;
    }
  }

  sidechain(start: number): void {
    const first = Math.round(start * RATE);
    const last = Math.min(this.length, first + Math.round(0.3 * RATE));

    for (let index = Math.max(0, first); index < last; index++) {
      const gain = 1 - 0.6 * Math.exp(-((index - first) / RATE) * 12);
      this.duck[index] = Math.min(this.duck[index], gain);
    }
  }
}

function kick(mixer: Mixer, start: number, level = 0.9): void {
  let phase = 0;
  const random = noise(Math.round(start * 1000));
  mixer.sidechain(start);
  mixer.add(start, 0.42, 0, false, (time) => {
    phase += (2 * Math.PI * (44 + 110 * Math.exp(-time * 32))) / RATE;
    const click = time < 0.004 ? random() * 0.35 : 0;

    return (Math.sin(phase) * Math.exp(-time * 7.5) + click) * level;
  });
}

function clap(mixer: Mixer, start: number, level = 0.32, seed = 7): void {
  const random = noise(seed + Math.round(start * 977));
  let low = 0;
  let high = 0;
  let previous = 0;
  mixer.add(start, 0.32, 0.05, false, (time) => {
    const bursts = time < 0.03 ? Math.exp(-((time % 0.01) * 300)) : Math.exp(-(time - 0.03) * 18);
    const raw = random();
    high = 0.85 * (high + raw - previous);
    previous = raw;
    low += lowpassAlpha(4200) * (high - low);

    return low * bursts * level;
  });
}

function hat(mixer: Mixer, start: number, open: boolean, level: number, pan: number): void {
  const random = noise(Math.round(start * 1301) + (open ? 11 : 3));
  let high = 0;
  let previous = 0;
  mixer.add(start, open ? 0.22 : 0.06, pan, false, (time) => {
    const raw = random();
    high = 0.55 * (high + raw - previous);
    previous = raw;

    return high * Math.exp(-time * (open ? 16 : 85)) * level;
  });
}

function crash(mixer: Mixer, start: number): void {
  const random = noise(Math.round(start * 733) + 5);
  let high = 0;
  let previous = 0;
  mixer.add(start, 2.4, -0.1, false, (time) => {
    const raw = random();
    high = 0.7 * (high + raw - previous);
    previous = raw;

    return high * Math.exp(-time * 2.2) * 0.16;
  });
}

function bass(mixer: Mixer, start: number, root: number, seconds: number): void {
  const pitch = frequency(root + 12);
  let phase = 0;
  let filtered = 0;
  mixer.add(start, seconds, 0, true, (time) => {
    phase = (phase + pitch / RATE) % 1;
    const saw = 2 * phase - 1;
    const sub = Math.sin(2 * Math.PI * phase);
    filtered += lowpassAlpha(180 + 900 * Math.exp(-time * 14)) * (saw - filtered);
    const envelope = Math.min(1, time / 0.005) * Math.min(1, (seconds - time) / 0.02);

    return (filtered * 0.55 + sub * 0.6) * envelope * 0.34;
  });
}

/** Detuned saws per chord tone, through a lowpass that can open over time. */
function chord(mixer: Mixer, start: number, seconds: number, tones: number[], level: number, cutoff: (time: number) => number, ducked: boolean, attack = 0.01, release = 0.05): void {
  const random = noise(Math.round(start * 631) + tones[0]);
  const voices = tones.flatMap((tone) => [0.996, 1, 1.004].map((detune) => ({ step: (frequency(tone) * detune) / RATE, phase: (random() + 1) / 2 })));
  let filtered = 0;
  mixer.add(start, seconds, 0, ducked, (time) => {
    let sum = 0;
    for (const voice of voices) {
      voice.phase = (voice.phase + voice.step) % 1;
      sum += 2 * voice.phase - 1;
    }
    filtered += lowpassAlpha(cutoff(time)) * (sum / voices.length - filtered);
    const envelope = Math.min(1, time / attack) * Math.min(1, (seconds - time) / release);

    return filtered * envelope * level;
  });
}

function pluck(mixer: Mixer, start: number, midi: number, level: number, pan: number): void {
  const step = frequency(midi) / RATE;
  let phase = 0;
  let filtered = 0;
  mixer.add(start, 0.28, pan, true, (time) => {
    phase = (phase + step) % 1;
    const square = phase < 0.32 ? 1 : -1;
    const triangle = 1 - 4 * Math.abs(phase - 0.5);
    filtered += lowpassAlpha(900 + 4200 * Math.exp(-time * 20)) * (square * 0.6 + triangle * 0.6 - filtered);

    return filtered * Math.exp(-time * 13) * level;
  });
}

/** Noise and a rising tone over the build, ending on the drop. */
function riser(mixer: Mixer, start: number, seconds: number): void {
  const random = noise(Math.round(start * 419));
  let phase = 0;
  let high = 0;
  let previous = 0;
  mixer.add(start, seconds, 0, false, (time) => {
    const progress = time / seconds;
    const raw = random();
    high = (0.3 + 0.65 * progress) * (high + raw - previous);
    previous = raw;
    phase += (2 * Math.PI * (180 + 1400 * progress * progress)) / RATE;

    return (high * 0.22 + Math.sin(phase) * 0.05) * progress * progress;
  });
}

/** A soft swoosh across the stereo field, for a video transition. */
function whoosh(mixer: Mixer, center: number): void {
  const seconds = 0.9;
  const random = noise(Math.round(center * 557));
  let band = 0;
  let previous = 0;
  const start = center - seconds / 2;
  const first = Math.max(0, Math.round(start * RATE));

  for (let index = first; index < Math.min(mixer.length, first + Math.round(seconds * RATE)); index++) {
    const time = (index - first) / RATE;
    const progress = time / seconds;
    const raw = random();
    band += lowpassAlpha(400 + 5000 * Math.sin(Math.PI * progress)) * (raw - band);
    const value = (band - previous * 0.6) * Math.sin(Math.PI * progress) ** 2 * 0.3;
    previous = band;
    mixer.left[index] += value * (1 - progress);
    mixer.right[index] += value * progress;
  }
}

/** Where the breakdown (6 bars) and the build after it (4 bars) start. */
function landmarks(bars: number): { breakdown: number; build: number } {
  const breakdown = Math.max(12, Math.round(bars * 0.36));

  return { breakdown, build: breakdown + 6 };
}

function sectionOf(bar: number, bars: number): Section {
  const { breakdown, build } = landmarks(bars);

  if (bar < 4) {
    return "intro";
  }
  if (bar >= bars - 2) {
    return "outro";
  }
  if (bar >= breakdown && bar < build) {
    return "breakdown";
  }
  if (bar >= build && bar < build + 4) {
    return "build";
  }

  return "drop";
}

/**
 * @param seconds Length of the track.
 * @param whooshes Moments (seconds) for a transition swoosh.
 */
export function promoTrack(seconds: number, whooshes: number[] = []): Track {
  const mixer = new Mixer(Math.ceil(seconds * RATE));
  const bars = Math.ceil(seconds / BAR);

  for (let bar = 0; bar < bars; bar++) {
    const start = bar * BAR;
    const section = sectionOf(bar, bars);
    const previous = bar > 0 ? sectionOf(bar - 1, bars) : null;
    const { tones, root } = PROGRESSION[bar % PROGRESSION.length];

    if ((section === "drop" && previous !== "drop") || (section === "outro" && previous !== "outro")) {
      crash(mixer, start);
    }

    // Pads: every section, opening up through the intro.
    const padLevel = section === "drop" ? 0.12 : 0.2;
    chord(mixer, start, BAR, tones, padLevel, (time) => (section === "intro" ? 300 + 2200 * ((bar * BAR + time) / (4 * BAR)) : section === "outro" ? 1800 - time * 500 : 1600), true, 0.08, 0.12);

    for (let step = 0; step < 16; step++) {
      const at = start + step * STEP;
      const beat = step % 4 === 0;

      if (section === "drop") {
        if (beat) {
          kick(mixer, at);
        }
        if (step === 4 || step === 12) {
          clap(mixer, at);
        }
        hat(mixer, at, step % 4 === 2, step % 4 === 2 ? 0.12 : 0.07, step % 2 === 0 ? -0.35 : 0.35);
        if (step % 4 === 2) {
          bass(mixer, at, root, STEP * 1.6);
        }
        if (STABS.includes(step)) {
          chord(mixer, at, STEP * 1.5, tones.map((tone) => tone + 12), 0.15, (time) => 3200 * Math.exp(-time * 6) + 600, true);
        }
      }

      if (section === "intro" && step % 2 === 0) {
        hat(mixer, at, false, 0.03 + 0.04 * (bar / 4), 0.3);
      }

      if (section === "build") {
        // A snare roll that doubles its speed toward the drop: quarters, then 8ths, then 16ths.
        const into = bar - landmarks(bars).build + step / 16;
        const every = into < 2 ? 4 : into < 3 ? 2 : 1;
        if (step % every === 0) {
          clap(mixer, at, 0.1 + 0.06 * into, 31);
        }
        if (beat && into >= 2) {
          kick(mixer, at, 0.55);
        }
      }

      if (section === "breakdown" && step % 2 === 1) {
        hat(mixer, at, false, 0.035, -0.3);
      }

      if (section !== "intro" || bar >= 2) {
        const tone = ARP[step] === 3 ? root + 24 : tones[ARP[step]] + 12;
        const level = section === "drop" ? 0.09 : section === "outro" ? 0.07 * (1 - step / 16) : 0.11;
        pluck(mixer, at, tone, level, step % 2 === 0 ? -0.25 : 0.25);
        // Echo three 16ths later on the other side.
        pluck(mixer, at + 3 * STEP, tone, level * 0.35, step % 2 === 0 ? 0.6 : -0.6);
      }
    }

    if (bar === landmarks(bars).build) {
      riser(mixer, start, 4 * BAR);
    }
    if (bar === 3) {
      riser(mixer, start, BAR);
    }
  }

  // The last chord rings out over the end.
  const last = PROGRESSION[(bars - 1) % PROGRESSION.length];
  kick(mixer, (bars - 1) * BAR);
  chord(mixer, (bars - 1) * BAR, Math.max(0.5, seconds - (bars - 1) * BAR), last.tones, 0.18, () => 1400, false, 0.01, 1.5);

  for (const moment of whooshes) {
    whoosh(mixer, moment);
  }

  // Gentle saturation, then fades at both ends.
  const fadeIn = 0.4 * RATE;
  const fadeOut = 2.5 * RATE;
  for (let index = 0; index < mixer.length; index++) {
    const fade = Math.min(1, index / fadeIn, (mixer.length - index) / fadeOut);
    mixer.left[index] = Math.tanh(mixer.left[index] * 1.1) * fade;
    mixer.right[index] = Math.tanh(mixer.right[index] * 1.1) * fade;
  }

  return { left: mixer.left, right: mixer.right };
}

/** 16-bit stereo WAV. */
export function writeWav(file: string, track: Track): void {
  const frames = track.left.length;
  const buffer = Buffer.alloc(44 + frames * 4);
  buffer.write("RIFF", 0);
  buffer.writeUInt32LE(36 + frames * 4, 4);
  buffer.write("WAVEfmt ", 8);
  buffer.writeUInt32LE(16, 16);
  buffer.writeUInt16LE(1, 20);
  buffer.writeUInt16LE(2, 22);
  buffer.writeUInt32LE(RATE, 24);
  buffer.writeUInt32LE(RATE * 4, 28);
  buffer.writeUInt16LE(4, 32);
  buffer.writeUInt16LE(16, 34);
  buffer.write("data", 36);
  buffer.writeUInt32LE(frames * 4, 40);

  for (let index = 0; index < frames; index++) {
    buffer.writeInt16LE(Math.round(Math.max(-1, Math.min(1, track.left[index])) * 32767), 44 + index * 4);
    buffer.writeInt16LE(Math.round(Math.max(-1, Math.min(1, track.right[index])) * 32767), 46 + index * 4);
  }

  writeFileSync(file, buffer);
}
