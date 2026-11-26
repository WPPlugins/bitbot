/**
 * WhatsApp click-to-chat feature row. The owner's number and a switch. When
 * both are set the widget shows a WhatsApp button next to the Call button that
 * opens wa.me in a new tab, and the bot's reply carries the same button when it
 * cannot answer or the visitor asks for a person. No WhatsApp account or API
 * is involved. Open to every plan.
 *
 * Turning it on reveals the number field. The button only goes live once the
 * number passes validation.
 */
import { useEffect, useState } from 'react'
import type { AdminSettings } from '@/api/types'
import { FeatureRow } from './FeatureRow'
import { SettingField } from './SettingField'
import { checkWhatsAppNumber } from '@/lib/whatsappNumber'
import { phoneExample } from '@/lib/phoneExample'
import { getConfig } from '@/lib/config'


interface Props {
  settings: AdminSettings
  save: (patch: Partial<AdminSettings>) => void
}

export function WhatsAppSetting({ settings, save }: Props) {
  const saved = settings.whatsappNumber ?? ''
  const enabled = !!settings.whatsappEnabled
  const [typed, setTyped] = useState(saved)
  const [error, setError] = useState<string | null>(null)
  // Switched on before a valid number exists: show the field, keep the button off.
  const [waiting, setWaiting] = useState(false)
  const { example } = phoneExample(getConfig().siteLocale)

  // Follow the stored value when it changes from outside this field.
  useEffect(() => setTyped(saved), [saved])

  const commit = () => {
    const check = checkWhatsAppNumber(typed)
    if (!check.ok) {
      setError(check.error)
      return
    }
    setError(null)
    setTyped(check.value)
    const patch: Partial<AdminSettings> = {}
    if (check.value !== saved) patch.whatsappNumber = check.value
    if (waiting && check.value !== '') {
      patch.whatsappEnabled = true
      setWaiting(false)
    }
    if (Object.keys(patch).length > 0) save(patch)
  }

  const toggle = (next: boolean) => {
    if (!next) {
      setWaiting(false)
      save({ whatsappEnabled: false })
    } else if (saved) {
      save({ whatsappEnabled: true })
    } else {
      setWaiting(true)
    }
  }

  const open = enabled || waiting
  return (
    <FeatureRow
      title="WhatsApp button"
      on={open}
      onToggle={toggle}
      pill={enabled ? 'On' : 'Off'}
      pillTone={enabled ? 'on' : 'off'}
    >
      {open && (
        <SettingField label="Your WhatsApp number" help={`Include the country code, for example ${example}.`}>
          <input
            className="bb-input bb-input--narrow"
            type="tel"
            inputMode="tel"
            autoComplete="off"
            aria-label="Your WhatsApp number"
            aria-invalid={error ? true : undefined}
            value={typed}
            maxLength={24}
            placeholder={example}
            onChange={(e) => {
              setTyped(e.target.value)
              if (error) setError(null)
            }}
            onBlur={commit}
            onKeyDown={(e) => {
              if (e.key === 'Enter') commit()
            }}
          />
          {error && (
            <span className="bb-field-counter bb-field-counter-over" role="alert">
              {error}
            </span>
          )}
        </SettingField>
      )}
    </FeatureRow>
  )
}
