import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";
import { resolve } from "path";
import liveReload from "vite-plugin-live-reload";
import basicSsl from "@vitejs/plugin-basic-ssl";

// https://vite.dev/config/
export default defineConfig({
  plugins: [react(), liveReload([__dirname + "/**/*.php"]), basicSsl()],
  build: {
    outDir: resolve(__dirname, "../admin-dist"),
    emptyOutDir: true,
    rollupOptions: {
      input: "src/main.jsx",
      output: {
        entryFileNames: `daily-recommend-admin.js`,
        assetFileNames: `daily-recommend-admin.[ext]`,
      },
    },
  },
  server: {
    cors: true,
    headers: {
      "Access-control-Allow-Origin": "*",
    },
    strictPort: true,
    port: 5173,
    host: "0.0.0.0",
    hmr: {
      host: "localhost",
      protocol: "wss",
    },
  },
});
