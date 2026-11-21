import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

// No ambiente local, o Vite encaminha os pedidos para os servidores R1 e R2.
// Em Kubernetes, o Ingress realiza o encaminhamento no mesmo domínio da SPA.
export default defineConfig({
  plugins: [vue()],
  server: {
    host: '127.0.0.1',
    port: 5173,
    strictPort: true,
    proxy: {
      '/api': { target: 'http://127.0.0.1:8000', changeOrigin: true },
      '/socket.io': { target: 'http://127.0.0.1:3001', changeOrigin: true, ws: true },
    },
  },
})
