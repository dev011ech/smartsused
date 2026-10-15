import { useRef, useState, type ChangeEvent, type DragEvent, type ReactNode } from "react";
import axios from "axios";
import { Download, Upload } from "lucide-react";
import { apiClient } from "../api/client";

type ImportResponse = {
  success: boolean;
  message: string;
  imported?: number;
  created?: number;
  duplicates_skipped: number;
  failed: number;
  email_failed?: number;
  errors: Array<{ row: number; message: string }>;
  errors_omitted: boolean;
};

type ImportResult = ImportResponse & { imported: number };
type ImportType = "properties" | "units" | "owners";

const MAX_FILE_SIZE = 5 * 1024 * 1024;

function formatFileSize(size: number) {
  if (size < 1024) {
    return `${size} B`;
  }

  if (size < 1024 * 1024) {
    return `${(size / 1024).toLocaleString("sk-SK", { maximumFractionDigits: 1 })} kB`;
  }

  return `${(size / (1024 * 1024)).toLocaleString("sk-SK", { maximumFractionDigits: 1 })} MB`;
}

export function CSVImportZone({
  importType,
  onImported,
}: {
  importType: ImportType;
  onImported?: () => void | Promise<void>;
}) {
  const inputRef = useRef<HTMLInputElement>(null);
  const [file, setFile] = useState<File | null>(null);
  const [isDragging, setIsDragging] = useState(false);
  const [isUploading, setIsUploading] = useState(false);
  const [downloadingTemplate, setDownloadingTemplate] = useState(false);
  const [error, setError] = useState("");
  const [result, setResult] = useState<ImportResult | null>(null);

  async function downloadTemplate() {
    if (downloadingTemplate) return;

    setDownloadingTemplate(true);

    try {
      const templateType = importType === "owners" ? "residents" : importType;
      const response = await apiClient.get<Blob>("smartsused/v1/import/template", {
        params: { type: templateType },
        responseType: "blob",
      });
      const url = URL.createObjectURL(response.data);
      const link = document.createElement("a");
      link.href = url;
      link.download = importType === "owners" ? "smartsused-obyvatelia.xlsx" : `smartsused-${templateType}-template.xlsx`;
      document.body.append(link);
      link.click();
      link.remove();
      window.setTimeout(() => URL.revokeObjectURL(url), 1000);
    } catch (requestError) {
      let message = "Excel šablónu sa nepodarilo stiahnuť. Skúste to znova.";

      if (axios.isAxiosError(requestError) && requestError.response?.data instanceof Blob) {
        try {
          const responseText = await requestError.response.data.text();
          const responseBody: unknown = JSON.parse(responseText);
          if (
            typeof responseBody === "object" &&
            responseBody !== null &&
            "message" in responseBody &&
            typeof responseBody.message === "string"
          ) {
            message = responseBody.message;
          }
        } catch {
          // Keep the user-facing download error when the response is not JSON.
        }
      } else if (requestError instanceof Error) {
        message = requestError.message;
      }

      setError(message);
    } finally {
      setDownloadingTemplate(false);
    }
  }

  function chooseFile(candidate: File | undefined) {
    setResult(null);

    if (!candidate) {
      return;
    }

    const extension = candidate.name.toLowerCase().split(".").pop();
    const allowedMimeTypes = [
      "text/csv",
      "text/plain",
      "application/csv",
      "application/vnd.ms-excel",
      "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet",
      "application/zip",
    ];
    const hasValidExtension = extension === "csv" || extension === "xlsx";
    const hasValidMimeType = !candidate.type || allowedMimeTypes.includes(candidate.type.toLowerCase());

    if (!hasValidExtension || !hasValidMimeType) {
      setFile(null);
      setError("Vyberte platný súbor .csv alebo .xlsx.");
      return;
    }

    if (candidate.size === 0 || candidate.size > MAX_FILE_SIZE) {
      setFile(null);
      setError("Súbor musí byť väčší ako 0 B a nesmie prekročiť 5 MB.");
      return;
    }

    setFile(candidate);
    setError("");
  }

  function handleFileChange(event: ChangeEvent<HTMLInputElement>) {
    chooseFile(event.currentTarget.files?.[0]);
    event.currentTarget.value = "";
  }

  function handleDragOver(event: DragEvent<HTMLDivElement>) {
    event.preventDefault();
    setIsDragging(true);
  }

  function handleDrop(event: DragEvent<HTMLDivElement>) {
    event.preventDefault();
    setIsDragging(false);
    chooseFile(event.dataTransfer.files[0]);
  }

  async function startImport() {
    if (!file || isUploading) {
      return;
    }

    setIsUploading(true);
    setError("");
    setResult(null);

    const formData = new FormData();
    formData.append("file", file);
    const importEndpoint = importType === "owners" ? "residents" : importType;

    try {
      const response = await apiClient.post<ImportResponse>(
        `smartsused/v1/import/${importEndpoint}`,
        formData,
        { headers: { "Content-Type": "multipart/form-data" } },
      );

      const imported = response.data?.imported ?? response.data?.created;
      if (!response.data?.success || typeof imported !== "number") {
        throw new Error("Server vrátil neplatnú odpoveď na import.");
      }

      setResult({ ...response.data, imported });
      await onImported?.();
    } catch (requestError) {
      const serverMessage = axios.isAxiosError<{ message?: string }>(requestError)
        ? requestError.response?.data?.message
        : undefined;

      setError(
        serverMessage ||
          (requestError instanceof Error
            ? requestError.message
            : "Import sa nepodarilo dokončiť. Skúste to znova."),
      );
    } finally {
      setIsUploading(false);
    }
  }

  return (
    <section aria-labelledby="csv-import-title" className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-7">
      <div>
        <p className="text-sm font-semibold text-accent">Import Excel/CSV · {importType === "properties" ? "bytové domy" : importType === "units" ? "bytové jednotky" : "obyvatelia"}</p>
        <h2 id="csv-import-title" className="mt-1 text-xl font-bold text-primary">
          {importType === "owners" ? "Import obyvateľov" : `Hromadný import ${importType === "properties" ? "bytových domov" : "bytových jednotiek"}`}
        </h2>
        <p className="mt-2 text-sm leading-6 text-slate-600">
          Hlavička a údaje súboru musia zodpovedať zvolenému typu importu.
          Podporované súbory Excel (.xlsx) a CSV; maximálna veľkosť je 5 MB.
        </p>
      </div>

      {importType === "owners" ? (
        <div className="mt-5 rounded-lg bg-slate-50 p-4 text-sm text-slate-800">
          <p className="font-semibold text-slate-950">
            Šablóna Obyvatelia sa automaticky prispôsobí počtu domov priradených k vášmu účtu.
          </p>
          <ul className="mt-2 space-y-2">
            <li>
              Jeden dom:{" "}
              <code className="break-all font-bold text-slate-950">
                Meno;Priezvisko;E-mail;Telefón;Číslo bytu;Typ obyvateľa
              </code>
              . Byt vyberiete z ponuky.
            </li>
            <li>
              Viac domov:{" "}
              <code className="break-all font-bold text-slate-950">
                Názov bytového domu;Meno;Priezvisko;E-mail;Telefón;Číslo bytu;Typ obyvateľa
              </code>
              . Dom vyberiete z ponuky a číslo bytu zadáte; server ho overí.
            </li>
          </ul>
        </div>
      ) : (
        <p className="mt-5 rounded-lg bg-slate-50 p-3 text-sm font-medium text-slate-800">
          Hlavička pre CSV:{" "}
          <code className="break-all font-bold text-slate-950">
            {importType === "units" ? "nazov_domu;cislo_bytu;poschodie;vchod" : "nazov_domu;adresa"}
          </code>
        </p>
      )}

      {importType === "properties" && (
        <div className="mt-3 overflow-x-auto rounded-xl border border-slate-300">
          <table className="min-w-full divide-y divide-slate-200 text-left text-sm">
            <caption className="sr-only">Príklad riadka pre import bytových domov</caption>
            <thead className="bg-slate-100 text-xs font-bold uppercase tracking-wide text-slate-800">
              <tr><th scope="col" className="px-3 py-3">Názov domu</th><th scope="col" className="px-3 py-3">Adresa</th></tr>
            </thead>
            <tbody className="divide-y divide-slate-200 bg-white text-slate-800">
              <tr><td className="whitespace-nowrap px-3 py-3">Javorová 12</td><td className="whitespace-nowrap px-3 py-3">Javorová 12, Bratislava</td></tr>
            </tbody>
          </table>
        </div>
      )}

      <div className="mt-5 flex flex-wrap gap-3">
        <button
          type="button"
          className="inline-flex min-h-12 items-center justify-center rounded-lg border-2 border-primary bg-white px-4 py-2 font-semibold text-primary transition hover:bg-blue-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent disabled:cursor-wait disabled:opacity-60"
          disabled={downloadingTemplate || isUploading}
          onClick={() => void downloadTemplate()}
        >
          <Download aria-hidden="true" className="mr-2" size={18} strokeWidth={1.5} />
          {downloadingTemplate ? "Sťahuje sa…" : importType === "owners" ? "Stiahnuť Excel šablónu Obyvatelia" : "Stiahnuť vzorový Excel súbor"}
        </button>
      </div>

      <input
        ref={inputRef}
        type="file"
        accept=".csv,text/csv,.xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
        className="sr-only"
        aria-label="Vybrať Excel alebo CSV súbor"
        onChange={handleFileChange}
      />

      <div
        role="button"
        tabIndex={0}
        aria-label="Vybrať Excel alebo CSV súbor"
        aria-describedby={error ? "csv-import-error" : undefined}
        onClick={() => inputRef.current?.click()}
        onKeyDown={(event) => {
          if (event.key === "Enter" || event.key === " ") {
            event.preventDefault();
            inputRef.current?.click();
          }
        }}
        onDragEnter={handleDragOver}
        onDragOver={handleDragOver}
        onDragLeave={(event) => {
          if (!event.currentTarget.contains(event.relatedTarget as Node | null)) {
            setIsDragging(false);
          }
        }}
        onDrop={handleDrop}
        className={`mt-6 flex min-h-48 cursor-pointer flex-col items-center justify-center rounded-xl border-2 border-dashed px-5 py-8 text-center transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent ${
          isDragging
            ? "border-accent bg-emerald-50"
            : "border-slate-300 bg-slate-50 hover:border-accent hover:bg-emerald-50/60"
        }`}
      >
        <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" className="size-10 text-accent">
          <path
            d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8l-6-6Z"
            stroke="currentColor"
            strokeWidth="1.8"
            strokeLinejoin="round"
          />
          <path d="M14 2v6h6M8 13h8M8 17h8" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" />
        </svg>
        <p className="mt-4 font-semibold text-primary">
          Presuňte sem súbor {importType === "owners" ? "Obyvatelia" : importType === "units" ? "Bytové jednotky" : "Bytové domy"} alebo kliknite pre jeho výber
        </p>
        <p className="mt-2 text-sm text-slate-500">Formát .xlsx alebo .csv · maximálne 5 MB</p>
      </div>

      {file && (
        <div className="mt-4 flex flex-col gap-4 rounded-xl border border-slate-200 bg-white p-4 sm:flex-row sm:items-center sm:justify-between">
          <div className="min-w-0">
            <p className="truncate font-semibold text-primary">{file.name}</p>
            <p className="mt-1 text-sm text-slate-500">{formatFileSize(file.size)}</p>
          </div>
          <button
            type="button"
            className="btn-primary shrink-0"
            disabled={isUploading}
            onClick={startImport}
          >
            {isUploading ? (
              <>
                <svg
                  aria-hidden="true"
                  viewBox="0 0 24 24"
                  fill="none"
                  className="-ml-1 mr-2 size-5 animate-spin"
                >
                  <circle cx="12" cy="12" r="9" stroke="currentColor" strokeOpacity=".25" strokeWidth="3" />
                  <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" strokeWidth="3" strokeLinecap="round" />
                </svg>
                Nahráva sa a importuje…
              </>
            ) : (
              <>
                <Upload aria-hidden="true" className="mr-2" size={18} strokeWidth={1.5} />
                Spustiť import
              </>
            )}
          </button>
        </div>
      )}

      {error && (
        <p id="csv-import-error" role="alert" className="mt-4 rounded-lg border border-red-200 bg-red-50 p-3 text-sm font-medium text-red-800">
          {error}
        </p>
      )}

      {result && (
        <div role="status" className="mt-4 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-emerald-950">
          <p className="font-semibold">{result.message}</p>
          <p className="mt-1 text-sm">
            {importType === "units" || importType === "properties" ? "Vytvorené" : "Naimportované"}: {result.imported} · Už priradené: {result.duplicates_skipped} · Chyby: {result.failed}
            {typeof result.email_failed === "number" && result.email_failed > 0 && <> · Neodoslané e-maily: {result.email_failed}</>}
          </p>
          {result.errors.length > 0 && (
            <ul className="mt-3 list-inside list-disc space-y-1 text-sm">
              {result.errors.map((item) => (
                <li key={`${item.row}-${item.message}`}>
                  Riadok {item.row}: {item.message}
                </li>
              ))}
            </ul>
          )}
          {result.errors_omitted && (
            <p className="mt-2 text-sm">Ďalšie podrobnosti o chybách boli vynechané.</p>
          )}
        </div>
      )}
    </section>
  );
}

export function CollapsibleCSVImportPanel({
  importType,
  onImported,
  toolbarContent,
}: {
  importType: ImportType;
  onImported?: () => void | Promise<void>;
  toolbarContent?: ReactNode;
}) {
  const [isOpen, setIsOpen] = useState(false);
  const panelId = `csv-import-panel-${importType}`;

  return (
    <div className="w-full">
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div className="flex min-w-0 flex-1 flex-wrap items-center gap-3">
          {toolbarContent}
        </div>
        <button
          type="button"
          aria-expanded={isOpen}
          aria-controls={panelId}
          onClick={() => setIsOpen((open) => !open)}
          className="inline-flex min-h-10 items-center justify-center gap-2 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 transition hover:border-slate-400 hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
        >
          <Upload aria-hidden="true" size={16} strokeWidth={1.5} />
          Hromadný import
        </button>
      </div>
      <div
        id={panelId}
        aria-hidden={!isOpen}
        className={`grid transition-all duration-300 ease-in-out ${
          isOpen ? "visible grid-rows-[1fr] pt-4 opacity-100" : "invisible grid-rows-[0fr] pt-0 opacity-0"
        }`}
      >
        <div className="min-h-0 overflow-hidden">
          <CSVImportZone importType={importType} onImported={onImported} />
        </div>
      </div>
    </div>
  );
}
