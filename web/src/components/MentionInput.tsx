import { useEffect, useMemo, useRef, useState } from "react";
import { api, type MentionCandidate } from "../lib/api";
import { inputClass } from "./ui";

/**
 * Textarea that completes @mentions against the people a mention here would
 * actually reach.
 *
 * The list comes from the server per thread visibility rather than from the
 * Circle's member list: inside a private thread, offering someone who cannot
 * open it would promise a notification the backend then drops on the floor.
 *
 * What gets inserted is the handle, not the display name, because that is what
 * the server's parser matches. Names are shown; handles are typed.
 */

/** Mirrors the server's `/@([\w.\-]+)/` — the picker cannot offer what the parser will not read. */
const TOKEN = /(^|\s)@([\w.\-]*)$/;

const MAX_SUGGESTIONS = 6;

export function MentionTextarea({
  /** API path returning the mentionable list, e.g. `/threads/{id}/mentionable`. */
  source,
  value,
  onChange,
  rows = 2,
  placeholder,
  autoFocus = false,
  className = "",
}: {
  source: string;
  value: string;
  onChange: (next: string) => void;
  rows?: number;
  placeholder?: string;
  autoFocus?: boolean;
  className?: string;
}) {
  const ref = useRef<HTMLTextAreaElement | null>(null);
  const [people, setPeople] = useState<MentionCandidate[] | null>(null);
  const [query, setQuery] = useState<string | null>(null);
  const [active, setActive] = useState(0);

  // Fetched on the first @ rather than on mount: most composers are opened and
  // closed without anyone mentioning anybody.
  useEffect(() => {
    setPeople(null);
  }, [source]);

  useEffect(() => {
    if (query === null || people !== null) return;
    let live = true;
    api
      .get<{ data: MentionCandidate[] }>(source)
      .then((r) => live && setPeople(r.data))
      // A picker that cannot load is a picker that stays shut. Typing the
      // handle by hand still works, so there is nothing to report here.
      .catch(() => live && setPeople([]));
    return () => {
      live = false;
    };
  }, [query, people, source]);

  const matches = useMemo(() => {
    if (query === null || people === null) return [];
    const q = query.toLowerCase();
    if (q === "") return people.slice(0, MAX_SUGGESTIONS);

    return people
      .filter((p) => {
        const name = (p.name ?? "").toLowerCase();
        return (
          p.handle.startsWith(q) ||
          name.startsWith(q) ||
          name.split(" ").some((word) => word.startsWith(q))
        );
      })
      .slice(0, MAX_SUGGESTIONS);
  }, [people, query]);

  const open = query !== null && matches.length > 0;

  function syncQuery(el: HTMLTextAreaElement) {
    const before = el.value.slice(0, el.selectionStart ?? el.value.length);
    const found = before.match(TOKEN);
    setQuery(found === null ? null : found[2]);
    setActive(0);
  }

  function choose(person: MentionCandidate) {
    const el = ref.current;
    if (el === null) return;

    const caret = el.selectionStart ?? value.length;
    const before = value.slice(0, caret);
    const found = before.match(TOKEN);
    if (found === null) return;

    const start = caret - found[0].length + found[1].length;
    const inserted = `@${person.handle} `;
    const next = value.slice(0, start) + inserted + value.slice(caret);

    onChange(next);
    setQuery(null);

    // The caret has to land after the inserted handle, which React will not do
    // on its own once the value is replaced underneath it.
    const at = start + inserted.length;
    requestAnimationFrame(() => {
      el.focus();
      el.setSelectionRange(at, at);
    });
  }

  return (
    <div className="relative w-full">
      {open && (
        <ul
          role="listbox"
          className="card absolute bottom-full left-0 z-30 mb-1.5 w-72 max-w-full overflow-hidden py-1 shadow-[var(--shadow-ring),var(--shadow-float)]"
        >
          {matches.map((p, i) => (
            <li key={p.id}>
              <button
                type="button"
                role="option"
                aria-selected={i === active}
                // Selecting must not blur the textarea first, or the caret
                // position the insert depends on is already gone.
                onMouseDown={(e) => {
                  e.preventDefault();
                  choose(p);
                }}
                onMouseEnter={() => setActive(i)}
                className={`flex w-full items-baseline gap-2 px-3 py-1.5 text-left text-[0.8125rem] ${
                  i === active ? "bg-[var(--paper-sunk)]" : ""
                }`}
              >
                <span className="font-[590] text-[var(--ink)]">{p.name ?? p.handle}</span>
                <span className="text-xs text-[var(--ink-faint)]">@{p.handle}</span>
                {p.party && (
                  <span className="ml-auto shrink-0 text-xs text-[var(--ink-faint)]">
                    {p.party}
                  </span>
                )}
              </button>
            </li>
          ))}
        </ul>
      )}

      <textarea
        ref={ref}
        value={value}
        rows={rows}
        autoFocus={autoFocus}
        placeholder={placeholder}
        className={`${inputClass} !text-sm ${className}`}
        onChange={(e) => {
          onChange(e.target.value);
          syncQuery(e.target);
        }}
        // Moving the caret away from a half-typed @name should close the menu,
        // otherwise it stays open over text it no longer refers to.
        onClick={(e) => syncQuery(e.currentTarget)}
        onKeyUp={(e) => {
          if (["ArrowLeft", "ArrowRight", "Home", "End"].includes(e.key)) {
            syncQuery(e.currentTarget);
          }
        }}
        onBlur={() => setQuery(null)}
        onKeyDown={(e) => {
          if (!open) return;

          if (e.key === "ArrowDown") {
            e.preventDefault();
            setActive((i) => (i + 1) % matches.length);
          } else if (e.key === "ArrowUp") {
            e.preventDefault();
            setActive((i) => (i - 1 + matches.length) % matches.length);
          } else if (e.key === "Enter" || e.key === "Tab") {
            // Enter picks the highlighted name rather than sending: the menu
            // being open is the signal that a name is what was meant.
            e.preventDefault();
            choose(matches[active]);
          } else if (e.key === "Escape") {
            e.preventDefault();
            setQuery(null);
          }
        }}
      />
    </div>
  );
}
