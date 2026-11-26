/**
 * Agent tab — chat-first surface for Pro+ admins.
 *
 * Branches on plan:
 *   - Pro+        → render the full AgentSurface (chat + drawers)
 *   - everyone else → render AgentLockedPitch (editorial pitch + CTA)
 *
 * No ProGate wrapper here. The generic ProGate's "Feature unlocks on
 * Tier" copy reads awkwardly when feature and tier are both "Agent",
 * and the popup-over-faded-UI pattern feels like a paywall. The pitch
 * component is the lock-state UI.
 */
import { AgentSurface } from '@/components/integrations/AgentSurface'
import { AgentLockedPitch } from '@/components/integrations/AgentLockedPitch'
import { isAgent } from '@/lib/config'
import { useCurrentPlan } from '@/hooks/useCurrentPlan'

export function Integrations() {
  const onAgent = isAgent(useCurrentPlan())

  if (!onAgent) {
    return <AgentLockedPitch />
  }

  return <AgentSurface />
}
