import { defineConfig } from "vite";
import vue from "@vitejs/plugin-vue";
export default defineConfig({
  plugins: [vue()],
  server: {
    proxy: {
      "/console/ws": { target: process.env.CONSOLE_PROXY_TARGET || "http://127.0.0.1:18213", ws: true },
      "/api": {
        target: process.env.API_PROXY_TARGET || "http://127.0.0.1:8080",
      },
      "/health": {
        target: process.env.API_PROXY_TARGET || "http://127.0.0.1:8080",
      },
    },
  },
});
