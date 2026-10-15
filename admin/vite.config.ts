import tailwindcss from "@tailwindcss/vite";
import react from "@vitejs/plugin-react";
import { dirname, resolve } from "node:path";
import { fileURLToPath } from "node:url";
import { defineConfig } from "vite";

const root = dirname(fileURLToPath(import.meta.url));

export default defineConfig({
  root,
  plugins: [react(), tailwindcss()],
  build: {
    outDir: resolve(root, "../assets/build"),
    emptyOutDir: true,
    cssCodeSplit: false,
    modulePreload: { polyfill: false },
    rollupOptions: {
      input: resolve(root, "index.html"),
      output: {
        format: "iife",
        entryFileNames: "smartsused-admin.js",
        assetFileNames: (asset) =>
          asset.name?.endsWith(".css") ? "smartsused-admin.css" : "assets/[name][extname]",
      },
    },
  },
});
