/** Suggested questions for a translated language: plain labels as simple chips. */
import { useEffect, useState } from 'react'
import { MAX_QUESTIONS, QUESTION_MAX_LENGTH } from '@/lib/widgetCopy'

interface QuestionChipsProps {
  value: readonly string[]
  lang: string
  onCommit: (next: string[]) => void
}

const clean = (list: readonly string[]): string[] => list.map((q) => q.trim()).filter((q) => q !== '')

export function QuestionChips({ value, lang, onCommit }: QuestionChipsProps) {
  const [items, setItems] = useState<string[]>([...value])
  const key = value.join('\u0001')
  useEffect(() => setItems([...value]), [key]) // eslint-disable-line react-hooks/exhaustive-deps

  const commit = (list: string[]) => {
    const next = clean(list)
    if (next.join('\u0001') !== key) onCommit(next)
  }
  const edit = (i: number, text: string) => setItems(items.map((q, n) => (n === i ? text : q)))
  const remove = (i: number) => {
    const next = items.filter((_, n) => n !== i)
    setItems(next)
    commit(next)
  }

  return (
    <div className="bb-qchips">
      {items.map((q, i) => (
        <span className="bb-qchip" key={i}>
          <input
            className="bb-qchip-input"
            aria-label={`Suggested question ${i + 1}`}
            lang={lang}
            dir="auto"
            size={Math.max(6, q.length + 1)}
            value={q}
            maxLength={QUESTION_MAX_LENGTH}
            onChange={(e) => edit(i, e.target.value)}
            onBlur={() => commit(items)}
          />
          <button type="button" className="bb-qchip-x" aria-label="Remove question" onClick={() => remove(i)}>×</button>
        </span>
      ))}
      {items.length < MAX_QUESTIONS && (
        <button type="button" className="bb-qchip bb-qchip--add" onClick={() => setItems([...items, ''])}>+ Add</button>
      )}
    </div>
  )
}
