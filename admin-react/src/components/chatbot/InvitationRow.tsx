/** Invitation bubble (the nudge): switch, message and "Show after", plus a preview button. */
import type { AdminSettings } from '@/api/types'
import { FeatureRow } from './FeatureRow'
import { SettingField } from './SettingField'
import { showAfterOptions } from '@/lib/chatbotPanels'
import { DEFAULT_NUDGE_MESSAGE, nudgeWith, parseNudge } from '@/lib/nudge'

interface InvitationRowProps {
  settings: AdminSettings
  save: (patch: Partial<AdminSettings>) => void
}

export function InvitationRow({ settings, save }: InvitationRowProps) {
  const cfg = parseNudge(settings.nudge)
  const update = (patch: Parameters<typeof nudgeWith>[1]) => save({ nudge: nudgeWith(settings.nudge, patch) })
  const preview = () =>
    window.dispatchEvent(new CustomEvent('bb-preview-nudge', { detail: { message: cfg.message || DEFAULT_NUDGE_MESSAGE } }))

  return (
    <FeatureRow
      title="Invitation bubble"
      description="After the delay the bubble slides in beside the button and the button buzzes, once per visit."
      on={cfg.enabled}
      onToggle={(enabled) => update({ enabled })}
      action={<button type="button" className="bb-btn bb-btn-small" onClick={preview}>Preview invitation</button>}
    >
      {cfg.enabled && (
        <div className="bb-cols">
          <SettingField label="Message">
            <input
              className="bb-input"
              value={cfg.message}
              maxLength={120}
              placeholder={DEFAULT_NUDGE_MESSAGE}
              onChange={(e) => update({ message: e.target.value })}
            />
          </SettingField>
          <SettingField label="Show after">
            <div className="bb-segmented" role="group" aria-label="Show after">
              {showAfterOptions(cfg.delaySeconds).map((s) => (
                <button
                  type="button"
                  key={s}
                  className={`bb-seg ${cfg.delaySeconds === s ? 'bb-seg-active' : ''}`}
                  aria-pressed={cfg.delaySeconds === s}
                  onClick={() => update({ delaySeconds: s })}
                >{s} s</button>
              ))}
            </div>
          </SettingField>
        </div>
      )}
    </FeatureRow>
  )
}
