/**
 * Integrations drawer body — slide-over catalogue grouped by category.
 *
 * Single source of truth: the backend GetIntegrationsCatalog RPC (served
 * from integrations/<id>/integration.json manifests) via
 * useIntegrationsCatalog. BOTH the integration list and the category list
 * come from that data — categories are derived from the manifests in
 * first-appearance order, never a hardcoded array. There is NO fallback:
 * if the catalog is loading or fails, we show that, never a stale duplicate.
 *
 * Per-card connection status comes from each connector's own status query;
 * a card is "wireable" (has a configure flow) when its manifest declares a
 * real auth type. New connectors slot in by dropping a manifest + adding
 * their status query + a configure pane branch below.
 */
import { useState } from 'react'
import { useIntegrationsCatalog, type IntegrationManifest } from '@/hooks/useIntegrationsCatalog'
import { useSearchConsoleIntegration } from '@/hooks/useSearchConsole'
import { useWooCommerceStatus } from '@/hooks/useWooCommerce'
import { useZohoIntegration } from '@/hooks/useZoho'
import { useMetaIntegration } from '@/hooks/useMeta'
import { usePipedriveStatus } from '@/hooks/usePipedrive'
import { SearchConsoleConfigure } from './SearchConsoleConfigure'
import { WooCommerceConfigure } from './WooCommerceConfigure'
import { ZohoConfigure } from './ZohoConfigure'
import { PipedriveConfigure } from './PipedriveConfigure'
import { BrandLogo } from './BrandLogo'
import { MetaConfigure } from './MetaConfigure'
import { SupportInboxConfigure } from './SupportInboxConfigure'

type ConnectionState = 'connected' | 'available'

function StatusPill({ connection, comingSoon }: { connection: ConnectionState; comingSoon?: boolean }) {
  if (comingSoon) {
    return <span className="bb-int-status bb-int-status-coming-soon">Coming soon</span>
  }
  const label = connection === 'connected' ? 'Connected' : 'Available'
  return <span className={`bb-int-status bb-int-status-${connection}`}>{label}</span>
}

export function IntegrationsDrawerBody() {
  const [configuring, setConfiguring] = useState<string | null>(null)
  const catalog = useIntegrationsCatalog()
  const sc = useSearchConsoleIntegration()
  const wc = useWooCommerceStatus()
  const zoho = useZohoIntegration()
  const meta = useMetaIntegration()
  const pd = usePipedriveStatus()

  // Live connection status for the integrations that have backing state.
  // Keyed by manifest id; missing → 'available'.
  const liveStatusById: Record<string, ConnectionState> = {
    'search-console': sc.data?.integration?.status === 'connected' ? 'connected' : 'available',
    'woocommerce': wc.data?.configured ? 'connected' : 'available',
    'zoho-crm': zoho.data?.integration?.status === 'connected' ? 'connected' : 'available',
    'messenger': meta.data?.integration?.status === 'connected' ? 'connected' : 'available',
    'pipedrive': pd.data?.configured ? 'connected' : 'available',
  }

  if (configuring === 'search-console') {
    return <SearchConsoleConfigure onClose={() => setConfiguring(null)} />
  }
  if (configuring === 'woocommerce') {
    return <WooCommerceConfigure onClose={() => setConfiguring(null)} />
  }
  if (configuring === 'zoho-crm') {
    return <ZohoConfigure onClose={() => setConfiguring(null)} />
  }
  if (configuring === 'pipedrive') {
    return <PipedriveConfigure onClose={() => setConfiguring(null)} />
  }
  if (configuring === 'messenger') {
    return <MetaConfigure onClose={() => setConfiguring(null)} />
  }
  if (configuring === 'support-inbox') {
    return <SupportInboxConfigure onClose={() => setConfiguring(null)} />
  }

  if (catalog.isLoading) {
    return (
      <div className="bb-int-drawer">
        <p className="bb-int-drawer-intro">Loading integrations…</p>
      </div>
    )
  }

  if (catalog.isError || !catalog.data) {
    return (
      <div className="bb-int-drawer">
        <p className="bb-int-drawer-intro">
          Couldn&apos;t load integrations.{' '}
          <button className="bb-link-btn" onClick={() => catalog.refetch()}>
            Retry
          </button>
        </p>
      </div>
    )
  }

  const integrations: IntegrationManifest[] = catalog.data
  // Categories are derived from the catalog data (first-appearance order),
  // never hardcoded — drop a manifest in a new category and it appears.
  const categories = Array.from(new Set(integrations.map((i) => i.category)))

  return (
    <div className="bb-int-drawer">
      <p className="bb-int-drawer-intro">
        Each integration extends what the bot can do in conversations. Turn the
        ones you need on; the rest stay quiet.
      </p>

      {categories.map((cat) => {
        const items = integrations.filter((i) => i.category === cat)
        if (items.length === 0) return null
        return (
          <section key={cat} className="bb-int-section bb-int-section-drawer">
            <header className="bb-int-section-head">
              <h3 className="bb-int-section-title">{cat}</h3>
              <span className="bb-int-section-count">{items.length}</span>
            </header>
            <div className="bb-int-grid bb-int-grid-drawer">
              {items.map((it) => {
                const comingSoon = it.comingSoon
                const connection = liveStatusById[it.id] ?? 'available'
                // Wireable = has a real configure flow. Derived from the
                // manifest's auth type, not a hardcoded id list.
                const wireable = !comingSoon && it.auth !== 'none'
                return (
                  <article key={it.id} className="bb-int-card">
                    <div className="bb-int-head">
                      <span className="bb-int-icon" aria-hidden><BrandLogo id={it.id} /></span>
                      <div className="bb-int-titles">
                        <h4 className="bb-int-name">{it.name}</h4>
                        <span className="bb-int-cat">{it.category}</span>
                      </div>
                      <StatusPill connection={connection} comingSoon={comingSoon} />
                    </div>
                    <p className="bb-int-body">{it.description}</p>
                    <div className="bb-int-actions">
                      {comingSoon ? (
                        <button className="bb-btn bb-btn-ghost bb-btn-small" disabled>
                          Coming soon
                        </button>
                      ) : it.id === 'support-inbox' ? (
                        <button
                          className="bb-btn bb-btn-ghost bb-btn-small"
                          onClick={() => setConfiguring(it.id)}
                        >
                          Learn more →
                        </button>
                      ) : connection === 'connected' ? (
                        <button
                          className="bb-btn bb-btn-ghost bb-btn-small"
                          onClick={() => wireable && setConfiguring(it.id)}
                          disabled={!wireable}
                        >
                          Configure →
                        </button>
                      ) : (
                        <button
                          className="bb-btn bb-btn-primary bb-btn-small"
                          onClick={() => wireable && setConfiguring(it.id)}
                          disabled={!wireable}
                        >
                          Enable →
                        </button>
                      )}
                    </div>
                  </article>
                )
              })}
            </div>
          </section>
        )
      })}

      <div className="bb-int-suggest">
        <h4>Want more?</h4>
        <p>
          Need something not listed? Tell us — requested integrations ship
          within a sprint when there&apos;s clear demand.
        </p>
        <a className="bb-btn bb-btn-ghost bb-btn-small" href="mailto:support@youami.ai">
          Email support
        </a>
      </div>
    </div>
  )
}
