import { useEffect, useState, type FormEvent } from "react";
import { GoogleLogin, type CredentialResponse } from "@react-oauth/google";
import axios from "axios";
import { Link, useNavigate } from "react-router-dom";
import { apiClient } from "../api/client";

const googleClientId =
  window.smartsusedConfig?.google_client_id ??
  (import.meta.env.VITE_GOOGLE_CLIENT_ID as string | undefined);

function readInvitationToken() {
  const queryToken = new URLSearchParams(window.location.search).get("token");
  if (queryToken) return queryToken;

  const hashQuery = window.location.hash.split("?")[1] ?? "";
  return new URLSearchParams(hashQuery).get("token") ?? "";
}

function getErrorMessage(error: unknown) {
  if (axios.isAxiosError<{ message?: string }>(error)) {
    return error.response?.data?.message ?? "Registráciu sa nepodarilo dokončiť. Skúste to znova.";
  }
  return error instanceof Error ? error.message : "Registráciu sa nepodarilo dokončiť. Skúste to znova.";
}

export function RegisterTenant() {
  const navigate = useNavigate();
  const [token] = useState(readInvitationToken);
  const [email, setEmail] = useState("");
  const [password, setPassword] = useState("");
  const [passwordConfirmation, setPasswordConfirmation] = useState("");
  const [loadingInvitation, setLoadingInvitation] = useState(true);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState("");

  useEffect(() => {
    let active = true;

    async function loadInvitation() {
      if (!token) {
        setError("V odkaze chýba pozývací token. Požiadajte vlastníka bytu o novú pozvánku.");
        setLoadingInvitation(false);
        return;
      }

      try {
        const { data } = await apiClient.post<{ email: string }>(
          "smartsused/v1/tenant/invite/validate",
          { token },
        );
        if (active && typeof data.email === "string") {
          setEmail(data.email);
        } else if (active) {
          setError("Server nevrátil e-mail priradený k pozvánke.");
        }
      } catch (requestError) {
        if (active) setError(getErrorMessage(requestError));
      } finally {
        if (active) setLoadingInvitation(false);
      }
    }

    void loadInvitation();
    return () => {
      active = false;
    };
  }, [token]);

  async function finishRegistration(payload: { password?: string; id_token?: string }) {
    if (submitting) return;
    setSubmitting(true);
    setError("");

    try {
      await apiClient.post("smartsused/v1/tenant/register", {
        token,
        ...payload,
      });
      navigate("/login", {
        replace: true,
        state: { notice: "Účet bol úspešne vytvorený. Teraz sa môžete prihlásiť." },
      });
    } catch (requestError) {
      setError(getErrorMessage(requestError));
    } finally {
      setSubmitting(false);
    }
  }

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (password.length < 12) {
      setError("Heslo musí obsahovať aspoň 12 znakov.");
      return;
    }
    if (password !== passwordConfirmation) {
      setError("Zadané heslá sa nezhodujú.");
      return;
    }
    await finishRegistration({ password });
  }

  async function registerWithGoogle(credential: CredentialResponse) {
    if (!credential.credential) {
      setError("Google neposkytol prihlasovací token. Skúste to znova.");
      return;
    }
    await finishRegistration({ id_token: credential.credential });
  }

  return (
    <main className="flex min-h-screen items-center justify-center bg-gradient-to-br from-blue-950 via-blue-900 to-emerald-900 px-4 py-10">
      <section className="w-full max-w-xl rounded-3xl border border-white/30 bg-white p-6 shadow-2xl sm:p-9">
        <header>
          <p className="text-sm font-bold uppercase tracking-[0.14em] text-emerald-800">SmartSused · Nájomca</p>
          <h1 className="mt-2 text-3xl font-bold tracking-tight text-slate-950">Dokončite registráciu</h1>
          <p className="mt-3 text-base leading-7 text-slate-700">
            Vytvorte si účet na pozvanie vlastníka bytovej jednotky.
          </p>
        </header>

        {loadingInvitation ? (
          <p role="status" className="mt-7 rounded-xl bg-slate-100 p-4 font-medium text-slate-800">
            Overujem pozývací odkaz…
          </p>
        ) : email ? (
          <div className="mt-6 rounded-xl border border-slate-300 bg-slate-50 p-4">
            <p className="text-sm font-semibold text-slate-700">E-mail pozvaného nájomcu</p>
            <p className="mt-1 break-all text-lg font-bold text-slate-950">{email}</p>
          </div>
        ) : null}

        {error && (
          <p role="alert" className="mt-5 rounded-xl border-2 border-red-700 bg-red-50 p-4 font-semibold text-red-950">
            {error}
          </p>
        )}

        {email && !loadingInvitation && (
          <>
            <form className="mt-6 space-y-5" onSubmit={submit}>
              <label className="block text-base font-semibold text-slate-950">
                Nové heslo
                <input
                  autoComplete="new-password"
                  className="form-input mt-2 w-full"
                  type="password"
                  minLength={12}
                  maxLength={4096}
                  required
                  value={password}
                  onChange={(event) => setPassword(event.target.value)}
                />
                <span className="mt-1 block text-sm font-normal text-slate-700">Použite aspoň 12 znakov.</span>
              </label>
              <label className="block text-base font-semibold text-slate-950">
                Potvrdenie hesla
                <input
                  autoComplete="new-password"
                  className="form-input mt-2 w-full"
                  type="password"
                  minLength={12}
                  maxLength={4096}
                  required
                  value={passwordConfirmation}
                  onChange={(event) => setPasswordConfirmation(event.target.value)}
                />
              </label>
              <button
                type="submit"
                className="min-h-12 w-full rounded-xl bg-emerald-800 px-5 py-3 font-bold text-white shadow-sm transition hover:bg-emerald-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-700 disabled:cursor-wait disabled:opacity-60"
                disabled={submitting}
              >
                {submitting ? "Vytvára sa účet…" : "Vytvoriť účet"}
              </button>
            </form>

            <div className="my-6 flex items-center gap-3 text-sm font-medium text-slate-600">
              <span className="h-px flex-1 bg-slate-300" />
              alebo
              <span className="h-px flex-1 bg-slate-300" />
            </div>

            {googleClientId ? (
              <div className="flex justify-center">
                <GoogleLogin
                  onError={() => setError("Registrácia cez Google sa nepodarila. Skúste to znova.")}
                  onSuccess={(credential) => void registerWithGoogle(credential)}
                  text="signup_with"
                  theme="outline"
                  shape="rectangular"
                  width="320"
                />
              </div>
            ) : (
              <p className="rounded-xl bg-slate-100 p-4 text-center text-sm text-slate-700">
                Registrácia cez Google nie je nakonfigurovaná. Použite formulár na vytvorenie hesla.
              </p>
            )}
          </>
        )}

        <p className="mt-7 text-center text-sm text-slate-700">
          Už máte účet?{" "}
          <Link className="font-bold text-blue-900 underline underline-offset-4" to="/login">
            Prihláste sa
          </Link>
        </p>
      </section>
    </main>
  );
}
