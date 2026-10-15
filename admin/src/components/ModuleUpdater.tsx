import { useCallback, useEffect, useState } from "react";
import axios from "axios";
import { Download, LoaderCircle, RefreshCw } from "lucide-react";
import { apiClient } from "../api/client";

const modules = [
  { slug: "smartsused-notices", name: "SmartSused Notices" },
  { slug: "smartsused-helpdesk", name: "SmartSused Helpdesk" },
  { slug: "smartsused-technical-passport", name: "SmartSused Technical Passport" },
  { slug: "smartsused-polls", name: "SmartSused Polls" },
] as const;

type UpdateResponse = {
  updated: boolean;
  version: string;
  message: string;
};

type CoreUpdateInfo = {
  current_version: string;
  latest_version: string;
  update_available: boolean;
  changelog: string;
};

type ModuleVersionsResponse = {
  versions: Record<string, string | null>;
};

export function ModuleUpdater() {
  const [busySlug, setBusySlug] = useState<string | null>(null);
  const [messages, setMessages] = useState<Record<string, string>>({});
  const [error, setError] = useState("");
  const [coreUpdate, setCoreUpdate] = useState<CoreUpdateInfo | null>(null);
  const [coreError, setCoreError] = useState("");
  const [isCheckingCore, setIsCheckingCore] = useState(true);
  const [moduleVersions, setModuleVersions] = useState<Record<string, string | null>>({});
  const [isLoadingVersions, setIsLoadingVersions] = useState(true);
  const [versionsError, setVersionsError] = useState("");

  const loadModuleVersions = useCallback(async () => {
    setIsLoadingVersions(true);
    setVersionsError("");
    try {
      const response = await apiClient.get<ModuleVersionsResponse>("smartsused/v1/modules/versions");
      if (
        !response.data.versions ||
        typeof response.data.versions !== "object" ||
        modules.some((module) => {
          const version = response.data.versions[module.slug];
          return version !== null && typeof version !== "string";
        })
      ) {
        throw new Error("Server vrátil neplatné informácie o verziách modulov.");
      }
      setModuleVersions(response.data.versions);
    } catch (requestError) {
      setVersionsError(
        axios.isAxiosError<{ message?: string }>(requestError)
          ? requestError.response?.data?.message ?? "Verzie modulov sa nepodarilo načítať."
          : requestError instanceof Error
            ? requestError.message
            : "Verzie modulov sa nepodarilo načítať.",
      );
    } finally {
      setIsLoadingVersions(false);
    }
  }, []);

  const checkCoreUpdate = useCallback(async () => {
    setIsCheckingCore(true);
    setCoreError("");
    try {
      const response = await apiClient.get<CoreUpdateInfo>("smartsused/v1/modules/core-update");
      if (
        typeof response.data.current_version !== "string" ||
        typeof response.data.latest_version !== "string" ||
        typeof response.data.update_available !== "boolean" ||
        typeof response.data.changelog !== "string"
      ) {
        throw new Error("Licenčný server vrátil neplatné informácie o aktualizácii Core.");
      }
      setCoreUpdate(response.data);
    } catch (requestError) {
      setCoreError(
        axios.isAxiosError<{ message?: string }>(requestError)
          ? requestError.response?.data?.message ?? "Kontrola aktualizácie SmartSused Core zlyhala."
          : requestError instanceof Error
            ? requestError.message
            : "Kontrola aktualizácie SmartSused Core zlyhala.",
      );
    } finally {
      setIsCheckingCore(false);
    }
  }, []);

  useEffect(() => {
    void checkCoreUpdate();
    void loadModuleVersions();
  }, [checkCoreUpdate, loadModuleVersions]);

  const updateModule = async (slug: (typeof modules)[number]["slug"] | "sprava-bytov") => {
    setBusySlug(slug);
    setError("");
    setMessages((current) => ({ ...current, [slug]: "" }));

    try {
      const response = await apiClient.post<UpdateResponse>("smartsused/v1/modules/update", { slug });
      if (slug === "sprava-bytov" && response.data.updated) {
        setCoreUpdate((current) =>
          current
            ? { ...current, current_version: response.data.version, latest_version: response.data.version, update_available: false }
            : current,
        );
        setMessages((current) => ({
          ...current,
          [slug]: `${response.data.message} Obnovte stránku, aby sa načítala nová verzia.`,
        }));
        return;
      }
      setMessages((current) => ({ ...current, [slug]: response.data.message }));
      if (response.data.updated && slug !== "sprava-bytov") {
        setModuleVersions((current) => ({ ...current, [slug]: response.data.version }));
      }
    } catch (requestError) {
      const message = axios.isAxiosError<{ message?: string }>(requestError)
        ? requestError.response?.data?.message ?? "Aktualizáciu sa nepodarilo dokončiť."
        : requestError instanceof Error
          ? requestError.message
          : "Aktualizáciu sa nepodarilo dokončiť.";
      setError(message);
    } finally {
      setBusySlug(null);
    }
  };

  return (
    <section className="space-y-5" aria-labelledby="module-updates-heading">
      <header>
        <p className="text-sm font-semibold uppercase tracking-wide text-emerald-700">Správa platformy</p>
        <h1 id="module-updates-heading" className="mt-1 text-2xl font-bold text-slate-950">
          Aktualizácie modulov
        </h1>
        <p className="mt-2 text-sm text-slate-600">
          Balíky sa overujú cez licenčný server a inštalujú pomocou WordPress aktualizačného nástroja.
        </p>
      </header>

      {error && (
        <div role="alert" className="rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-800">
          {error}
        </div>
      )}
      {versionsError && (
        <div role="alert" className="rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-800">
          {versionsError}
        </div>
      )}

      <ul className="grid gap-4 md:grid-cols-2">
        <li className="flex flex-col justify-between gap-4 rounded-xl border-2 border-blue-300 bg-blue-50 p-5 shadow-sm md:col-span-2">
          <div className="flex flex-wrap items-start justify-between gap-4">
            <div>
              <h2 className="text-lg font-bold text-blue-950">SmartSused Core</h2>
              <p className="mt-1 text-sm text-slate-700">
                Aktuálna verzia: {coreUpdate ? `v${coreUpdate.current_version}` : "Načítavam…"}
                {coreUpdate ? ` · Dostupná verzia: v${coreUpdate.latest_version}` : ""}
              </p>
              {coreUpdate?.changelog && coreUpdate.update_available && (
                <p className="mt-2 whitespace-pre-line text-sm text-slate-700">{coreUpdate.changelog}</p>
              )}
              {messages["sprava-bytov"] && (
                <p role="status" className="mt-2 text-sm text-emerald-800">{messages["sprava-bytov"]}</p>
              )}
              {coreError && <p role="alert" className="mt-2 text-sm text-red-800">{coreError}</p>}
            </div>
            <button
              type="button"
              disabled={busySlug !== null || isCheckingCore || !coreUpdate?.update_available}
              onClick={() => void updateModule("sprava-bytov")}
              className="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg bg-blue-950 px-4 py-2 text-sm font-bold text-white hover:bg-blue-900 focus:outline-none focus:ring-2 focus:ring-blue-700 disabled:cursor-not-allowed disabled:opacity-60"
            >
              {busySlug === "sprava-bytov" ? (
                <LoaderCircle aria-hidden="true" className="size-4 animate-spin" />
              ) : (
                <Download aria-hidden="true" className="size-4" />
              )}
              {busySlug === "sprava-bytov" ? "Aktualizujem jadro…" : "Aktualizovať jadro systému"}
            </button>
          </div>
          <button
            type="button"
            disabled={isCheckingCore || busySlug !== null}
            onClick={() => void checkCoreUpdate()}
            className="inline-flex min-h-9 w-fit items-center gap-2 rounded-lg border border-blue-300 bg-white px-3 text-sm font-semibold text-blue-950 hover:bg-blue-100 disabled:opacity-60"
          >
            <RefreshCw aria-hidden="true" className={`size-4 ${isCheckingCore ? "animate-spin" : ""}`} />
            Znovu skontrolovať Core
          </button>
        </li>
        {modules.map((module) => (
          <li key={module.slug} className="flex flex-col justify-between gap-4 rounded-xl border border-slate-200 bg-white p-5 shadow-sm">
            <div>
              <h2 className="font-semibold text-slate-900">{module.name}</h2>
              <p className="mt-1 text-sm text-slate-600">
                Aktuálna verzia: {isLoadingVersions ? "Načítavam…" : moduleVersions[module.slug] ? `v${moduleVersions[module.slug]}` : "Modul nie je nainštalovaný"}
              </p>
              {messages[module.slug] && (
                <p role="status" className="mt-2 text-sm text-emerald-800">
                  {messages[module.slug]}
                </p>
              )}
            </div>
            <button
              type="button"
              disabled={busySlug !== null || isLoadingVersions || !moduleVersions[module.slug]}
              onClick={() => void updateModule(module.slug)}
              className="inline-flex min-h-11 items-center justify-center gap-2 rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-blue-700 disabled:cursor-not-allowed disabled:opacity-60"
            >
              {busySlug === module.slug ? (
                <LoaderCircle aria-hidden="true" className="h-4 w-4 animate-spin" />
              ) : (
                <Download aria-hidden="true" className="h-4 w-4" />
              )}
              Skontrolovať a aktualizovať
            </button>
          </li>
        ))}
      </ul>
    </section>
  );
}
