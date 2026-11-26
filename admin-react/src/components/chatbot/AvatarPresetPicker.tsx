/** Small modal to pick one of the shipped preset avatars. */
import { createPortal } from 'react-dom'
import { openMediaLibrary } from './MediaUploadButton'
import { AVATAR_PRESETS, avatarPresetUrl } from '@/lib/avatarPresets'

interface AvatarPresetPickerProps {
  assetsUrl: string | undefined
  currentUrl: string
  onPick: (url: string) => void
  /** A media-library image was chosen; the parent saves it and opens the crop. */
  onUploaded: (url: string) => void
  onCancel: () => void
}

export function AvatarPresetPicker({ assetsUrl, currentUrl, onPick, onUploaded, onCancel }: AvatarPresetPickerProps) {
  return createPortal((
    <div
      className="bb-portal-scope bb-avatar-crop-modal-overlay"
      onClick={(e) => { if (e.target === e.currentTarget) onCancel() }}
      onKeyDown={(e) => { if (e.key === 'Escape') onCancel() }}
      role="dialog"
      aria-modal="true"
      aria-label="Choose an avatar"
    >
      <div className="bb-avatar-crop-modal bb-avatar-picker">
        <header className="bb-avatar-crop-head">
          <h3>Choose an avatar</h3>
        </header>
        <div className="bb-avatar-picker-grid">
          {AVATAR_PRESETS.map((preset) => {
            const url = avatarPresetUrl(assetsUrl, preset.file)
            return (
              <button
                type="button"
                key={preset.file}
                className={`bb-avatar-picker-tile ${url === currentUrl ? 'bb-avatar-picker-tile-active' : ''}`}
                aria-pressed={url === currentUrl}
                aria-label={preset.label}
                onClick={() => onPick(url)}
              >
                <img src={url} alt="" width={64} height={64} />
              </button>
            )
          })}
          <button
            type="button"
            className="bb-avatar-picker-tile bb-avatar-picker-upload"
            aria-label="Upload your own"
            onClick={() => openMediaLibrary({ accept: 'image', onSelect: (url) => onUploaded(url) })}
          >
            <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden="true">
              <path d="M12 16V4M7 9l5-5 5 5M5 20h14" />
            </svg>
            <span>Upload your own</span>
          </button>
        </div>
        <footer className="bb-avatar-crop-actions">
          <button type="button" className="bb-btn bb-btn-ghost" onClick={onCancel}>Cancel</button>
        </footer>
      </div>
    </div>
  ), document.body)
}
