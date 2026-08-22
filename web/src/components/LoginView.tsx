import { useState } from "react";
import { api, setToken } from "../lib/api";
import { Button, ErrorNote, Field, inputClass } from "./ui";

/**
 * Sign in / register.
 *
 * Kept deliberately plain. The interesting security properties of this system
 * are in the Circle policy layer, not here — the MVP is explicit that enterprise
 * SSO is out of scope (spec §3).
 */
export function LoginView() {
  const [mode, setMode] = useState<"login" | "register">("login");
  const [name, setName] = useState("");
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState<unknown>(null);
  const [busy, setBusy] = useState(false);

  async function submit() {
    setBusy(true);
    setError(null);

    try {
      const res = await api.post<{ token: string }>(
        mode === "login" ? "/auth/login" : "/auth/register",
        mode === "login" ? { email, password } : { name, email, password },
      );
      setToken(res.token);

      // Honour a pending invitation link, if that is how they arrived.
      const next = new URLSearchParams(location.search).get("next");
      location.href = next ?? "/circles";
    } catch (e) {
      setError(e);
    } finally {
      setBusy(false);
    }
  }

  return (
    <main className="grid min-h-screen place-items-center px-6 py-12">
      <div className="w-full max-w-md">
        <div className="mb-7 flex items-center gap-3">
          <svg width="30" height="30" viewBox="0 0 32 32" aria-hidden="true">
            <circle cx="16" cy="16" r="12" fill="none" stroke="currentColor" strokeWidth="2.5" />
            <circle cx="16" cy="16" r="4" fill="var(--signal)" />
          </svg>
          <div>
            <p className="display text-lg font-800 uppercase tracking-[0.24em] leading-none">
              Circle
            </p>
            <p className="mt-1 text-xs text-[var(--ink-muted)]">
              A bounded place for one consequential decision.
            </p>
          </div>
        </div>

        <form onSubmit={(e) => { e.preventDefault(); void submit(); }} className="titleblock lay-in">
          <div className="border-b border-[var(--rule)] px-5 py-2.5">
            <div className="flex gap-4">
              {(["login", "register"] as const).map((m) => (
                <button
                  key={m}
                  type="button"
                  onClick={() => {
                    setMode(m);
                    setError(null);
                  }}
                  className={`label pb-0.5 ${
                    mode === m
                      ? "!text-[var(--ink)] border-b-2 border-[var(--signal)]"
                      : "hover:text-[var(--ink)]"
                  }`}
                >
                  {m === "login" ? "Sign in" : "Create account"}
                </button>
              ))}
            </div>
          </div>

          <div className="space-y-3 px-5 py-5">
            {mode === "register" && (
              <Field label="Name">
                <input
                  value={name}
                  onChange={(e) => setName(e.target.value)}
                  className={inputClass}
                  autoComplete="name"
                  required
                />
              </Field>
            )}

            <Field label="Email">
              <input
                value={email}
                onChange={(e) => setEmail(e.target.value)}
                type="email"
                className={inputClass}
                autoComplete="email"
                required
              />
            </Field>

            <Field
              label="Password"
              hint={mode === "register" ? "At least 12 characters." : undefined}
            >
              <input
                value={password}
                onChange={(e) => setPassword(e.target.value)}
                type="password"
                className={inputClass}
                autoComplete={mode === "login" ? "current-password" : "new-password"}
                required
              />
            </Field>

            {!!error && <ErrorNote error={error} />}

            <Button type="submit" variant="primary" disabled={busy} className="w-full">
              {busy ? "…" : mode === "login" ? "Sign in" : "Create account"}
            </Button>
          </div>
        </form>

        <p className="mt-5 max-w-md text-xs leading-relaxed text-[var(--ink-faint)]">
          Access to any Circle comes from being invited to it. Belonging to the
          same organisation grants nothing on its own.
        </p>
      </div>
    </main>
  );
}
