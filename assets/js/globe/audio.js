/**
 * The globe's soundscape, synthesised entirely in WebAudio.
 *
 * No audio files: the ambience is two detuned drones under slowly filtered
 * noise — the "space wind" every planetarium reaches for — and the interface
 * sounds are short envelopes on oscillators. That keeps the repository free
 * of binary assets and the site free of extra requests, in browser and in
 * the Android app alike.
 *
 * Sound is off until the visitor turns it on (autoplay policy agrees), and
 * the choice is remembered. Everything is quiet by design: an ambience is
 * something you notice when it stops.
 */

const STORAGE_KEY = 'mtl-globe-sound';

export class GlobeAudio {
  constructor() {
    this.context = null;
    this.master = null;
    this.enabled = false;
  }

  /** Whether the visitor had sound on last time. */
  static remembered() {
    try {
      return localStorage.getItem(STORAGE_KEY) === 'on';
    } catch {
      return false;
    }
  }

  remember() {
    try {
      localStorage.setItem(STORAGE_KEY, this.enabled ? 'on' : 'off');
    } catch {
      // Private mode; the toggle simply will not persist.
    }
  }

  /** Toggles the ambience. Must be called from a user gesture. */
  toggle() {
    this.enabled = !this.enabled;

    if (this.enabled) {
      this.start();
    } else {
      this.stop();
    }

    this.remember();

    return this.enabled;
  }

  start() {
    this.enabled = true;

    if (this.context) {
      this.context.resume();
      this.master.gain.cancelScheduledValues(this.context.currentTime);
      this.master.gain.linearRampToValueAtTime(0.16, this.context.currentTime + 1.5);
      return;
    }

    const AudioContextClass = window.AudioContext || window.webkitAudioContext;

    if (!AudioContextClass) return;

    const context = new AudioContextClass();

    this.context = context;
    this.master = context.createGain();
    this.master.gain.value = 0;
    this.master.connect(context.destination);
    this.master.gain.linearRampToValueAtTime(0.16, context.currentTime + 2.5);

    // --- The drones: two low, slightly detuned triangles. -------------------
    for (const [frequency, level] of [[55, 0.5], [82.7, 0.28]]) {
      const oscillator = context.createOscillator();
      oscillator.type = 'triangle';
      oscillator.frequency.value = frequency;

      const gain = context.createGain();
      gain.gain.value = level;

      // A very slow wobble on each drone's level keeps the pad breathing.
      const lfo = context.createOscillator();
      lfo.frequency.value = 0.05 + frequency / 4000;
      const lfoGain = context.createGain();
      lfoGain.gain.value = level * 0.35;

      lfo.connect(lfoGain);
      lfoGain.connect(gain.gain);

      const lowpass = context.createBiquadFilter();
      lowpass.type = 'lowpass';
      lowpass.frequency.value = 220;

      oscillator.connect(lowpass);
      lowpass.connect(gain);
      gain.connect(this.master);

      oscillator.start();
      lfo.start();
    }

    // --- The wind: looping noise through a wandering bandpass. --------------
    const seconds = 4;
    const buffer = context.createBuffer(1, context.sampleRate * seconds, context.sampleRate);
    const channel = buffer.getChannelData(0);

    for (let i = 0; i < channel.length; i += 1) {
      channel[i] = Math.random() * 2 - 1;
    }

    const noise = context.createBufferSource();
    noise.buffer = buffer;
    noise.loop = true;

    const bandpass = context.createBiquadFilter();
    bandpass.type = 'bandpass';
    bandpass.frequency.value = 400;
    bandpass.Q.value = 1.6;

    const windLfo = context.createOscillator();
    windLfo.frequency.value = 0.07;
    const windLfoGain = context.createGain();
    windLfoGain.gain.value = 220;
    windLfo.connect(windLfoGain);
    windLfoGain.connect(bandpass.frequency);

    const windGain = context.createGain();
    windGain.gain.value = 0.14;

    noise.connect(bandpass);
    bandpass.connect(windGain);
    windGain.connect(this.master);

    noise.start();
    windLfo.start();
  }

  stop() {
    this.enabled = false;

    if (!this.context) return;

    this.master.gain.cancelScheduledValues(this.context.currentTime);
    this.master.gain.linearRampToValueAtTime(0, this.context.currentTime + 0.6);

    // Suspend after the fade so the graph costs nothing while silent.
    setTimeout(() => {
      if (!this.enabled) this.context?.suspend();
    }, 800);
  }

  /** A soft, short blip: a stop was selected. */
  tick() {
    if (!this.enabled || !this.context) return;

    const context = this.context;
    const oscillator = context.createOscillator();
    const gain = context.createGain();

    oscillator.type = 'sine';
    oscillator.frequency.setValueAtTime(740, context.currentTime);
    oscillator.frequency.exponentialRampToValueAtTime(520, context.currentTime + 0.12);

    gain.gain.setValueAtTime(0.0001, context.currentTime);
    gain.gain.exponentialRampToValueAtTime(0.35, context.currentTime + 0.015);
    gain.gain.exponentialRampToValueAtTime(0.0001, context.currentTime + 0.18);

    oscillator.connect(gain);
    gain.connect(this.master);

    oscillator.start();
    oscillator.stop(context.currentTime + 0.2);
  }

  /** A rising sweep under a flight to a stop. */
  whoosh() {
    if (!this.enabled || !this.context) return;

    const context = this.context;
    const seconds = 0.9;

    const buffer = context.createBuffer(1, context.sampleRate * seconds, context.sampleRate);
    const channel = buffer.getChannelData(0);

    for (let i = 0; i < channel.length; i += 1) {
      channel[i] = Math.random() * 2 - 1;
    }

    const noise = context.createBufferSource();
    noise.buffer = buffer;

    const bandpass = context.createBiquadFilter();
    bandpass.type = 'bandpass';
    bandpass.Q.value = 2.5;
    bandpass.frequency.setValueAtTime(180, context.currentTime);
    bandpass.frequency.exponentialRampToValueAtTime(1100, context.currentTime + seconds * 0.55);
    bandpass.frequency.exponentialRampToValueAtTime(240, context.currentTime + seconds);

    const gain = context.createGain();
    gain.gain.setValueAtTime(0.0001, context.currentTime);
    gain.gain.exponentialRampToValueAtTime(0.3, context.currentTime + seconds * 0.35);
    gain.gain.exponentialRampToValueAtTime(0.0001, context.currentTime + seconds);

    noise.connect(bandpass);
    bandpass.connect(gain);
    gain.connect(this.master);

    noise.start();
    noise.stop(context.currentTime + seconds);
  }
}
