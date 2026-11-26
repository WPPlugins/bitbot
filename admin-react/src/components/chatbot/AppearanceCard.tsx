/**
 * Card 2: Appearance. Colour and avatar, chat button shape and position, and
 * the exact radius and offsets behind Advanced.
 */
import type { AdminSettings } from '@/api/types'
import { Card } from '@/components/ui/Card'
import { SettingField } from './SettingField'
import { AdvancedRow } from './AdvancedRow'
import { AvatarField } from './AvatarField'
import { radiusOffsetSummary } from '@/lib/chatbotPanels'
import { PILL_HEIGHT_MIN, PILL_HEIGHT_MAX, clampPillHeight } from '@/lib/pillButton'

interface AppearanceCardProps {
  settings: AdminSettings
  save: (patch: Partial<AdminSettings>) => void
  onAdjustCrop: () => void
  onUploaded: (url: string) => void
}

const PRESET_COLORS = ['#E84F2A', '#0073AA', '#16A34A', '#9333EA', '#0F1419']

interface Option<T extends string> {
  key: T
  label: string
}

function Segmented<T extends string>({ options, value, onPick, label }: {
  options: readonly Option<T>[]
  value: T | undefined
  onPick: (key: T) => void
  label: string
}) {
  return (
    <div className="bb-segmented" role="group" aria-label={label}>
      {options.map((o) => (
        <button
          type="button"
          key={o.key}
          className={`bb-seg ${value === o.key ? 'bb-seg-active' : ''}`}
          aria-pressed={value === o.key}
          onClick={() => onPick(o.key)}
        >{o.label}</button>
      ))}
    </div>
  )
}

// Circle, square and hexagon stay in the backend allow-list for older sites but are not offered here.
const SHAPES = [{ key: 'rounded', label: 'Rounded' }, { key: 'pill', label: 'Pill' }] as const
const POSITIONS = [{ key: 'bottom-left', label: 'Bottom left' }, { key: 'bottom-right', label: 'Bottom right' }] as const

function OffsetInput({ label, value, onChange }: { label: string; value: number; onChange: (n: number) => void }) {
  return (
    <label className="bb-offset-field">
      <span>{label}</span>
      <input
        type="number"
        className="bb-input bb-input-num"
        min={0}
        max={200}
        step={2}
        value={value}
        onChange={(e) => onChange(Math.max(0, Number(e.target.value)))}
      />
      <span className="bb-offset-unit">px</span>
    </label>
  )
}

export function AppearanceCard({ settings, save, onAdjustCrop, onUploaded }: AppearanceCardProps) {
  return (
    <Card title="Appearance" subtitle="How the widget looks on your pages.">
      <div className="bb-stack">
        <div className="bb-cols">
          <SettingField label="Colour">
            <div className="bb-color-picker">
              {PRESET_COLORS.map((hex) => (
                <button
                  type="button"
                  key={hex}
                  className={`bb-color-swatch ${settings.primaryColor.toLowerCase() === hex.toLowerCase() ? 'bb-color-swatch-active' : ''}`}
                  style={{ background: hex }}
                  onClick={() => save({ primaryColor: hex })}
                  aria-label={`Use ${hex}`}
                />
              ))}
              <input
                className="bb-input bb-color-hex"
                value={settings.primaryColor}
                onChange={(e) => save({ primaryColor: e.target.value })}
                placeholder="#0073aa"
                aria-label="Custom hex"
              />
            </div>
          </SettingField>
          <AvatarField settings={settings} save={save} onAdjustCrop={onAdjustCrop} onUploaded={onUploaded} />
        </div>

        <div className="bb-cols">
          <SettingField label="Chat button shape">
            <Segmented label="Chat button shape" options={SHAPES} value={settings.widgetShape as 'rounded' | 'pill'} onPick={(widgetShape) => save({ widgetShape })} />
            {settings.widgetShape === 'pill' && (
              <label className="bb-pill-height">
                <span>{`Button height: ${clampPillHeight(settings.pillHeight)}px`}</span>
                <input
                  type="range"
                  min={PILL_HEIGHT_MIN}
                  max={PILL_HEIGHT_MAX}
                  value={clampPillHeight(settings.pillHeight)}
                  onChange={(e) => save({ pillHeight: clampPillHeight(e.target.value) })}
                  className="bb-slider"
                />
              </label>
            )}
          </SettingField>
          <SettingField label="Position">
            <Segmented label="Position" options={POSITIONS} value={settings.widgetPosition} onPick={(widgetPosition) => save({ widgetPosition })} />
          </SettingField>
        </div>
      </div>

      <AdvancedRow
        label="Advanced: corner radius, offset from edge"
        summary={radiusOffsetSummary(settings.cornerRadius, settings.widgetOffsetX, settings.widgetOffsetY)}
      >
        <SettingField label={`Corner radius: ${settings.cornerRadius}px`} help="0 is sharp, 32 is fully rounded.">
          <input
            type="range"
            min={0}
            max={32}
            value={settings.cornerRadius}
            onChange={(e) => save({ cornerRadius: Number(e.target.value) })}
            className="bb-slider"
          />
        </SettingField>
        <SettingField label="Offset from edge" help="Raise it if another floating widget is in the way.">
          <div className="bb-offset-row">
            <OffsetInput label="Horizontal" value={settings.widgetOffsetX} onChange={(widgetOffsetX) => save({ widgetOffsetX })} />
            <OffsetInput label="Vertical" value={settings.widgetOffsetY} onChange={(widgetOffsetY) => save({ widgetOffsetY })} />
            <button type="button" className="bb-link-btn" onClick={() => save({ widgetOffsetX: 20, widgetOffsetY: 20 })} title="Reset to 20px / 20px">Reset</button>
          </div>
        </SettingField>
      </AdvancedRow>
    </Card>
  )
}
