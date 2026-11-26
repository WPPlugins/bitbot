import { defineConfig, type Plugin } from 'vite'
import react from '@vitejs/plugin-react'
import path from 'node:path'
import { fileURLToPath } from 'node:url'
import * as ReactNS from 'react'
import * as ReactDomNS from 'react-dom'
import * as ReactDomClientNS from 'react-dom/client'

const __dirname = path.dirname(fileURLToPath(import.meta.url))

// React and ReactDOM come from WordPress itself (script handles `react` and
// `react-dom`, exposed as window.React / window.ReactDOM), so the bundle does
// not inline its own copy. Imports of react, react-dom, react-dom/client and
// react/jsx-runtime resolve to tiny virtual modules that re-export the globals.
// The named exports are taken from the installed packages so every import a
// dependency makes is satisfied. The JSX runtime is a local shim over
// React.createElement so it also works on WordPress versions that predate the
// `react-jsx-runtime` handle (added in 6.6).
const VIRTUAL_PREFIX = '\0wp-global:'
const wpGlobalModules: Record<string, { global: string; names: string[] }> = {
  react: { global: 'window.React', names: Object.keys(ReactNS) },
  'react-dom': { global: 'window.ReactDOM', names: Object.keys(ReactDomNS) },
  'react-dom/client': { global: 'window.ReactDOM', names: Object.keys(ReactDomClientNS) },
}
function wpGlobalsPlugin(): Plugin {
  return {
    name: 'bitbot-wp-global-react',
    enforce: 'pre',
    resolveId(id) {
      if (id === 'react/jsx-runtime' || id === 'react/jsx-dev-runtime') {
        return path.resolve(__dirname, 'src/lib/wpJsxRuntime.ts')
      }
      if (id in wpGlobalModules) return VIRTUAL_PREFIX + id
      return null
    },
    load(id) {
      if (!id.startsWith(VIRTUAL_PREFIX)) return null
      const { global, names } = wpGlobalModules[id.slice(VIRTUAL_PREFIX.length)]
      const valid = names.filter((n) => n !== 'default' && /^[A-Za-z_$][\w$]*$/.test(n))
      return (
        `const g = ${global};\n` +
        `export default g;\n` +
        valid.map((n) => `export const ${n} = g.${n};`).join('\n')
      )
    },
  }
}

// Vite build for the BitBot admin SPA. Output lands in the plugin's
// assets/admin/ directory — the existing PHP enqueue mechanism in
// bitbot.php cache-busts entry files. Shared imports need content hashes
// because their relative URLs do not inherit the entry file query string.
export default defineConfig({
  plugins: [react(), wpGlobalsPlugin()],
  resolve: {
    // Keep in sync with tsconfig.json compilerOptions.paths.
    alias: {
      '@': path.resolve(__dirname, 'src'),
    },
  },
  build: {
    outDir: '../assets/admin',
    emptyOutDir: true,
    sourcemap: false,
    rollupOptions: {
      input: { admin: 'src/main.tsx', 'admin-browser-bridge': 'src/admin-browser-bridge.ts' },
      output: {
        entryFileNames: '[name].js',
        assetFileNames: (info) => {
          // Single CSS file → admin.css; anything else keeps its name.
          if (info.name?.endsWith('.css')) return 'admin.css'
          return '[name].[ext]'
        },
        chunkFileNames: '[name]-[hash].js',
      },
    },
  },
  server: {
    port: 5173,
    // Dev-server proxy so the React app can hit the backend RPC during
    // standalone dev (not in WP admin). In WP admin the apiUrl comes
    // from wp_localize_script.
    proxy: { '/api': 'https://dev.youami.ai' },
  },
})
