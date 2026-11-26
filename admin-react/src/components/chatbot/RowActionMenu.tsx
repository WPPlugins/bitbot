import { createPortal } from 'react-dom'
import type { PageInfo } from '@/api/types'
import { pageState, rowCells } from '@/lib/pagesStatus'

/** Gap between the trigger and the menu, and the tallest the menu gets (three items). */
const MENU_GAP = 4
const MENU_MAX_HEIGHT = 140

/**
 * RowActionMenu — portaled to <body> so it isn't clipped by the parent
 * Card's overflow:hidden. Positions itself relative to the trigger's
 * viewport coordinates (fixed-positioned, right-aligned to the anchor).
 * Wrapped in .bb-portal-scope so the design tokens propagate.
 */
export function RowActionMenu({
  anchor,
  page,
  onClose,
  onResync,
  onToggle,
  onRemove,
  resyncing,
  toggling,
  removing,
}: {
  anchor: DOMRect
  page: PageInfo
  onClose: () => void
  onResync: () => void
  onToggle: () => void
  onRemove: () => void
  resyncing: boolean
  toggling: boolean
  removing: boolean
}) {
  // Right-align the menu against the trigger's right edge so it grows
  // leftward into the table rather than off-screen.
  const cells = rowCells(pageState(page))
  const right = window.innerWidth - anchor.right
  // Open upward when the menu would run past the bottom of the window.
  const opensUp = anchor.bottom + MENU_GAP + MENU_MAX_HEIGHT > window.innerHeight
  const place = opensUp ? { top: 'auto', bottom: window.innerHeight - anchor.top + MENU_GAP } : { top: anchor.bottom + MENU_GAP }
  return createPortal(
    <div className="bb-portal-scope">
      <div className="bb-pages-action-backdrop" onClick={onClose} />
      <div
        className="bb-pages-action-menu bb-pages-action-menu-portaled"
        role="menu"
        style={{ position: 'fixed', right, ...place }}
      >
        {cells.menu.resync && <button type="button" role="menuitem" onClick={onResync} disabled={resyncing}>Resync</button>}
        {cells.menu.toggle && (
          <button type="button" role="menuitem" onClick={onToggle} disabled={toggling}>
            {page.enabled ? 'Turn off' : 'Turn on'}
          </button>
        )}
        <button
          type="button"
          role="menuitem"
          className="bb-pages-action-danger"
          onClick={onRemove}
          disabled={removing}
        >Remove</button>
      </div>
    </div>,
    document.body,
  )
}

