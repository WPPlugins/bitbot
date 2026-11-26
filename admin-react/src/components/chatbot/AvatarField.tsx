/** Avatar: thumbnail, choose a preset or upload your own, adjust the crop, clear. */
import { useState } from 'react'
import type { AdminSettings } from '@/api/types'
import { SettingField } from './SettingField'
import { AvatarPresetPicker } from './AvatarPresetPicker'
import { getConfig } from '@/lib/config'
import { parseAvatarTransform, transformToBackground } from './AvatarCropModal'

interface AvatarFieldProps {
  settings: AdminSettings
  save: (patch: Partial<AdminSettings>) => void
  onAdjustCrop: () => void
  onUploaded: (url: string) => void
}

export function AvatarField({ settings, save, onAdjustCrop, onUploaded }: AvatarFieldProps) {
  const [picking, setPicking] = useState(false)
  const thumbBg = transformToBackground(parseAvatarTransform(settings.avatarTransform), 36, 36)
  return (
    <SettingField label="Avatar" help="Drag and zoom to frame it after upload.">
      <div className="bb-avatar-row">
        {settings.avatarUrl && (
          <div className="bb-avatar-thumb-wrap">
            {thumbBg ? (
              <div
                className="bb-avatar-thumb"
                style={{
                  backgroundImage: `url(${settings.avatarUrl})`,
                  backgroundSize: thumbBg.backgroundSize,
                  backgroundPosition: thumbBg.backgroundPosition,
                  backgroundRepeat: 'no-repeat',
                }}
              />
            ) : (
              <img
                src={settings.avatarUrl}
                alt=""
                className="bb-avatar-thumb"
                onError={(e) => { (e.target as HTMLImageElement).style.display = 'none' }}
              />
            )}
          </div>
        )}
        <button type="button" className="bb-btn bb-btn-ghost bb-btn-small" onClick={() => setPicking(true)}>
          {settings.avatarUrl ? 'Change avatar' : 'Choose avatar'}
        </button>
        {settings.avatarUrl && (
          <>
            <button type="button" className="bb-link-btn" onClick={onAdjustCrop} title="Adjust how the avatar is framed">Adjust crop</button>
            <button type="button" className="bb-link-btn" onClick={() => save({ avatarUrl: '', avatarTransform: '' })} title="Clear avatar">Clear</button>
          </>
        )}
      </div>
      {picking && (
        <AvatarPresetPicker
          assetsUrl={getConfig().assetsUrl}
          currentUrl={settings.avatarUrl}
          onCancel={() => setPicking(false)}
          onPick={(url) => { save({ avatarUrl: url, avatarTransform: '' }); setPicking(false) }}
          onUploaded={(url) => { save({ avatarUrl: url, avatarTransform: '' }); setPicking(false); onUploaded(url) }}
        />
      )}
    </SettingField>
  )
}
