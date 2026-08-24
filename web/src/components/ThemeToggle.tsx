import { useEffect, useState } from "react";

const STORAGE_KEY = "circle-theme";

/*
  The canvas colour of each theme, mirrored from global.css. Mobile browsers
  tint their own chrome from this, and a light bar above a black page is the
  one place the theme visibly leaks out of the document.
*/
const THEME_COLOR = { light: "#fbfbfd", dark: "#000000" } as const;

type Theme = keyof typeof THEME_COLOR;

/** What the inline script in Base.astro already decided, re-read on mount. */
function currentTheme(): Theme {
  return document.documentElement.dataset.theme === "dark" ? "dark" : "light";
}

function applyTheme(theme: Theme) {
  if (theme === "dark") {
    document.documentElement.dataset.theme = "dark";
  } else {
    delete document.documentElement.dataset.theme;
  }

  document
    .querySelector('meta[name="theme-color"]')
    ?.setAttribute("content", THEME_COLOR[theme]);

  try {
    localStorage.setItem(STORAGE_KEY, theme);
  } catch {
    /* A blocked store costs the reader their choice on the next page, not this one. */
  }
}

/**
 * The theme switch.
 *
 * Two states, not three: the product is a light sheet by default on every
 * device, and dark is something a reader turns on. There is deliberately no
 * "follow the system" setting — an OS preference set for a mail client is not
 * a statement about how someone wants to read a decision record.
 */
export function ThemeToggle({ className = "" }: { className?: string }) {
  /*
    Light until mounted. The server renders no theme, so committing to one here
    would mean a mismatched icon for the split second before hydration; the
    real value is read in the effect below.
  */
  const [theme, setTheme] = useState<Theme>("light");

  useEffect(() => setTheme(currentTheme()), []);

  const next: Theme = theme === "dark" ? "light" : "dark";

  return (
    <button
      type="button"
      onClick={() => {
        applyTheme(next);
        setTheme(next);
      }}
      aria-label={`Switch to ${next} mode`}
      title={`Switch to ${next} mode`}
      className={`inline-grid h-8 w-8 place-items-center rounded-[var(--r-control)] text-[var(--ink-muted)] transition-colors hover:bg-[var(--paper-sunk)] hover:text-[var(--ink)] ${className}`}
    >
      {/*
        The icon shows the destination, not the current state — a moon while
        you are in the light, the way the platform switches do.
      */}
      <svg
        width="16"
        height="16"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        strokeWidth="1.75"
        strokeLinecap="round"
        strokeLinejoin="round"
        aria-hidden="true"
      >
        {next === "dark" ? (
          <path d="M20.5 14.3A8.5 8.5 0 1 1 9.7 3.5a7 7 0 0 0 10.8 10.8Z" />
        ) : (
          <>
            <circle cx="12" cy="12" r="4.25" />
            <path d="M12 2.5v2M12 19.5v2M2.5 12h2M19.5 12h2M5.3 5.3l1.4 1.4M17.3 17.3l1.4 1.4M18.7 5.3l-1.4 1.4M6.7 17.3l-1.4 1.4" />
          </>
        )}
      </svg>
    </button>
  );
}
