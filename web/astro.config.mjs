// @ts-check
import { defineConfig } from "astro/config";

import react from "@astrojs/react";
import tailwindcss from "@tailwindcss/vite";
import node from "@astrojs/node";

// https://astro.build/config
export default defineConfig({
  integrations: [react()],

  // Server-rendered because the Circle routes are dynamic (`/circles/:id/...`)
  // and their set is not knowable at build time. The pages themselves render an
  // empty shell — every view is a React island that talks to the API with the
  // signed-in user's bearer token, so authorisation is never decided here.
  output: "server",

  adapter: node({ mode: "standalone" }),

  vite: {
    plugins: [tailwindcss()],
  },

  server: { port: 4321, host: true },

  devToolbar: { enabled: false },
});
