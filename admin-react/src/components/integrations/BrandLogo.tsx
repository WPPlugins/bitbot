/**
 * Real brand logos for the integrations directory, keyed by integration id.
 *
 * Zoho CRM, WooCommerce, Pipedrive and Google Search Console use each vendor's
 * OFFICIAL icon — the exact square marks grabbed from their own sites/CDNs
 * (bundled under src/assets/integrations). Facebook Messenger uses the
 * simple-icons glyph in its brand blue. "AI Support Inbox" is our own feature
 * (no third-party brand), so it gets a neutral inbox glyph.
 */
import type { ReactNode } from 'react'
import { siMessenger } from 'simple-icons'
import searchConsoleLogo from '@/assets/integrations/search-console.png'
import woocommerceLogo from '@/assets/integrations/woocommerce.png'
import pipedriveLogo from '@/assets/integrations/pipedrive.png'
import zohoCrmLogo from '@/assets/integrations/zoho-crm.png'

const SIZE = 22

// integration id → official bundled icon image
const IMG: Record<string, string> = {
  'search-console': searchConsoleLogo,
  woocommerce: woocommerceLogo,
  pipedrive: pipedriveLogo,
  'zoho-crm': zohoCrmLogo,
}

export function BrandLogo({ id }: { id: string }) {
  const img = IMG[id]
  if (img) {
    return <img src={img} width={SIZE} height={SIZE} alt="" aria-hidden="true" style={{ objectFit: 'contain', borderRadius: 4 }} />
  }

  if (id === 'messenger') {
    return (
      <svg width={SIZE} height={SIZE} viewBox="0 0 24 24" role="img" aria-hidden="true" fill={`#${siMessenger.hex}`}>
        <path d={siMessenger.path} />
      </svg>
    )
  }

  // support-inbox (own feature) — neutral inbox glyph, not a brand.
  return (
    <svg width={SIZE} height={SIZE} viewBox="0 0 24 24" role="img" aria-hidden="true"
      fill="none" stroke="#6b6b6b" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
      <path d="M22 12h-6l-2 3h-4l-2-3H2" />
      <path d="M5.45 5.11 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.45-6.89A2 2 0 0 0 16.76 4H7.24a2 2 0 0 0-1.79 1.11z" />
    </svg>
  )
}

/**
 * Configure-pane heading = the integration's real logo next to its title.
 * Used by every *Configure component so the open card mirrors the directory.
 */
export function ConfigureTitle({ id, children }: { id: string; children: ReactNode }) {
  return (
    <span className="bb-int-configure-titlerow">
      <BrandLogo id={id} />
      <h3 className="bb-int-configure-title">{children}</h3>
    </span>
  )
}
