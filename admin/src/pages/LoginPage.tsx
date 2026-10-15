import { useState, type FormEvent } from "react";
import { GoogleLogin, type CredentialResponse } from "@react-oauth/google";
import { Navigate, useLocation } from "react-router-dom";
import { useAuth, roleHome } from "../auth/AuthContext";

const googleClientId =
  window.smartsusedConfig?.google_client_id ??
  (import.meta.env.VITE_GOOGLE_CLIENT_ID as string | undefined);

export function LoginPage() {
  const { isAuthenticated, login, loginWithGoogle, user } = useAuth();
  const location = useLocation();
  const requestedPath =
    typeof location.state === "object" &&
    location.state !== null &&
    "from" in location.state &&
    typeof location.state.from === "object" &&
    location.state.from !== null &&
    "pathname" in location.state.from &&
    typeof location.state.from.pathname === "string" &&
    location.state.from.pathname !== "/login"
      ? location.state.from.pathname
      : null;
  const notice =
    typeof location.state === "object" &&
    location.state !== null &&
    "notice" in location.state &&
    typeof location.state.notice === "string"
      ? location.state.notice
      : "";
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState("");
  const [isSubmitting, setIsSubmitting] = useState(false);

  if (isAuthenticated && user) {
    return <Navigate to={requestedPath ?? roleHome[user.role]} replace />;
  }

  const submit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    setError("");
    setIsSubmitting(true);
    try {
      await login({ email, password });
    } catch (loginError) {
      setError(loginError instanceof Error ? loginError.message : "Prihlásenie sa nepodarilo.");
    } finally {
      setIsSubmitting(false);
    }
  };

  const handleGoogleCredential = async (credential: CredentialResponse) => {
    if (!credential.credential) {
      setError("Google neposkytol prihlasovací token.");
      return;
    }
    setError("");
    setIsSubmitting(true);
    try {
      await loginWithGoogle(credential.credential);
    } catch (loginError) {
      setError(loginError instanceof Error ? loginError.message : "Prihlásenie cez Google sa nepodarilo.");
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <main className="flex min-h-screen items-center justify-center bg-gradient-to-br from-blue-950 via-blue-900 to-cyan-800 px-4 py-12">
      <section className="w-full max-w-md rounded-3xl bg-white p-7 shadow-2xl sm:p-9">
        <div className="mb-8">
          <p className="mb-2 text-sm font-semibold uppercase tracking-[0.16em] text-cyan-700">Vitajte doma</p>
          <h1 className="text-3xl font-bold tracking-tight text-slate-950">Prihláste sa do SmartSused</h1>
          <p className="mt-2 text-sm leading-6 text-slate-600">Správa vášho bytového domu na jednom mieste.</p>
        </div>

        {notice && (
          <p role="status" className="mb-5 rounded-xl border-2 border-emerald-700 bg-emerald-50 px-4 py-3 text-sm font-semibold text-emerald-950">
            {notice}
          </p>
        )}

        <form className="space-y-5" onSubmit={submit}>
          <label className="block text-sm font-medium text-slate-700">
            E-mail
            <input
              autoComplete="username"
              className="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3 outline-none transition focus:border-blue-700 focus:ring-2 focus:ring-blue-100"
              onChange={(event) => setEmail(event.target.value)}
              required
              type="email"
              value={email}
            />
          </label>
          <label className="block text-sm font-medium text-slate-700">
            Heslo
            <input
              autoComplete="current-password"
              className="mt-2 w-full rounded-xl border border-slate-300 px-4 py-3 outline-none transition focus:border-blue-700 focus:ring-2 focus:ring-blue-100"
              onChange={(event) => setPassword(event.target.value)}
              required
              type="password"
              value={password}
            />
          </label>

          {error && (
            <p role="alert" className="rounded-xl bg-red-50 px-4 py-3 text-sm text-red-800">
              {error}
            </p>
          )}

          <button
            className="w-full rounded-xl bg-blue-950 px-4 py-3 font-semibold text-white transition hover:bg-blue-900 focus:outline-none focus:ring-2 focus:ring-blue-600 focus:ring-offset-2 disabled:cursor-wait disabled:opacity-60"
            disabled={isSubmitting}
            type="submit"
          >
            {isSubmitting ? "Prihlasujem…" : "Prihlásiť sa"}
          </button>
        </form>

        <div className="my-6 flex items-center gap-3 text-xs text-slate-400">
          <span className="h-px flex-1 bg-slate-200" />
          alebo pokračujte cez
          <span className="h-px flex-1 bg-slate-200" />
        </div>

        {googleClientId ? (
          <div className="flex justify-center">
            <GoogleLogin
              onError={() => setError("Prihlásenie cez Google sa nepodarilo.")}
              onSuccess={handleGoogleCredential}
              text="signin_with"
              theme="outline"
              shape="rectangular"
              width="320"
            />
          </div>
        ) : (
          <p className="rounded-xl bg-slate-50 px-4 py-3 text-center text-xs leading-5 text-slate-500">
            Google prihlásenie zatiaľ nie je nakonfigurované.
          </p>
        )}
      </section>
    </main>
  );
}
