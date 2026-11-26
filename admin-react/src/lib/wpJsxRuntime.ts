// JSX runtime shim over WordPress's own React (window.React). Keeps the admin
// bundle free of an inlined React and independent of the `react-jsx-runtime`
// script handle, which older supported WordPress versions do not register.
import { createElement, Fragment } from 'react'

type Props = Record<string, unknown> | null

export { Fragment }

export function jsx(type: unknown, props: Props, key?: unknown) {
  const next = key === undefined ? props : { ...props, key }
  return createElement(type as never, next as never)
}

export const jsxs = jsx
export const jsxDEV = jsx
