/**
 * StageBadge — colored pill for a lead's stage. Used in the leads list
 * row and the detail panel header.
 */
import type { LeadStage } from '@/api/types'

const STAGE_LABEL: Record<LeadStage, string> = {
  new: 'New',
  contacted: 'Contacted',
  qualified: 'Qualified',
  won: 'Won',
  lost: 'Lost',
}

interface StageBadgeProps {
  stage: LeadStage
}

export function StageBadge({ stage }: StageBadgeProps) {
  return (
    <span className={`bb-stage-badge bb-stage-${stage}`}>
      {STAGE_LABEL[stage]}
    </span>
  )
}

export const STAGE_LABELS = STAGE_LABEL
export const STAGES: LeadStage[] = ['new', 'contacted', 'qualified', 'won', 'lost']
