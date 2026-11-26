/** Card 3: Calls & WhatsApp. Two feature rows. */
import type { AdminSettings } from '@/api/types'
import { Card } from '@/components/ui/Card'
import { VoiceCallsSetting } from './VoiceCallsSetting'
import { WhatsAppSetting } from './WhatsAppSetting'

interface CallsCardProps {
  settings: AdminSettings
  save: (patch: Partial<AdminSettings>) => void
}

export function CallsCard({ settings, save }: CallsCardProps) {
  return (
    <Card title="Calls & WhatsApp" subtitle="Extra ways for visitors to reach you from the chat.">
      <VoiceCallsSetting settings={settings} />
      <WhatsAppSetting settings={settings} save={save} />
    </Card>
  )
}
