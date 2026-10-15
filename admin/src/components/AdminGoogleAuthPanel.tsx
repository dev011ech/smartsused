import { useCallback, useEffect, useState, type FormEvent } from "react";
import axios from "axios";
import { Save, ShieldCheck } from "lucide-react";
import { apiClient } from "../api/client";

type GoogleAuthConfig = {
  client_id: string;
  source: "wp-config" | "database" | "plugin-default";
  site_origin: string;
  can_edit: boolean;
  secret_used: false;
};

function getErrorMessage(error: unknown) {
  if (axios.isAxiosError<{ message?: string }>(error)) {
    return error.response?.data?.message ?? "Nastavenie Google prihlásenia sa nepodarilo načítať.";
  }
  return error instanceof Error ? error.message : "Nastavenie Google prihlásenia sa nepodarilo načítať.";
}

const sourceLabels: Record<GoogleAuthConfig["source"], string> = {
  "wp-config": "wp-config.php",
  database: "nastavenie administrátora",
  "plugin-default": "predvolené nastavenie pluginu",
};

export function AdminGoogleAuthPanel() {
  const [config, setConfig] = useState<GoogleAuthConfig | null>(null);
  const [clientId, setClientId] = useState("");
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [isLoading, setIsLoading] = useState(true);
  const [isSaving, setIsSaving] = useState(false);

  const loadConfig = useCallback(async () => {
    setIsLoading(true);
    setError("");
    try {
      const response = await apiClient.get<GoogleAuthConfig>("smartsused/v1/admin/google-auth");
      setConfig(response.data);
      setClientId(response.data.client_id);
    } catch (loadError) {
      setError(getErrorMessage(loadError));
    } finally {
      setIsLoading(false);
    }
  }, []);

  useEffect(() => {
    void loadConfig();
  }, [loadConfig]);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setError("");
    setNotice("");
    setIsSaving(true);
    try {
      const response = await apiClient.post<GoogleAuthConfig>("smartsused/v1/admin/google-auth", {
        client_id: clientId.trim(),
      });
      setConfig(response.data);
      setClientId(response.data.client_id);
      setNotice("Google Client ID bolo uložené. Zmena sa prejaví po obnovení prihlasovacej stránky.");
    } catch (saveError) {
      setError(getErrorMessage(saveError));
    } finally {
      setIsSaving(false);
    }
  }

  return (
    <section className="space-y-5" aria-labelledby="admin-google-auth-heading">
      <header>
        <p className="text-sm font-semibold text-blue-800">Prihlásenie a zabezpečenie</p>
        <h2 id="admin-google-auth-heading" className="mt-1 text-2xl font-bold text-primary">Google Auth</h2>
        <p className="mt-2 text-sm leading-6 text-slate-700">
          Spravujte verejné Web Client ID používané Google tlačidlom a serverovým overením ID tokenu.
        </p>
      </header>

      {error && <p role="alert" className="rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-800">{error}</p>}
      {notice && <p role="status" className="rounded-lg border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">{notice}</p>}

      <div className="grid gap-3 sm:grid-cols-2">
        <article className="rounded-2xl border border-slate-300 bg-white p-5 shadow-sm">
          <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Aktívny Client ID</p>
          <p className="mt-2 break-all font-mono text-sm font-semibold text-slate-950">
            {isLoading ? "Načítavam…" : config?.client_id || "Nie je nakonfigurované"}
          </p>
          <p className="mt-2 text-xs text-slate-500">
            Zdroj: {config ? sourceLabels[config.source] : "—"}
          </p>
        </article>
        <article className="rounded-2xl border border-slate-300 bg-white p-5 shadow-sm">
          <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">Očakávaný JavaScript origin</p>
          <p className="mt-2 break-all font-mono text-sm font-semibold text-slate-950">
            {isLoading ? "Načítavam…" : config?.site_origin || "Origin sa nepodarilo určiť"}
          </p>
          <p className="mt-2 text-xs text-slate-500">Origin musí byť pridaný presne takto v Google Cloud Console.</p>
        </article>
      </div>

      {!isLoading && config && (
        <form onSubmit={submit} className="max-w-2xl space-y-4 rounded-2xl border border-slate-300 bg-white p-5 shadow-sm sm:p-7">
          <label className="block text-sm font-semibold text-slate-800" htmlFor="google-web-client-id">
            Google OAuth Web Client ID
            <input
              id="google-web-client-id"
              type="text"
              autoComplete="off"
              required
              maxLength={256}
              readOnly={!config.can_edit}
              value={clientId}
              onChange={(event) => setClientId(event.target.value)}
              className="mt-2 block min-h-11 w-full rounded-lg border border-slate-300 px-3 font-mono text-sm font-normal outline-none focus:border-blue-700 focus:ring-2 focus:ring-blue-100 read-only:bg-slate-100"
            />
          </label>
          {config.can_edit ? (
            <button
              type="submit"
              disabled={isSaving || isLoading}
              className="inline-flex min-h-11 items-center gap-2 rounded-lg bg-blue-950 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-900 disabled:cursor-wait disabled:opacity-60"
            >
              <Save aria-hidden="true" className="size-4" />
              {isSaving ? "Ukladám…" : "Uložiť Client ID"}
            </button>
          ) : (
            <p className="text-sm text-slate-600">
              Client ID je uzamknuté konfiguráciou servera a mení sa vo <code>wp-config.php</code>.
            </p>
          )}
        </form>
      )}

      <aside className="rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm leading-6 text-blue-950">
        <h3 className="flex items-center gap-2 font-semibold">
          <ShieldCheck aria-hidden="true" className="size-4" />
          Kontrola Google Cloud Console
        </h3>
        <p className="mt-2">
          V OAuth klientovi typu Web application pridajte vyššie uvedený origin do „Authorized JavaScript origins“.
          Uložte zmeny v Google Cloud Console a následne znovu načítajte `/login`.
        </p>
        <p className="mt-2">
          Tento plugin používa Google Identity Services ID token, preto nepotrebuje Client Secret.
          Secret nevkladajte do formulára, Reactu ani repozitára. Uloženie Client ID kontroluje iba jeho formát;
          registráciu originu ani prihlásenie nemožno potvrdiť bez prístupu ku Google Cloud Console.
        </p>
      </aside>
    </section>
  );
}
