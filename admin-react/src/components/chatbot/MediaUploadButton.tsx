/**
 * MediaUploadButton — opens the WordPress Media Library modal so the
 * admin can pick or upload an image (or any file type passed via the
 * `accept` prop) and feed its public URL back into a settings field.
 *
 * Uses window.wp.media, which WordPress core ships and we enqueue via
 * `wp_enqueue_media()` in bitbot.php on the Youami admin page. If the
 * SDK isn't on the page (e.g. local Vite preview), the button falls
 * back to a hidden file input so devs can still test in the standalone
 * dev server.
 *
 * Returns just the URL via the `onSelect` callback — the parent owns
 * what to do with it (save to settings, paste into preview, etc.).
 */

import { toast } from '@/components/ui/Toast'

interface MediaItem {
  id: number
  url: string
  filename?: string
  /** WP-canonical short type: 'image', 'video', 'audio', etc. */
  type?: string
  mime?: string
  width?: number
  height?: number
}

interface PluploadFile {
  id: string
  name: string
  type?: string
}

interface PluploadInstance {
  bind: (event: string, handler: (up: PluploadInstance, files: PluploadFile[]) => void) => void
  removeFile: (file: PluploadFile) => void
  settings: {
    filters?: {
      mime_types?: Array<{ title: string; extensions: string }>
    }
  }
}

interface MediaFrame {
  on: (event: string, handler: () => void) => void
  open: () => void
  state: () => { get: (k: string) => { first: () => { toJSON: () => unknown } } }
  uploader?: { uploader?: { uploader?: PluploadInstance } }
}

declare global {
  interface Window {
    wp?: {
      media?: (config: {
        title?: string
        button?: { text: string }
        library?: { type?: string | string[] }
        multiple?: boolean
      }) => MediaFrame
    }
  }
}

/** WP rejects SVG uploads by default (XSS risk via embedded JS), so we don't
 *  advertise it. PDF, video, etc. get filtered out at the plupload queue. */
const ALLOWED_IMAGE_EXTS = 'png,jpg,jpeg,gif,webp'

interface MediaUploadButtonProps {
  /** Plain-English label for the modal title + the trigger button. */
  label?: string
  /** MIME-type filter, e.g. ['image']. Defaults to images. */
  accept?: string | string[]
  /** Called with the selected file's public URL. */
  onSelect: (url: string, item: MediaItem) => void
  /** Class name override for the trigger button. */
  className?: string
}

interface OpenMediaLibraryOptions {
  /** Modal title. */
  label?: string
  /** MIME-type filter, e.g. ['image']. Defaults to images. */
  accept?: string | string[]
  /** Called with the selected file's public URL. */
  onSelect: (url: string, item: MediaItem) => void
}

/** Open the WordPress Media Library (shared by the button and the avatar picker tile). */
export function openMediaLibrary({ label = 'Choose from Media Library', accept = 'image', onSelect }: OpenMediaLibraryOptions): void {
  if (typeof window === 'undefined' || !window.wp?.media) {
    // Fallback: WP media library isn't loaded (probably running in
    // Vite dev outside WordPress). Tell the user to paste the URL.
    alert('WP Media Library isn\'t available on this page. Paste the URL directly into the field instead.')
    return
  }
  const frame = window.wp.media({
    title: label,
    button: { text: 'Use this file' },
    library: { type: accept },
    multiple: false,
  })

  // WP's `library.type` only filters the Media Library *grid*. The
  // Upload Files tab still accepts anything the plupload uploader
  // allows — so if the user drops a PDF, WP silently queues it and
  // uploads it the next time anything kicks the queue (e.g. picking
  // a JPG). Restrict the uploader queue itself the moment it's ready
  // so non-images never even hit the server.
  frame.on('uploader:ready', () => {
    const up = frame.uploader?.uploader?.uploader
    if (!up) return
    if (up.settings.filters) {
      up.settings.filters.mime_types = [{ title: 'Images', extensions: ALLOWED_IMAGE_EXTS }]
    }
    up.bind('FilesAdded', (uploader, files) => {
      const rejected: PluploadFile[] = []
      for (const f of files) {
        const mime = (f.type ?? '').toLowerCase()
        if (!mime.startsWith('image/') || mime === 'image/svg+xml') {
          rejected.push(f)
          uploader.removeFile(f)
        }
      }
      if (rejected.length) {
        const names = rejected.map((f) => f.name).join(', ')
        toast.error(
          rejected.length === 1
            ? `"${names}" isn't an image — only PNG, JPG, GIF, and WebP are allowed.`
            : `${rejected.length} files skipped — only PNG, JPG, GIF, and WebP are allowed.`,
        )
      }
    })
  })

  frame.on('select', () => {
    const attachment = frame.state().get('selection').first().toJSON() as MediaItem
    if (!attachment?.url) return

    // Defense in depth: even if the upload filter is bypassed (e.g.
    // user picks an existing non-image from the Media Library), make
    // sure we never feed a non-image URL into an <img src>.
    const expectedTypes = Array.isArray(accept) ? accept : [accept]
    const mime = (attachment.mime ?? '').toLowerCase()
    const broadType = (attachment.type ?? mime.split('/')[0] ?? '').toLowerCase()
    const ok = expectedTypes.some((t) => t === broadType || mime.startsWith(`${t}/`))
    if (!ok) {
      toast.error(
        `That file is a ${broadType || 'non-image file'} — please choose an image (PNG, JPG, GIF, WebP).`,
      )
      return
    }
    onSelect(attachment.url, attachment)
  })
  frame.open()
}

export function MediaUploadButton({
  label = 'Choose from Media Library',
  accept = 'image',
  onSelect,
  className,
}: MediaUploadButtonProps) {
  return (
    <button
      type="button"
      className={className ?? 'bb-btn bb-btn-ghost bb-btn-small'}
      onClick={() => openMediaLibrary({ label, accept, onSelect })}
    >
      Upload
    </button>
  )
}
