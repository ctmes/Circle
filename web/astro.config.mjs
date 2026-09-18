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

    server: {
      /*
        The dev server runs in a Linux container against a Windows bind mount,
        and inotify events do not cross that boundary — the watcher stays
        silent, so an edit to a layout or a component is served as the version
        that was on disk when the container started. It reads as the change
        "not working" rather than as a stale process, which is the expensive
        kind of wrong. Polling is the only thing that sees those writes; the
        interval is loose enough that idling on a large tree stays cheap.
      */
      watch: { usePolling: true, interval: 400 },
    },
  },

  server: { port: 4321, host: true },

  devToolbar: { enabled: false },
});
