/**
 * previewSight — GIVING THE AGENT PIXELS, without ever pretending it has them.
 *
 * `previewController` measures the page: geometry, overflow, computed colour,
 * contrast, whether an image's bytes actually arrived, the rendered words. That
 * is better evidence than a picture for everything checkable by number — and it
 * cannot judge a composition. It cannot see that a correctly-loaded image is the
 * WRONG image, that a heading is sitting on a busy photo, that a logo is
 * stretched, or that a palette clashes. The house rule is that every creation
 * must LOOK designed, so without pixels that judgement falls entirely to the
 * owner.
 *
 * WHY THIS AND NOT DOM RASTERISATION. `html2canvas` and friends RE-RENDER the
 * page from the DOM with their own layout engine. A picture that disagrees with
 * the page is worse than no picture, because it is believed. `getDisplayMedia`
 * is the browser's own capture of its own compositor output: it cannot disagree,
 * because it is not a second renderer.
 *
 * ONE PROMPT, NOT ONE PER LOOK. `getDisplayMedia` returns a LIVE MediaStream.
 * Granted once, from one user gesture, it stays open; a frame is taken by
 * drawing the video to a canvas, as often as we like, with no further prompt.
 * (The earlier note in this repo claiming a prompt per look was simply wrong,
 * and it is what talked this design out of having eyes.)
 *
 * WHAT IS SHARED. `preferCurrentTab` + `displaySurface: 'browser'` asks for this
 * tab. Element Capture (`RestrictionTarget`, Chromium 132+) then restricts the
 * track AT SOURCE to the element that holds the page frames: the frames carry
 * that element's own pixels and nothing drawn over it. A drawer, the document
 * viewer or the expand pill on top of the dock is simply not in the picture,
 * and the capture carries on while the dock is covered (measured 2026-10-07,
 * Chromium 147: 30 frames a second under an opaque drawer, the page in every
 * frame). The element must stay rendered: hidden, transparent or off screen it
 * gives no frames at all, so the dock is never hidden that way while sharing.
 *
 * Without Element Capture this falls back to the whole tab, cropped to the
 * preview by Region Capture or by geometry. That picture is the SCREEN, so
 * anything over the dock is in it; `method: 'tab'` says so and the caller
 * declines a covered look as data. Where the owner shares a WINDOW or a WHOLE
 * SCREEN instead, nothing is captured: their email is not the agent's
 * business, and a frame is never worth taking by accident.
 *
 * HONESTY IS THE CONTRACT. Every capture returns `captured: true/false` with a
 * reason, never a guess and never a previous frame. A refusal here is a look
 * WITHOUT sight, not a failed look — `viewSite` still returns its measurements.
 */

const MAX_EDGE = 1100
const JPEG_QUALITY = 0.72
/** A presented frame settles within a few hundred ms; past this the surface is
 *  simply static, which is its own answer (see `capture`). */
const FRESH_FRAME_MS = 1200
/** Tab capture maps 1:1 onto the tab's own viewport. If the two axes disagree by
 *  more than this the surface is not what we think it is, and a crop computed
 *  from it would frame the wrong thing. */
const SCALE_AGREEMENT = 0.1

export interface SightCapture {
  /** Did a live frame get taken for THIS look? Never inferred, never carried. */
  captured: boolean
  /** Why there is no picture, in the owner's terms. Set whenever captured is false. */
  reason?: string
  width?: number
  height?: number
  /** What the frame shows, so the agent knows what it is looking at. */
  region?: string
  /** Cheap hash of the JPEG, so a repeat of the previous look is visible
   *  downstream. A page that did not change photographs the same twice —
   *  that is a fact to surface, not a reason to refuse. */
  frameHash?: string
  /** True when this frame is byte-identical to the previous look's. */
  repeatOfPreviousLook?: boolean
  /** Where the pixels came from: the track itself, or the video element. */
  frameSource?: 'track' | 'video'
  /**
   * How the picture is taken. `element`: only the page frame's own pixels,
   * nothing drawn over it (Element Capture). `tab`: a crop of the screen, so a
   * panel over the dock would be in it. Absent when sharing is off.
   */
  method?: SightMethod
  /** JPEG bytes, base64, no data: prefix. Only when captured. */
  base64?: string
  mediaType?: string
}

export type SightMethod = 'element' | 'tab'

// Element Capture, Region Capture and rVFC are not in lib.dom yet. Declared narrowly rather than
// cast away wholesale, so a typo still fails the build.
type CropTargetCtor = { fromElement(el: Element): Promise<unknown> }
type CroppableTrack = MediaStreamTrack & {
  cropTo?: (target: unknown) => Promise<void>
  /** Element Capture: only this element's subtree, nothing occluding it. */
  restrictTo?: (target: unknown) => Promise<void>
  /** Capture Handle — who is actually being captured. See TAB_HANDLE below. */
  getCaptureHandle?: () => { handle?: string; origin?: string } | null
}

/**
 * WHICH TAB IS ACTUALLY IN SHOT.
 *
 * `preferCurrentTab` is a HINT. `displaySurface: 'browser'` means the owner
 * picked a tab, not that they picked THIS one, and the picker happily offers
 * every other tab they have open. Two tabs in one window share a viewport size,
 * so the geometry fallback's scale check agrees for both — and it would then
 * crop this panel's coordinates out of somebody's mail and hand the model a
 * picture of it, labelled as the site.
 *
 * The file said it already refused that ("unless the shared surface is this
 * tab"); the code only ever proved "a tab". Same shape as every other bug this
 * week: unknown rendered as known.
 *
 * Capture Handle settles it. We stamp this document with a per-session random
 * handle and only the captured tab's handle comes back on the track, so a match
 * is proof of identity rather than a preference that was probably honoured.
 */
export const TAB_HANDLE = `bb-preview-${Math.random().toString(36).slice(2)}${Date.now().toString(36)}`

type HandleConfigurableMedia = MediaDevices & {
  setCaptureHandleConfig?: (c: { handle: string; exposeOrigin: boolean; permittedOrigins: string[] }) => void
}

/** Stamp this document so a capture of it is recognisable. No-op where unsupported. */
const stampTabHandle = (): void => {
  const md = navigator.mediaDevices as HandleConfigurableMedia
  if (typeof md.setCaptureHandleConfig !== 'function') return
  try {
    md.setCaptureHandleConfig({ handle: TAB_HANDLE, exposeOrigin: false, permittedOrigins: [location.origin] })
  } catch {
    // Unsupported argument shape — identity then stays unproven, and the
    // geometry fallback refuses rather than guessing.
  }
}

/** Is the track definitely capturing THIS tab? `null` = cannot tell. */
export const capturedThisTab = (t: CroppableTrack): boolean | null => {
  if (typeof t.getCaptureHandle !== 'function') return null
  let h: { handle?: string } | null = null
  try {
    h = t.getCaptureHandle()
  } catch {
    return null
  }
  if (!h || typeof h.handle !== 'string' || h.handle.length === 0) return null
  return h.handle === TAB_HANDLE
}
type FrameCallbackVideo = HTMLVideoElement & {
  requestVideoFrameCallback?: (cb: () => void) => number
}

const cropTargetCtor = (): CropTargetCtor | null => {
  const c = (window as unknown as { CropTarget?: CropTargetCtor }).CropTarget
  return c && typeof c.fromElement === 'function' ? c : null
}

const restrictionTargetCtor = (): CropTargetCtor | null => {
  const c = (window as unknown as { RestrictionTarget?: CropTargetCtor }).RestrictionTarget
  return c && typeof c.fromElement === 'function' ? c : null
}

/** Can this browser take the frame-only picture? Chromium 132+ only today. */
export const elementCaptureSupported = (): boolean => restrictionTargetCtor() !== null

/** The words the agent reads for each way of taking the picture. */
export const SIGHT_REGION: Record<SightMethod, string> = {
  element: 'the page frame only (nothing drawn over it is in the picture)',
  tab: 'the preview panel, cropped out of the shared screen',
}

/**
 * Restrict the track to `target` (Element Capture), else crop it to `target`
 * (Region Capture), else leave it whole for the geometry crop. Returns the
 * method actually in force; a browser that refuses one step falls to the next.
 */
export async function narrowTrack(
  track: CroppableTrack,
  target: Element | null,
  ctors: { restriction: CropTargetCtor | null; crop: CropTargetCtor | null } = { restriction: restrictionTargetCtor(), crop: cropTargetCtor() },
): Promise<{ method: SightMethod; cropped: boolean }> {
  if (!target) return { method: 'tab', cropped: false }
  if (ctors.restriction && typeof track.restrictTo === 'function') {
    try {
      await track.restrictTo(await ctors.restriction.fromElement(target))
      return { method: 'element', cropped: true }
    } catch {
      // Not eligible here (or not implemented): the screen crop below.
    }
  }
  if (ctors.crop && typeof track.cropTo === 'function') {
    try {
      await track.cropTo(await ctors.crop.fromElement(target))
      return { method: 'tab', cropped: true }
    } catch {
      // Crop unavailable for this element; `capture` falls to geometry,
      // which refuses outright unless the shared surface is this tab.
    }
  }
  return { method: 'tab', cropped: false }
}

/** Is a tab share even possible here? False on HTTP, in an iframe without the
 *  permission, and in browsers that do not implement it. */
export const sightSupported = (): boolean =>
  typeof navigator !== 'undefined' &&
  !!navigator.mediaDevices &&
  typeof navigator.mediaDevices.getDisplayMedia === 'function'

/**
 * Wait for the compositor to present a NEW frame, so a page that has just
 * navigated is definitely in shot.
 *
 * Resolves false on timeout, which is not a failure: screen capture delivers a
 * frame when the surface CHANGES, so a surface that has gone quiet means the
 * frame already held is the current one.
 */
const nextPresentedFrame = (video: HTMLVideoElement): Promise<boolean> =>
  new Promise((resolve) => {
    let settled = false
    const finish = (v: boolean) => {
      if (settled) return
      settled = true
      resolve(v)
    }
    const timer = window.setTimeout(() => finish(false), FRESH_FRAME_MS)
    const rvfc = (video as FrameCallbackVideo).requestVideoFrameCallback
    if (typeof rvfc === 'function') {
      rvfc.call(video, () => {
        window.clearTimeout(timer)
        finish(true)
      })
      return
    }
    // No rVFC: the media clock only advances when a frame is presented, so a
    // change in currentTime is the same signal by another route.
    const started = video.currentTime
    const tick = () => {
      if (settled) return
      if (video.currentTime !== started) {
        window.clearTimeout(timer)
        finish(true)
        return
      }
      window.requestAnimationFrame(tick)
    }
    window.requestAnimationFrame(tick)
  })

const declined = (reason: string, method?: SightMethod): SightCapture => ({ captured: false, reason, ...(method ? { method } : {}) })

export interface SightSession {
  /** Ask once. Needs a user gesture, so call it straight from a click. */
  grant(target: HTMLElement | null): Promise<{ ok: true } | { ok: false; error: string }>
  /** Give the stream back. The browser's sharing indicator goes away. */
  stop(): void
  /** Same, but silent — for teardown, where there is no UI left to tell. */
  release(): void
  live(): boolean
  /** How a picture is taken while live: frame only, or a crop of the screen. */
  method(): SightMethod | null
  /** Grab one frame of what the owner is looking at, right now. */
  capture(): Promise<SightCapture>
}

/**
 * Hold one grant for the life of the tab. `onEnded` fires when the owner
 * revokes it from the browser's own sharing bar, which is the path most people
 * will use and which React would otherwise never hear about.
 */
export function createSightSession(onEnded: () => void): SightSession {
  let stream: MediaStream | null = null
  let track: CroppableTrack | null = null
  let video: HTMLVideoElement | null = null
  let lastFrameHash: string | null = null
  let cropped = false
  let method: SightMethod | null = null

  const teardown = () => {
    stream?.getTracks().forEach((t) => t.stop())
    if (video) {
      video.srcObject = null
      video.remove()
    }
    stream = null
    track = null
    video = null
    cropped = false
    method = null
  }

  return {
    live: () => !!track && track.readyState === 'live',

    method: () => (track && track.readyState === 'live' ? method : null),

    stop: () => {
      teardown()
      onEnded()
    },

    release: teardown,

    async grant(target) {
      if (!sightSupported()) {
        return { ok: false, error: 'this browser cannot share a tab, so the agent measures pages without seeing them' }
      }
      try {
        stampTabHandle()
        const media = await navigator.mediaDevices.getDisplayMedia({
          // Ask for THIS tab. The owner can still choose otherwise, and
          // `capture` refuses rather than framing whatever they picked.
          video: {
            displaySurface: 'browser',
            width: { ideal: 2560 },
            height: { ideal: 1440 },
            frameRate: { ideal: 4 },
          },
          audio: false,
          preferCurrentTab: true,
          selfBrowserSurface: 'include',
          surfaceSwitching: 'exclude',
        } as DisplayMediaStreamOptions)
        const v = document.createElement('video')
        v.muted = true
        v.playsInline = true
        v.style.position = 'fixed'
        v.style.width = '1px'
        v.style.height = '1px'
        v.style.opacity = '0'
        v.style.pointerEvents = 'none'
        v.srcObject = media
        document.body.appendChild(v)
        await v.play().catch(() => undefined)

        stream = media
        video = v
        track = media.getVideoTracks()[0] as CroppableTrack | undefined ?? null
        if (!track) {
          // A share with no video track would leave the browser's sharing
          // indicator up for a session that can never produce a frame.
          teardown()
          return { ok: false, error: 'the browser shared nothing this panel can read, so the agent keeps measuring without seeing' }
        }

        // NARROW AT SOURCE where the browser supports it: the track then
        // carries ONLY the page frame, so nothing else in this admin page, or
        // behind it, or over it, is ever encoded, uploaded, or seen by the model.
        ;({ method, cropped } = await narrowTrack(track, target))

        // A SHARE CAN BE SWITCHED MID-SESSION. Chrome's picker lets the owner
        // move the share to a different tab without ending the track, so an
        // identity proved at grant time is not proof a minute later. `capture`
        // re-checks on every look, and this drops the session outright the
        // moment the handle changes, so the panel stops claiming sight it no
        // longer has rather than refusing look by look.
        const onHandleChange = () => {
          if (!track) return
          if (capturedThisTab(track) === true) return
          teardown()
          onEnded()
        }
        ;(track as unknown as EventTarget).addEventListener('capturehandlechange', onHandleChange)

        track.addEventListener(
          'ended',
          () => {
            teardown()
            onEnded()
          },
          { once: true },
        )
        return { ok: true }
      } catch (e) {
        teardown()
        const name = e instanceof Error ? e.name : ''
        if (name === 'NotAllowedError') {
          return { ok: false, error: 'sharing was declined, so the agent keeps measuring pages without seeing them' }
        }
        return { ok: false, error: e instanceof Error ? e.message : 'the browser refused to share this tab' }
      }
    },

    async capture(): Promise<SightCapture> {
      if (!track || !video) return declined('the owner has not turned on "let the agent see", so there is no picture of this page')
      const how = method ?? 'tab'
      if (track.readyState !== 'live') return declined('the screen share has ended, so nothing could be captured on this look', how)
      if (track.muted) return declined('the browser has paused the screen share, so nothing could be captured on this look', how)
      // A BACKGROUNDED TAB IS NOT REPAINTED, so the last frame the compositor
      // handed us may predate the page that was just loaded. That is precisely
      // the stale frame this must never hand over. In practice Chromium keeps a
      // tab that is being shared drawn and visible: measured 2026-10-07 on
      // Chromium 147, frames kept arriving at the requested rate with another
      // tab in front and with the window minimised, for both methods. Should a
      // browser stop drawing it anyway, this is a look without a picture, said
      // as data; the measurements were taken from the live page and stand.
      if (typeof document !== 'undefined' && document.visibilityState !== 'visible') {
        return declined('no new picture while the tab was in the background; the measurements are current', how)
      }
      if (video.readyState < 2 || video.videoWidth === 0) {
        return declined('the shared stream has not produced a frame yet', how)
      }
      // THE TRACK, NOT THE VIDEO ELEMENT. The <video> here is 1×1 and
      // invisible, and Chromium throttles decode and presentation for a video
      // nobody can see — so what it HOLDS can be the first frame the stream
      // ever produced, long after the tab has moved on. Live 2026-09-29: four
      // looks across two runs each handed the model the pre-crop first frame
      // (the admin tab, "Visual check OFF", an empty chat) while the browser's
      // own measurement of the same look reported a fully loaded page; the
      // agent verified a footer change against a picture of its own chat
      // window. nextPresentedFrame gave up after 1.2s and drew what was there.
      //
      // ImageCapture.grabFrame() reads the most recent frame the TRACK carries,
      // with no video element in the way. The video path stays as the fallback
      // for a browser without it.
      let source: CanvasImageSource = video
      let frameSource: 'track' | 'video' = 'video'
      let bitmap: ImageBitmap | null = null
      const ImageCaptureCtor = (window as unknown as {
        ImageCapture?: new (t: MediaStreamTrack) => { grabFrame(): Promise<ImageBitmap> }
      }).ImageCapture
      if (typeof ImageCaptureCtor === 'function') {
        try {
          bitmap = await Promise.race([
            new ImageCaptureCtor(track).grabFrame(),
            new Promise<null>((r) => window.setTimeout(() => r(null), FRESH_FRAME_MS)),
          ])
        } catch {
          bitmap = null
        }
      }
      if (bitmap && bitmap.width > 0 && bitmap.height > 0) {
        source = bitmap
        frameSource = 'track'
      } else if (how === 'element' && typeof ImageCaptureCtor === 'function') {
        // An element that is not being drawn gives the track no frames at all,
        // and the <video> would still hold the last one it saw. That old frame
        // is exactly the picture this must never hand over.
        return declined('the page frame was not being drawn on this look, so there is no new picture; the measurements are current', how)
      } else {
        bitmap = null
        await nextPresentedFrame(video)
      }

      const vw = bitmap ? bitmap.width : video.videoWidth
      const vh = bitmap ? bitmap.height : video.videoHeight
      let sx = 0
      let sy = 0
      let sw = vw
      let sh = vh
      let region = SIGHT_REGION[how]

      if (!cropped) {
        // Not cropped at source. Only a TAB share has a defined mapping onto
        // this document's coordinates; a window or a whole screen does not, and
        // guessing would either frame the wrong thing or hand the model whatever
        // else the owner has open.
        const surface = (track.getSettings() as MediaTrackSettings & { displaySurface?: string }).displaySurface
        if (surface === 'monitor' || surface === 'window') {
          return declined(
            `the owner is sharing ${surface === 'monitor' ? 'their whole screen' : 'a window'} rather than this tab, so no frame was taken — ask them to choose "This tab" if they want you to see the page`,
            how,
          )
        }
        if (surface !== 'browser') {
          // The browser will not say what is being shared, so we cannot line the
          // preview up inside it. Refusing is the only honest move: a crop
          // computed against the wrong surface would hand over a picture of
          // something else entirely and call it the page.
          return declined(
            'this browser will not say what is being shared, and it cannot crop to the preview on its own, so no frame was taken',
            how,
          )
        }
        // WHICH TAB, NOT JUST "A TAB". Everything below maps THIS document's
        // coordinates onto the shared pixels, which is only meaningful if the
        // shared tab IS this document. `displaySurface: 'browser'` does not say
        // that, and a second tab in the same window passes every other check
        // here — same viewport, so the scale agreement holds — after which the
        // crop lands on whatever they had open and is handed over as the site.
        //
        // Unprovable is refused, never assumed: no Capture Handle support means
        // no frame from this path, which costs a fallback that only runs where
        // Region Capture is already missing.
        const isThisTab = capturedThisTab(track)
        if (isThisTab === false) {
          return declined(
            'the shared tab is not this one, so no frame was taken — the agent will not take a picture of another tab; ask the owner to share the Youami tab itself',
            how,
          )
        }
        if (isThisTab === null) {
          return declined(
            'this browser cannot confirm WHICH tab is being shared and cannot crop to the preview on its own, so no frame was taken rather than risk capturing another tab',
            how,
          )
        }
        const el = document.querySelector('.bb-preview-scaler') as HTMLElement | null
        const rect = el?.getBoundingClientRect()
        if (!rect || rect.width < 2 || rect.height < 2) {
          return declined('the preview panel was not on screen, so there was nothing to capture', how)
        }
        const scaleX = vw / window.innerWidth
        const scaleY = vh / window.innerHeight
        if (!Number.isFinite(scaleX) || !Number.isFinite(scaleY) || scaleX <= 0 || scaleY <= 0) {
          return declined('the shared view could not be lined up with this page, so no frame was taken', how)
        }
        if (Math.abs(scaleX - scaleY) / scaleX > SCALE_AGREEMENT) {
          return declined('the shared view does not match this tab, so a frame would have shown the wrong thing', how)
        }
        sx = Math.max(0, Math.round(rect.left * scaleX))
        sy = Math.max(0, Math.round(rect.top * scaleY))
        sw = Math.min(vw - sx, Math.round(rect.width * scaleX))
        sh = Math.min(vh - sy, Math.round(rect.height * scaleY))
        if (sw < 2 || sh < 2) {
          return declined('the preview panel was off the visible area of the shared tab, so no frame was taken', how)
        }
      }

      // THE PICTURE IS THE SCREEN'S WIDTH. A 390px phone frame captured on a
      // Retina panel was 2x, then fitted to MAX_EDGE on its height: 507x1100,
      // and the agent reported "mobile (507px width)" (round 8). The frame's
      // own layout width (the iframe is rendered at the true viewport and only
      // scaled for display) caps the picture, so mobile is 390 wide.
      const frameCssWidth = (document.querySelector('.bb-preview-frame') as HTMLElement | null)?.offsetWidth ?? 0
      const fit = Math.min(1, MAX_EDGE / Math.max(sw, sh), frameCssWidth > 0 ? frameCssWidth / sw : 1)
      const outW = Math.max(1, Math.round(sw * fit))
      const outH = Math.max(1, Math.round(sh * fit))
      try {
        const canvas = document.createElement('canvas')
        canvas.width = outW
        canvas.height = outH
        const ctx = canvas.getContext('2d')
        if (!ctx) return declined('this browser would not give the panel a canvas, so no frame was taken', how)
        ctx.drawImage(source, sx, sy, sw, sh, 0, 0, outW, outH)
        bitmap?.close()
        bitmap = null
        const url = canvas.toDataURL('image/jpeg', JPEG_QUALITY)
        const base64 = url.slice(url.indexOf(',') + 1)
        if (!base64) return declined('the frame could not be encoded, so this look is measurements only', how)
        // FNV-1a over the base64: cheap, and equality is all anyone reads.
        let h = 0x811c9dc5
        for (let i = 0; i < base64.length; i++) {
          h ^= base64.charCodeAt(i)
          h = Math.imul(h, 0x01000193) >>> 0
        }
        const frameHash = h.toString(16).padStart(8, '0')
        const repeatOfPreviousLook = lastFrameHash === frameHash
        lastFrameHash = frameHash
        return {
          captured: true,
          width: outW,
          height: outH,
          region,
          base64,
          mediaType: 'image/jpeg',
          frameHash,
          repeatOfPreviousLook,
          frameSource,
          method: how,
        }
      } catch (e) {
        bitmap?.close()
        return declined(
          `the frame could not be read back (${e instanceof Error ? e.message : 'unknown error'}), so this look is measurements only`,
          how,
        )
      }
    },
  }
}
