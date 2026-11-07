/* wa_voice.js — record a voice note in the browser and hand back a file WhatsApp accepts.
 *
 *   WaVoice.supported()                           -> true when this browser can record a WhatsApp-compatible voice note
 *   WaVoice.record({ maxMs, onTick(ms), onLimit }) -> Promise<ctrl>       (asks for the microphone; rejects with a readable message)
 *     ctrl.stop()    -> Promise<{ file: File, ms }>                       stop and get the voice note (.ogg / .m4a)
 *     ctrl.cancel()                                                        throw the recording away
 *
 * WhatsApp (Cloud API) takes audio as OGG with the OPUS codec (a real voice note), or AAC in .m4a, up to 16 MB. It does NOT take WebM.
 *   Firefox records OGG/Opus itself                       -> sent as it is
 *   Chrome / Edge / Android record Opus inside WebM        -> the Opus packets are re-wrapped into an OGG file here (no re-encoding, no quality loss, takes milliseconds)
 *   Safari / iPhone record AAC in MP4                      -> sent as .m4a
 * Needs https (or localhost) for the microphone.
 */
(function () {
  'use strict';
  if (window.WaVoice) return;

  const TYPES = [['audio/ogg;codecs=opus', 'ogg'], ['audio/webm;codecs=opus', 'webm'], ['audio/mp4;codecs=mp4a.40.2', 'm4a']];   // plain "audio/mp4" is skipped on purpose: Chrome puts Opus in it, which WhatsApp refuses
  function pick() { try { if (!window.MediaRecorder || !navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) return null; for (const t of TYPES) if (MediaRecorder.isTypeSupported(t[0])) return t; } catch (e) { } return null; }
  const supported = () => !!pick();

  // ---------------------------------------------------------------------------------------------------------------- WebM (Opus) -> OGG (Opus)
  const MASTER = new Set([0x18538067, 0x1654AE6B, 0xAE, 0xE1, 0x1F43B675, 0xA0]);   // Segment, Tracks, TrackEntry, Audio, Cluster, BlockGroup: we walk straight into them
  function vint(b, p, keepMarker) {
    const f = b[p]; if (f === undefined || f === 0) return null; let len = 1, mask = 0x80; while (!(f & mask)) { len++; mask >>= 1; }
    let v = keepMarker ? f : f & (mask - 1), allOnes = (f & (mask - 1)) === mask - 1;
    for (let i = 1; i < len; i++) { const x = b[p + i]; if (x === undefined) return null; v = v * 256 + x; if (x !== 255) allOnes = false; }
    return { v, len, unknown: allOnes };
  }
  function parseWebm(buf) {
    const b = new Uint8Array(buf); let pos = 0; const out = { priv: null, channels: 1, frames: [] };
    while (pos < b.length) {
      const id = vint(b, pos, true); if (!id) break; const sz = vint(b, pos + id.len, false); if (!sz) break; const body = pos + id.len + sz.len;
      if (MASTER.has(id.v)) { pos = body; continue; }   // enter (works for unknown-size elements too)
      const end = sz.unknown ? b.length : Math.min(b.length, body + sz.v);
      if (id.v === 0x63A2) out.priv = b.slice(body, end);
      else if (id.v === 0x9F) out.channels = b[body] || 1;
      else if (id.v === 0xA3 || id.v === 0xA1) {   // SimpleBlock / Block: track (vint), time (2 bytes), flags (1 byte), then one Opus packet
        const tr = vint(b, body, false); if (tr) { const fl = b[body + tr.len + 2]; if ((fl & 0x06) !== 0) throw new Error('This recording uses a layout that cannot be converted.'); const s = body + tr.len + 3; if (end > s) out.frames.push(b.slice(s, end)); }
      }
      pos = end;
    }
    return out;
  }
  function opusSamples(p) {   // how many 48 kHz samples one Opus packet holds (RFC 6716 TOC byte)
    const toc = p[0], cfg = toc >> 3; let fd;
    if (cfg < 12) fd = [480, 960, 1920, 2880][cfg & 3]; else if (cfg < 16) fd = (cfg & 1) ? 960 : 480; else fd = [120, 240, 480, 960][cfg & 3];
    const c = toc & 3, n = c === 0 ? 1 : (c === 3 ? (p[1] & 0x3f) : 2); return fd * n;
  }
  let CRC = null;
  function crc32(u8) { if (!CRC) { CRC = new Uint32Array(256); for (let i = 0; i < 256; i++) { let r = i << 24; for (let j = 0; j < 8; j++) r = (r & 0x80000000) ? ((r << 1) ^ 0x04C11DB7) : (r << 1); CRC[i] = r >>> 0; } } let c = 0; for (let i = 0; i < u8.length; i++) c = ((c << 8) ^ CRC[((c >>> 24) & 0xff) ^ u8[i]]) >>> 0; return c >>> 0; }
  function oggPage(type, granule, serial, seq, packets) {   // packets: array of Uint8Array that END on this page
    const segs = []; let size = 0; for (const p of packets) { let n = p.length; while (n >= 255) { segs.push(255); n -= 255; } segs.push(n); size += p.length; }
    const out = new Uint8Array(27 + segs.length + size), dv = new DataView(out.buffer);
    out.set([0x4f, 0x67, 0x67, 0x53, 0, type]); dv.setUint32(6, granule >>> 0, true); dv.setUint32(10, Math.floor(granule / 4294967296) >>> 0, true); dv.setUint32(14, serial, true); dv.setUint32(18, seq, true); out[26] = segs.length; out.set(segs, 27);
    let o = 27 + segs.length; for (const p of packets) { out.set(p, o); o += p.length; }
    dv.setUint32(22, crc32(out), true); return out;
  }
  function webmToOgg(buf) {
    const w = parseWebm(buf); if (!w.frames.length) throw new Error('The recording is empty.');
    let head = w.priv; if (!head || head.length < 19 || String.fromCharCode.apply(null, head.slice(0, 8)) !== 'OpusHead') { head = new Uint8Array(19); head.set([0x4f, 0x70, 0x75, 0x73, 0x48, 0x65, 0x61, 0x64, 1, w.channels || 1, 0x38, 0x01]); new DataView(head.buffer).setUint32(12, 48000, true); }   // pre-skip 312
    const pre = head[10] | (head[11] << 8), serial = (Math.random() * 0xffffffff) >>> 0, parts = []; let seq = 0;
    parts.push(oggPage(0x02, 0, serial, seq++, [head]));
    const tags = new Uint8Array(8 + 4 + 2 + 4); tags.set([0x4f, 0x70, 0x75, 0x73, 0x54, 0x61, 0x67, 0x73]); new DataView(tags.buffer).setUint32(8, 2, true); tags.set([0x77, 0x61], 12);   // vendor "wa", no comments
    parts.push(oggPage(0, 0, serial, seq++, [tags]));
    let cum = pre, batch = [], bs = 0;
    const flush = (last) => { if (!batch.length) return; parts.push(oggPage(last ? 0x04 : 0, cum, serial, seq++, batch)); batch = []; bs = 0; };
    for (let i = 0; i < w.frames.length; i++) { const f = w.frames[i]; cum += opusSamples(f); batch.push(f); bs += f.length; if (batch.length >= 25 || bs > 6000) flush(i === w.frames.length - 1); }
    if (batch.length) flush(true); else { /* the last flush already carried the end flag only if it was the final frame */ }
    let total = 0; parts.forEach((p) => { total += p.length; }); const o = new Uint8Array(total); let k = 0; parts.forEach((p) => { o.set(p, k); k += p.length; });
    return o;
  }

  // ---------------------------------------------------------------------------------------------------------------- recorder
  async function record(opts) {
    opts = opts || {}; const t = pick(); if (!t) throw new Error('This browser cannot record a voice note that WhatsApp accepts. Use an updated Chrome, Edge, Firefox or Safari.');
    let stream;
    try { stream = await navigator.mediaDevices.getUserMedia({ audio: { channelCount: 1, echoCancellation: true, noiseSuppression: true, autoGainControl: true } }); }
    catch (e) { throw new Error(e && /denied|permission|notallowed/i.test(e.name + ' ' + e.message) ? 'The microphone is blocked. Allow it in the browser (the lock icon next to the address) and try again.' : 'No microphone was found.'); }
    let mr; try { mr = new MediaRecorder(stream, { mimeType: t[0], audioBitsPerSecond: 32000 }); } catch (e) { stream.getTracks().forEach((x) => x.stop()); throw new Error('Could not start the recorder: ' + (e.message || e)); }
    const chunks = []; mr.ondataavailable = (e) => { if (e.data && e.data.size) chunks.push(e.data); };
    const t0 = Date.now(), maxMs = opts.maxMs || 600000; let done = false;
    const release = () => { stream.getTracks().forEach((x) => x.stop()); clearInterval(iv); };
    const iv = setInterval(() => { const ms = Date.now() - t0; if (opts.onTick) { try { opts.onTick(ms); } catch (e) { } } if (ms >= maxMs && !done && opts.onLimit) { try { opts.onLimit(); } catch (e) { } } }, 250);
    mr.start(1000);
    const finish = () => new Promise((ok) => { if (mr.state === 'inactive') return ok(); mr.onstop = () => ok(); try { mr.stop(); } catch (e) { ok(); } });
    return {
      cancel() { done = true; try { if (mr.state !== 'inactive') { mr.onstop = null; mr.stop(); } } catch (e) { } release(); },
      async stop() {
        done = true; const ms = Date.now() - t0; await finish(); release();
        const blob = new Blob(chunks, { type: t[0].split(';')[0] }); if (!blob.size || ms < 600) throw new Error('The recording was too short.');
        if (t[1] === 'webm') { const ogg = webmToOgg(await blob.arrayBuffer()); return { file: new File([ogg], 'voice-note.ogg', { type: 'audio/ogg' }), ms }; }
        return { file: new File([blob], 'voice-note.' + t[1], { type: t[1] === 'ogg' ? 'audio/ogg' : 'audio/mp4' }), ms };
      },
    };
  }
  window.WaVoice = { supported, record, _webmToOgg: webmToOgg };
})();
