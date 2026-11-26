/**
 * Sanitizer for the Drafts body preview.
 *
 * The preview shows post HTML inside wp-admin. A post that lives in WordPress can have been edited by
 * anyone allowed to save unfiltered HTML, and shortcodes can emit active markup, so the rendered body is
 * NOT safe to drop into the admin page as is. This keeps reading markup only: no scripts, no event
 * handlers, no styles (inline or <style>), no forms, frames or embeds. Links take only http(s), mailto and
 * relative addresses; an <img> may also carry a data: address (the sanitizer always allows that on images;
 * an image cannot run script, and inline SVG is removed). The instance is passed in so the same rules can be tested outside a browser.
 */

/** Tags a blog post body needs to be readable. Everything else is dropped (its text is kept). */
export const PREVIEW_TAGS = [
  'p', 'br', 'hr', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
  'strong', 'b', 'em', 'i', 'u', 's', 'sub', 'sup', 'small', 'mark', 'span', 'div',
  'a', 'ul', 'ol', 'li', 'blockquote', 'cite', 'q', 'code', 'pre', 'kbd',
  'img', 'figure', 'figcaption',
  'table', 'thead', 'tbody', 'tfoot', 'tr', 'th', 'td', 'caption',
]

/** No class, id or style: nothing in the preview may hook the admin page's own CSS or scripts. */
export const PREVIEW_ATTRS = ['href', 'src', 'alt', 'title', 'width', 'height', 'colspan', 'rowspan']

export const PREVIEW_CONFIG = {
  ALLOWED_TAGS: PREVIEW_TAGS,
  ALLOWED_ATTR: PREVIEW_ATTRS,
  ALLOW_DATA_ATTR: false,
  ALLOW_ARIA_ATTR: false,
  // Whole subtrees that must not leave their text behind either.
  FORBID_CONTENTS: ['script', 'style', 'iframe', 'object', 'embed', 'form', 'template', 'noscript', 'svg', 'math'],
  ALLOWED_URI_REGEXP: /^(?:https?:|mailto:|\/|#|[^a-z]|[a-z+.-]+(?:[^a-z+.\-:]|$))/i,
}

export interface Purifier {
  sanitize: (html: string, config: typeof PREVIEW_CONFIG) => string
}

/** Post HTML made safe to show inside wp-admin. */
export function sanitizePostPreview(purify: Purifier, html: string): string {
  return purify.sanitize(html, PREVIEW_CONFIG)
}
