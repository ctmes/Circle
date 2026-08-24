import { useState } from "react";
import { api, setToken } from "../lib/api";
import { Button, ErrorNote, Field, inputClass } from "./ui";
import { ThemeToggle } from "./ThemeToggle";
import { Wordmark } from "./Wordmark";

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
    <main className="relative grid min-h-screen place-items-center px-6 py-12">
      {/* Signed out there is no chrome to hang this off, so it sits in the corner. */}
      <ThemeToggle className="absolute right-4 top-4" />

      <div className="w-full max-w-[26rem]">
        <div className="mb-8 text-center">
          <div className="inline-flex flex-col items-center gap-3">
            <Wordmark size={22} />
            <p className="text-[0.9375rem] leading-relaxed text-[var(--ink-muted)]">
              A bounded place for one consequential decision.
            </p>
          </div>
        </div>

        <form
          onSubmit={(e) => {
            e.preventDefault();
            void submit();
          }}
          className="titleblock lay-in overflow-hidden"
        >
          {/*
            A segmented control, not two tabs. There are exactly two mutually
            exclusive modes, and the platform idiom for that is a switch you can
            see both halves of.
          */}
          <div className="p-2">
            <div className="grid grid-cols-2 gap-1 rounded-[var(--r-control)] bg-[var(--paper-inset)] p-1">
              {(["login", "register"] as const).map((m) => (
                <button
                  key={m}
                  type="button"
                  aria-pressed={mode === m}
                  onClick={() => {
                    setMode(m);
                    setError(null);
                  }}
                  className={`rounded-[7px] py-1.5 text-[0.8125rem] transition-all duration-150 ${
                    mode === m
                      ? "bg-[var(--segment-active)] font-[590] text-[var(--ink)] shadow-[0_1px_3px_rgb(0_0_0/0.10)]"
                      : "font-[450] text-[var(--ink-muted)] hover:text-[var(--ink)]"
                  }`}
                >
                  {m === "login" ? "Sign in" : "Create account"}
                </button>
              ))}
            </div>
          </div>

          <div className="space-y-4 px-5 pb-5 pt-2">
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

            <Button
              type="submit"
              variant="primary"
              disabled={busy}
              className="w-full !py-2.5 !text-[0.9375rem]"
            >
              {busy ? "One moment…" : mode === "login" ? "Sign in" : "Create account"}
            </Button>
          </div>
        </form>

        <p className="mx-auto mt-6 max-w-sm text-center text-[0.8125rem] leading-relaxed text-[var(--ink-faint)]">
          Access to any Circle comes from being invited to it. Belonging to the
          same organisation grants nothing on its own.
        </p>
      </div>
    </main>
  );
}
