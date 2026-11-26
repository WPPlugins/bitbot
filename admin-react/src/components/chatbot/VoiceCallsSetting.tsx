import type { AdminSettings } from '@/api/types'
/**
 * Voice calls feature row (per site, default off). When on, the chat widget
 * shows a Call button; callers are answered by this site's Youami. No key or
 * account is needed: the minutes come from the plan's included credits. The
 * switch and voice picker are plan gated.
 */
import { VoicePicker } from './VoicePicker'
import { FeatureRow } from './FeatureRow'
import { TierGate } from './TierGate'
import { useVoiceCalls, useSetVoiceCalls } from '@/hooks/useVoiceCalls'
import { voiceCallsHelp } from '@/lib/voiceCallsCopy'

export function VoiceCallsSetting({ settings }: { settings: AdminSettings }) {
  const { data, isLoading } = useVoiceCalls()
  const setEnabled = useSetVoiceCalls()
  const enabled = !!data?.enabled
  const unavailable = !!data && !data.available

  return (
    <TierGate required="assistant" feature="Voice calls">
      <FeatureRow
        title="Voice calls"
        description={voiceCallsHelp({ available: !unavailable })}
        on={enabled}
        onToggle={(next) => setEnabled.mutate(next)}
        disabled={isLoading || (unavailable && !enabled)}
        pill={enabled ? 'On' : 'Off'}
        pillTone={enabled ? 'on' : 'off'}
      >
        {enabled && <VoicePicker settings={settings} />}
      </FeatureRow>
    </TierGate>
  )
}
