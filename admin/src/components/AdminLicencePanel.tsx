import { useCallback, useEffect, useState, type FormEvent } from "react";
import axios from "axios";
import { RefreshCw, Save } from "lucide-react";
import { apiClient } from "../api/client";

type LicenceStatus = {
  status: "active" | "inactive";
  expires_at: string;
  has_key: boolean;
  property_count: number;
  owner_count: number;
  max_properties: number | null;
  max_owners: number | null;
};

function getErrorMessage(error: unknown) {
  if (axios.isAxiosError<{ message?: string }>(error)) {
    return error.response?.data?.message ?? "Požiadavku na licenciu sa nepodarilo dokončiť.";
  }
  return error instanceof Error ? error.message : "Požiadavku na licenciu sa nepodarilo dokončiť.";
}

function countLabel(count: number, limit: number | null) {
  return `${count}${limit === null ? "" : ` / ${limit}`}`;
}

export function AdminLicencePanel() {
  const [licence, setLicence] = useState<LicenceStatus | null>(null);
  const [licenseKey, setLicenseKey] = useState("");
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [isLoading, setIsLoading] = useState(true);
  const [isSaving, setIsSaving] = useState(false);

  const loadLicence = useCallback(async () => {
    setIsLoading(true);
    setError("");
    try {
      const response = await apiClient.get<LicenceStatus>("smartsused/v1/admin/licence");
      setLicence(response.data);
    } catch (loadError) {
      setError(getErrorMessage(loadError));
    } finally {
      setIsLoading(false);
    }
  }, []);

  useEffect(() => {
    void loadLicence();
  }, [loadLicence]);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setError("");
    setNotice("");
    setIsSaving(true);
    try {
      const response = await apiClient.post<LicenceStatus>("smartsused/v1/admin/licence", {
        license_key: licenseKey,
      });
      setLicence(response.data);
      setLicenseKey("");
      setNotice("Licencia bola úspešne overená a uložená.");
    } catch (saveError) {
      setError(getErrorMessage(saveError));
    } finally {
      setIsSaving(false);
    }
  }

  return (
    <section className="space-y-5" aria-labelledby="admin-licence-heading">
      <header className="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h2 id="admin-licence-heading" className="text-2xl font-bold text-primary">Správa licencie</h2>
          <p className="mt-2 text-sm leading-6 text-slate-700">Licenčný kľúč sa uloží iba po úspešnom overení centrálnym serverom.</p>
        </div>
        <button
          type="button"
          onClick={() => void loadLicence()}
          disabled={isLoading}
          className="inline-flex min-h-10 items-center gap-2 rounded-lg border border-slate-300 bg-white px-3 text-sm font-semibold hover:bg-slate-50 disabled:opacity-60"
        >
          <RefreshCw aria-hidden="true" className={`size-4 ${isLoading ? "animate-spin" : ""}`} />
          Obnoviť stav
        </button>
      </header>

      {error && <p role="alert" className="rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-800">{error}</p>}
      {notice && <p role="status" className="rounded-lg border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">{notice}</p>}

      <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <InfoCard label="Stav licencie" value={isLoading ? "Načítavam…" : licence?.status === "active" ? "Aktívna" : "Neaktívna"} />
        <InfoCard label="Platnosť do" value={licence?.expires_at || "Neznáma"} />
        <InfoCard label="Bytové domy" value={licence ? countLabel(licence.property_count, licence.max_properties) : "—"} />
        <InfoCard label="Vlastníci" value={licence ? countLabel(licence.owner_count, licence.max_owners) : "—"} />
      </div>
      <p className="text-xs text-slate-500">
        {licence?.max_properties === null && licence?.max_owners === null
          ? "Licenčný server neposkytuje limity počtu domov ani vlastníkov; zobrazujú sa aktuálne počty v databáze."
          : "Počty v licencii uvádzajú aktuálne záznamy a dostupné licenčné limity."}
      </p>

      <form onSubmit={submit} className="max-w-2xl space-y-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
        <label className="block text-sm font-semibold text-slate-800" htmlFor="admin-license-key">
          {licence?.has_key ? "Nový licenčný kľúč" : "Licenčný kľúč"}
          <input
            id="admin-license-key"
            type="password"
            autoComplete="new-password"
            required
            maxLength={256}
            value={licenseKey}
            onChange={(event) => setLicenseKey(event.target.value)}
            className="mt-2 block min-h-11 w-full rounded-lg border border-slate-300 px-3 font-normal outline-none focus:border-blue-700 focus:ring-2 focus:ring-blue-100"
          />
        </label>
        <button
          type="submit"
          disabled={isSaving}
          className="inline-flex min-h-11 items-center gap-2 rounded-lg bg-blue-950 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-900 disabled:cursor-wait disabled:opacity-60"
        >
          <Save aria-hidden="true" className="size-4" />
          {isSaving ? "Overujem…" : "Uložiť a overiť"}
        </button>
      </form>
    </section>
  );
}

function InfoCard({ label, value }: { label: string; value: string }) {
  return (
    <article className="rounded-2xl border border-slate-300 bg-white p-5 shadow-sm">
      <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">{label}</p>
      <p className="mt-2 text-lg font-bold text-slate-950">{value}</p>
    </article>
  );
}
