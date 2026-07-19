import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import tailwindcss from '@tailwindcss/vite'

// Deploys as static files directly into Hostinger's public_html (same origin
// as /api), not a separate subdomain — see CLAUDE.md / project notes.
export default defineConfig({
  plugins: [react(), tailwindcss()],
  server: {
    // Local dev only: proxies /api to the PHP built-in server started per
    // README ("php -S localhost:8000 -t api"). Production build is deployed
    // same-origin into public_html, so no proxy/CORS config is needed there.
    proxy: {
      '/api': {
        target: 'http://localhost:8000',
        rewrite: (path) => path.replace(/^\/api/, ''),
      },
    },
  },
})
