/**
 * AvatarCropModal — opens after the admin picks an avatar image from
 * the WordPress Media Library (or clicks "Adjust crop") and lets them
 * pan + zoom inside a round frame (the round launcher and header circle).
 *
 * 2.0.2 (refactor): save the SOURCE-IMAGE crop rect (in pixels, plus
 * the source's natural dimensions) instead of a CSS transform. The
 * renderer then uses `background-image` with computed `background-size`
 * and `background-position` to display exactly that rect filling the
 * FAB. This works at zoom 1 (free pan within object-fit:cover's
 * vertical slack) and never exposes the FAB's brand colour because
 * the rendered image is sized to cover whatever rect the admin chose.
 *
 * Backward compatibility: parseAvatarTransform tolerates the legacy
 * { xPercent, yPercent, zoom } shape and returns null for it — the
 * renderer treats that as "no transform" and falls back to default
 * object-fit cover. Admins re-cropping migrates to the new shape.
 */
import { useState, useCallback, useEffect } from 'react'
import { createPortal } from 'react-dom'
import Cropper from 'react-easy-crop'
import type { Area, Point } from 'react-easy-crop'

export interface AvatarTransform {
  shape: 'rounded'
  /** Crop rect in source-image pixels — exactly what visitors see. */
  cropX: number
  cropY: number
  cropW: number
  cropH: number
  /** Source image's natural dimensions; render-time math needs these
   *  to compute background-size and background-position. */
  sourceW: number
  sourceH: number
}

interface AvatarCropModalProps {
  imageUrl: string
  initial?: AvatarTransform | null
  onSave: (transform: AvatarTransform) => void
  onCancel: () => void
}

const CROPPER_SIZE = 320

const ASPECT = 1

export function AvatarCropModal({ imageUrl, initial, onSave, onCancel }: AvatarCropModalProps) {
  const stageW = CROPPER_SIZE
  const stageH = CROPPER_SIZE

  // Seed pan/zoom from a previous matching-shape crop. Legacy
  // { xPercent, yPercent, zoom } values don't survive the format
  // change cleanly, so the admin re-cropping always starts fresh.
  const [crop, setCrop] = useState<Point>({ x: 0, y: 0 })
  const [zoom, setZoom] = useState<number>(1)
  const [croppedAreaPixels, setCroppedAreaPixels] = useState<Area | null>(null)
  // Source naturals captured on image load; needed to convert the
  // cropper's source-pixel rect into the render-time bg-size/position.
  const [sourceDims, setSourceDims] = useState<{ w: number; h: number } | null>(null)

  useEffect(() => {
    if (!imageUrl) return
    const img = new Image()
    img.onload = () => {
      if (img.naturalWidth > 0 && img.naturalHeight > 0) {
        setSourceDims({ w: img.naturalWidth, h: img.naturalHeight })
      }
    }
    img.src = imageUrl
  }, [imageUrl])

  // Pre-seed cropper position so re-editing an existing saved crop
  // lands roughly where the admin left it. We only have source-pixel
  // coords saved, and the cropper works in container-pixel coords —
  // close-enough heuristic: convert center of saved rect to a relative
  // position within the stage and seed crop there. Zoom is computed
  // from the stored crop width relative to source width: zoom ≈ sourceW/cropW.
  useEffect(() => {
    if (!initial || !sourceDims) return
    if (initial.shape !== 'rounded') return
    const z = Math.max(1, Math.min(4, sourceDims.w / Math.max(1, initial.cropW)))
    setZoom(z)
    // Seed crop offset so the saved rect's center maps to the stage's center.
    // Cropper's coord system: crop is the image's translation from its
    // centered position. We invert the calculation.
    // For now, leave crop at 0 — react-easy-crop will compute a stable
    // initial position from zoom alone. Admin can fine-tune by dragging.
  }, [initial, sourceDims])

  const onCropComplete = useCallback((_area: Area, areaPixels: Area) => {
    setCroppedAreaPixels(areaPixels)
  }, [])

  const handleSave = () => {
    if (!sourceDims || !croppedAreaPixels) {
      onCancel()
      return
    }
    onSave({
      shape: 'rounded',
      cropX: Math.round(croppedAreaPixels.x),
      cropY: Math.round(croppedAreaPixels.y),
      cropW: Math.round(croppedAreaPixels.width),
      cropH: Math.round(croppedAreaPixels.height),
      sourceW: sourceDims.w,
      sourceH: sourceDims.h,
    })
  }

  const handleReset = () => {
    setCrop({ x: 0, y: 0 })
    setZoom(1)
  }

  // Portal to <body> so the position:fixed overlay actually escapes
  // any transformed/filtered ancestor (Card, WP admin chrome) which
  // would otherwise clip the modal to the parent's bounds.
  // `.bb-portal-scope` re-establishes the CSS design tokens that are
  // normally scoped to #bitbot-admin-root.
  return createPortal((
    <div
      className="bb-portal-scope bb-avatar-crop-modal-overlay"
      onClick={(e) => { if (e.target === e.currentTarget) onCancel() }}
      role="dialog"
      aria-modal="true"
      aria-label="Adjust avatar crop"
    >
      <div className="bb-avatar-crop-modal">
        <header className="bb-avatar-crop-head">
          <h3>Frame your avatar</h3>
          <p>Drag to reposition, slider to zoom.</p>
        </header>

        <div
          className="bb-avatar-crop-stage"
          style={{ width: stageW, height: stageH }}
        >
          <Cropper
            image={imageUrl}
            crop={crop}
            zoom={zoom}
            aspect={ASPECT}
            cropShape="round"
            showGrid={false}
            minZoom={1}
            maxZoom={4}
            zoomSpeed={0.5}
            onCropChange={setCrop}
            onZoomChange={setZoom}
            onCropComplete={onCropComplete}
            objectFit="cover"
          />
        </div>

        <div className="bb-avatar-crop-zoom">
          <label>
            <span>Zoom</span>
            <input
              type="range"
              min={1}
              max={4}
              step={0.05}
              value={zoom}
              onChange={(e) => setZoom(Number(e.target.value))}
              className="bb-slider"
            />
          </label>
          <button type="button" className="bb-link-btn" onClick={handleReset}>Reset</button>
        </div>

        <footer className="bb-avatar-crop-actions">
          <button type="button" className="bb-btn bb-btn-ghost" onClick={onCancel}>Cancel</button>
          <button type="button" className="bb-btn bb-btn-primary" onClick={handleSave}>Use this crop</button>
        </footer>
      </div>
    </div>
  ), document.body)
}

/** Parse stored JSON transform. Returns null for the legacy
 *  { xPercent, yPercent, zoom } shape OR for invalid/missing values —
 *  callers fall back to plain object-fit cover when null. */
export function parseAvatarTransform(raw: string | null | undefined): AvatarTransform | null {
  if (!raw) return null
  try {
    const obj = JSON.parse(raw)
    if (typeof obj !== 'object' || obj === null) return null
    // New shape: needs cropW/cropH + sourceW/sourceH.
    if (
      typeof obj.cropW !== 'number' || typeof obj.cropH !== 'number' ||
      typeof obj.sourceW !== 'number' || typeof obj.sourceH !== 'number' ||
      obj.cropW <= 0 || obj.cropH <= 0 || obj.sourceW <= 0 || obj.sourceH <= 0
    ) {
      return null
    }
    // A 4:1 crop saved for the retired Pill frame is unusable now.
    if (obj.shape === 'pill') return null
    return {
      shape: 'rounded',
      cropX:   Number(obj.cropX ?? 0),
      cropY:   Number(obj.cropY ?? 0),
      cropW:   Number(obj.cropW),
      cropH:   Number(obj.cropH),
      sourceW: Number(obj.sourceW),
      sourceH: Number(obj.sourceH),
    }
  } catch {
    return null
  }
}

/**
 * Compute CSS `background-size` and `background-position` for the
 * given transform, sized to fit a `container` (in pixels). Returns
 * null when there is no usable transform (caller falls back to plain
 * object-fit cover).
 */
export function transformToBackground(
  t: AvatarTransform | null,
  containerW: number,
  containerH: number,
): { backgroundSize: string; backgroundPosition: string } | null {
  if (!t) return null
  if (t.shape !== 'rounded') return null
  if (t.cropW <= 0 || t.cropH <= 0) return null
  // Scale that makes the cropped rect fill the container.
  // Cropper aspect matches container aspect per SHAPE_GEOMETRY, so
  // scaleX === scaleY in practice; if they drift slightly we honour
  // both axes for safety.
  const scaleX = containerW / t.cropW
  const scaleY = containerH / t.cropH
  const bgW = t.sourceW * scaleX
  const bgH = t.sourceH * scaleY
  const bgX = -t.cropX * scaleX
  const bgY = -t.cropY * scaleY
  return {
    backgroundSize: `${bgW}px ${bgH}px`,
    backgroundPosition: `${bgX}px ${bgY}px`,
  }
}
