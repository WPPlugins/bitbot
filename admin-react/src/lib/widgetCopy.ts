/**
 * Pure helpers for the per-language widget title and welcome message.
 * Mirrors the PHP BitBot_Widget_Copy: rows live in the bitbot_widget_copy
 * option as { code: { title, welcome, auto } }, the site language is the base
 * title and welcome message and never has a row.
 */
import type { LanguageOption } from './voiceLanguages.ts'

export interface WidgetCopyRow {
  title: string
  welcome: string
  /** Suggested questions as plain labels. */
  questions: string[]
  /** The input hint shown in the empty message box. */
  placeholder: string
  /** true while the row is a machine translation the owner has not edited. */
  auto: boolean
  /** The owner edited it and the site text has changed since. */
  needsReview: boolean
  /** The last translation attempt did not come back. */
  failed: boolean
}

export type WidgetCopyMap = Record<string, WidgetCopyRow>

/** Limits for the translated rows (the site-language row keeps the Branding limits). */
export const TRANSLATED_LIMITS = { title: 60, welcome: 400, placeholder: 50 } as const

export const MAX_QUESTIONS = 8
export const QUESTION_MAX_LENGTH = 80

/** The selected languages other than the site language, as catalogue rows (a code outside the catalogue shows as itself). */
export function otherLanguages(
  selected: readonly string[],
  site: string,
  catalogue: readonly LanguageOption[],
): LanguageOption[] {
  const byCode = new Map(catalogue.map((l) => [l.code, l]))
  return selected
    .filter((code) => code !== site)
    .map((code) => byCode.get(code) ?? { code, name: code, native: code })
}

/** The stored row for a language, or an empty owner row. */
export function rowFor(copy: Partial<Record<string, Partial<WidgetCopyRow>>> | undefined, code: string): WidgetCopyRow {
  const row = copy?.[code]
  return {
    title: row?.title ?? '',
    welcome: row?.welcome ?? '',
    questions: row?.questions ?? [],
    placeholder: row?.placeholder ?? '',
    auto: !!row?.auto,
    needsReview: !!row?.needsReview,
    failed: !!row?.failed,
  }
}

export interface CopyFields {
  title?: string
  welcome?: string
  questions?: string[]
  placeholder?: string
}

/** The POST body for one edited row (merge semantics server side). An empty fields object marks the row reviewed. */
export function copyPatch(code: string, fields: CopyFields): { widgetCopy: Record<string, CopyFields> } {
  return { widgetCopy: { [code]: fields } }
}

/** The POST body that asks the server to retranslate one language. */
export function regeneratePatch(code: string): { regenerateCopy: string[] } {
  return { regenerateCopy: [code] }
}

interface PreviewBase {
  widgetTitle: string
  welcomeMessage: string
  placeholderText?: string
  suggestedQuestions: Array<{ label: string; message: string }>
  widgetCopy?: Partial<Record<string, Partial<WidgetCopyRow>>>
}

/**
 * What the preview shows for one language: the site language is the base
 * settings; another language is its stored row, with an empty field falling
 * back to the site text (as the widget does).
 */
export function previewCopy(base: PreviewBase, code: string, site: string): Pick<PreviewBase, 'widgetTitle' | 'welcomeMessage' | 'suggestedQuestions' | 'placeholderText'> {
  if (code === site) return base
  const row = rowFor(base.widgetCopy, code)
  return {
    widgetTitle: row.title || base.widgetTitle,
    welcomeMessage: row.welcome || base.welcomeMessage,
    placeholderText: row.placeholder || base.placeholderText,
    suggestedQuestions: row.questions.length > 0
      ? row.questions.map((q) => ({ label: q, message: q }))
      : base.suggestedQuestions,
  }
}
