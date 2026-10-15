import { useCallback, useEffect, useRef, useState, type FormEvent } from "react";
import axios from "axios";
import { CircleStop, Eye, Plus, Save, Trash2, X } from "lucide-react";
import { apiClient } from "../api/client";
import { ActionIconButton } from "./ActionIconButton";
import { EmptyTableState } from "./EmptyTableState";
import { PollResults } from "./PollResults";

type Property = {
  id: number;
  title: string;
};

type Poll = {
  id: number;
  question: string;
  description: string;
  property_id: number;
  status: "active" | "closed";
  expires_at: number;
  results: {
    total_votes: number;
    ano: number;
    nie: number;
    zdrzal_sa: number;
  };
};

type PollsResponse = {
  polls: Poll[];
  properties: Property[];
  total: number;
  page: number;
  total_pages: number;
};

type PollForm = {
  question: string;
  description: string;
  property_id: string;
  expires_on: string;
};

const emptyForm: PollForm = {
  question: "",
  description: "",
  property_id: "",
  expires_on: "",
};

function errorMessage(error: unknown, fallback: string) {
  if (axios.isAxiosError<{ message?: string }>(error)) {
    return error.response?.data?.message ?? fallback;
  }
  return error instanceof Error ? error.message : fallback;
}

function isPollsResponse(value: unknown): value is PollsResponse {
  if (!value || typeof value !== "object") return false;
  const response = value as Partial<PollsResponse>;
  return Array.isArray(response.polls)
    && Array.isArray(response.properties)
    && Number.isInteger(response.total)
    && Number.isInteger(response.page)
    && Number.isInteger(response.total_pages)
    && response.polls.every((poll) =>
      Number.isInteger(poll.id)
      && typeof poll.question === "string"
      && typeof poll.description === "string"
      && Number.isInteger(poll.property_id)
      && (poll.status === "active" || poll.status === "closed")
      && Number.isInteger(poll.expires_at)
      && Number.isInteger(poll.results?.total_votes)
      && Number.isInteger(poll.results?.ano)
      && Number.isInteger(poll.results?.nie)
      && Number.isInteger(poll.results?.zdrzal_sa),
    )
    && response.properties.every((property) => Number.isInteger(property.id) && typeof property.title === "string");
}

function expirationTimestamp(date: string) {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(date);
  if (!match) return null;
  const [, year, month, day] = match;
  const timestamp = Math.floor(Date.UTC(Number(year), Number(month) - 1, Number(day), 23, 59, 59) / 1000);
  const parsed = new Date(timestamp * 1000);
  if (
    parsed.getUTCFullYear() !== Number(year)
    || parsed.getUTCMonth() !== Number(month) - 1
    || parsed.getUTCDate() !== Number(day)
  ) {
    return null;
  }
  return timestamp;
}

function formatDate(timestamp: number) {
  return new Intl.DateTimeFormat("sk-SK", { dateStyle: "medium", timeZone: "UTC" })
    .format(new Date(timestamp * 1000));
}

export function ManagerPollsPanel() {
  const [polls, setPolls] = useState<Poll[]>([]);
  const [properties, setProperties] = useState<Property[]>([]);
  const [page, setPage] = useState(1);
  const [total, setTotal] = useState(0);
  const [totalPages, setTotalPages] = useState(0);
  const [form, setForm] = useState<PollForm>(emptyForm);
  const [expandedPollId, setExpandedPollId] = useState<number | null>(null);
  const [pageError, setPageError] = useState("");
  const [notice, setNotice] = useState("");
  const [isLoading, setIsLoading] = useState(true);
  const [isSaving, setIsSaving] = useState(false);
  const [busyPollId, setBusyPollId] = useState<number | null>(null);
  const [isDialogOpen, setIsDialogOpen] = useState(false);
  const dialogRef = useRef<HTMLDialogElement>(null);

  const loadData = useCallback(async (requestedPage: number, quiet = false) => {
    if (!quiet) {
      setIsLoading(true);
      setPageError("");
    }
    try {
      const { data } = await apiClient.get<unknown>("smartsused/v1/polls/manage", {
        params: { page: requestedPage },
      });
      if (!isPollsResponse(data)) {
        throw new Error("Server vrátil neplatné údaje ankiet.");
      }
      setPolls(data.polls);
      setProperties(data.properties);
      setTotal(data.total);
      setTotalPages(data.total_pages);
      setPage(data.page);
      setPageError("");
    } catch (error) {
      setPageError(errorMessage(error, "Ankety sa nepodarilo načítať."));
    } finally {
      if (!quiet) setIsLoading(false);
    }
  }, []);

  useEffect(() => {
    void loadData(page);
  }, [loadData, page]);

  useEffect(() => {
    const interval = window.setInterval(() => {
      void loadData(page, true);
    }, 15000);
    return () => window.clearInterval(interval);
  }, [loadData, page]);

  useEffect(() => {
    const dialog = dialogRef.current;
    if (!dialog) return;
    if (isDialogOpen && !dialog.open) dialog.showModal();
    if (!isDialogOpen && dialog.open) dialog.close();
  }, [isDialogOpen]);

  const propertyNames = new Map(properties.map((property) => [property.id, property.title]));

  async function createPoll(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setPageError("");
    setNotice("");
    const expiresAt = expirationTimestamp(form.expires_on);
    if (!expiresAt || expiresAt <= Math.floor(Date.now() / 1000)) {
      setPageError("Zadajte platný dátum ukončenia v budúcnosti.");
      return;
    }

    setIsSaving(true);
    try {
      await apiClient.post("smartsused/v1/polls/manage", {
        question: form.question.trim(),
        description: form.description.trim(),
        property_id: Number(form.property_id),
        expires_at: expiresAt,
      });
      setForm(emptyForm);
      setIsDialogOpen(false);
      setPage(1);
      setNotice("Anketa bola vytvorená a je dostupná rezidentom vybraného domu.");
      await loadData(1, true);
    } catch (error) {
      setPageError(errorMessage(error, "Anketu sa nepodarilo vytvoriť."));
    } finally {
      setIsSaving(false);
    }
  }

  async function closePoll(poll: Poll) {
    if (!window.confirm(`Naozaj chcete predčasne ukončiť anketu „${poll.question}“?`)) return;
    setPageError("");
    setNotice("");
    setBusyPollId(poll.id);
    try {
      const { data } = await apiClient.put<{ message?: string }>(`smartsused/v1/polls/manage/${poll.id}`);
      setNotice(data.message ?? "Anketa bola ukončená.");
      await loadData(page, true);
    } catch (error) {
      setPageError(errorMessage(error, "Anketu sa nepodarilo ukončiť."));
    } finally {
      setBusyPollId(null);
    }
  }

  async function deletePoll(poll: Poll) {
    if (!window.confirm(`Naozaj chcete natrvalo odstrániť anketu „${poll.question}“ aj jej hlasy?`)) return;
    setPageError("");
    setNotice("");
    setBusyPollId(poll.id);
    try {
      const { data } = await apiClient.delete<{ message?: string }>(`smartsused/v1/polls/manage/${poll.id}`);
      setNotice(data.message ?? "Anketa bola odstránená.");
      const nextPage = polls.length === 1 && page > 1 ? page - 1 : page;
      setPage(nextPage);
      await loadData(nextPage, true);
    } catch (error) {
      setPageError(errorMessage(error, "Anketu sa nepodarilo odstrániť."));
    } finally {
      setBusyPollId(null);
    }
  }

  return (
    <section className="space-y-5" aria-labelledby="manager-polls-heading">
      <header className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <p className="text-sm font-semibold text-blue-800">SmartSused · Správa</p>
          <h1 id="manager-polls-heading" className="mt-1 text-2xl font-bold tracking-tight text-primary">Ankety</h1>
          <p className="mt-2 text-sm text-slate-600">Správa ankiet pre priradené bytové domy. Výsledky sa obnovujú každých 15 sekúnd.</p>
        </div>
        <button
          type="button"
          onClick={() => {
            setPageError("");
            setNotice("");
            setForm({ ...emptyForm, property_id: properties.length === 1 ? String(properties[0].id) : "" });
            setIsDialogOpen(true);
          }}
          disabled={properties.length === 0}
          className="inline-flex min-h-10 items-center justify-center gap-2 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 transition hover:border-slate-400 hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:cursor-not-allowed disabled:opacity-50"
        >
          <Plus aria-hidden="true" size={16} strokeWidth={1.5} />
          Vytvoriť novú anketu
        </button>
      </header>

      {pageError && (
        <div role="alert" className="rounded-lg border border-red-200 bg-red-50 p-3 text-sm font-medium text-red-800">
          <p>{pageError}</p>
          {!isLoading && <button type="button" onClick={() => void loadData(page)} className="mt-2 underline">Skúsiť znova</button>}
        </div>
      )}
      {notice && <p role="status" className="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm font-medium text-emerald-900">{notice}</p>}

      <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        {isLoading ? (
          <p role="status" className="p-5 text-sm text-slate-600">Načítavam ankety…</p>
        ) : polls.length === 0 ? (
          <EmptyTableState />
        ) : (
          <table className="w-full min-w-[58rem] border-collapse text-left text-xs font-medium text-slate-700">
            <thead className="bg-slate-100 text-xs font-semibold text-slate-800">
              <tr className="border-b border-slate-200">
                <th scope="col" className="px-3 py-2">Otázka</th>
                <th scope="col" className="px-3 py-2">Bytový dom</th>
                <th scope="col" className="px-3 py-2">Stav</th>
                <th scope="col" className="px-3 py-2">Hlasov</th>
                <th scope="col" className="px-3 py-2">Platnosť do</th>
                <th scope="col" className="px-3 py-2"><span className="sr-only">Akcie</span></th>
              </tr>
            </thead>
            <tbody>
              {polls.map((poll) => (
                <FragmentRow
                  key={poll.id}
                  poll={poll}
                  propertyName={propertyNames.get(poll.property_id) ?? "Bytový dom"}
                  expanded={expandedPollId === poll.id}
                  busy={busyPollId === poll.id}
                  onToggleResults={() => setExpandedPollId((current) => current === poll.id ? null : poll.id)}
                  onClose={() => void closePoll(poll)}
                  onDelete={() => void deletePoll(poll)}
                />
              ))}
            </tbody>
          </table>
        )}
      </div>

      {!isLoading && totalPages > 1 && (
        <div className="flex items-center justify-between gap-3 text-sm text-slate-600">
          <span>{total} ankiet · Strana {page} z {totalPages}</span>
          <div className="flex gap-2">
            <button
              type="button"
              disabled={page <= 1}
              onClick={() => setPage((current) => current - 1)}
              className="min-h-9 rounded-lg border border-slate-300 px-3 font-semibold disabled:opacity-50"
            >Predchádzajúca</button>
            <button
              type="button"
              disabled={page >= totalPages}
              onClick={() => setPage((current) => current + 1)}
              className="min-h-9 rounded-lg border border-slate-300 px-3 font-semibold disabled:opacity-50"
            >Ďalšia</button>
          </div>
        </div>
      )}

      <dialog
        ref={dialogRef}
        aria-labelledby="manager-poll-form-title"
        className="poll-dialog fixed inset-0 z-50 m-0 h-full max-h-none w-full max-w-none overflow-y-auto bg-slate-950/60 p-4 backdrop:bg-transparent"
        onCancel={(event) => {
          event.preventDefault();
          if (!isSaving) setIsDialogOpen(false);
        }}
      >
        <form onSubmit={createPoll} className="mx-auto my-8 w-full max-w-xl space-y-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-2xl sm:p-6">
          <header className="flex items-start justify-between gap-3">
            <div>
              <h2 id="manager-poll-form-title" className="text-xl font-bold text-primary">Vytvoriť novú anketu</h2>
              <p className="mt-1 text-sm text-slate-600">Anketa sa zobrazí rezidentom vybraného domu.</p>
            </div>
            <button type="button" aria-label="Zavrieť formulár" disabled={isSaving} onClick={() => setIsDialogOpen(false)} className="rounded-lg p-2 text-slate-500 hover:bg-slate-100">
              <X aria-hidden="true" className="size-5" />
            </button>
          </header>

          <label className="block text-sm font-semibold text-slate-800">
            Otázka
            <input
              required
              maxLength={250}
              value={form.question}
              onChange={(event) => setForm((current) => ({ ...current, question: event.target.value }))}
              className="form-input mt-2 block w-full"
            />
          </label>
          <label className="block text-sm font-semibold text-slate-800">
            Popis / kontext
            <textarea
              maxLength={10000}
              rows={4}
              value={form.description}
              onChange={(event) => setForm((current) => ({ ...current, description: event.target.value }))}
              className="form-input mt-2 block w-full resize-y"
            />
          </label>
          <label className="block text-sm font-semibold text-slate-800">
            Bytový dom
            <select
              required
              value={form.property_id}
              onChange={(event) => setForm((current) => ({ ...current, property_id: event.target.value }))}
              className="form-input mt-2 block w-full"
            >
              <option value="">Vyberte bytový dom</option>
              {properties.map((property) => <option key={property.id} value={property.id}>{property.title}</option>)}
            </select>
          </label>
          <label className="block text-sm font-semibold text-slate-800">
            Dátum ukončenia
            <input
              type="date"
              required
              min={new Date().toISOString().slice(0, 10)}
              value={form.expires_on}
              onChange={(event) => setForm((current) => ({ ...current, expires_on: event.target.value }))}
              className="form-input mt-2 block w-full"
            />
            <span className="mt-1 block text-xs font-normal text-slate-500">Platnosť končí na konci zvoleného dňa podľa UTC.</span>
          </label>

          {pageError && <p role="alert" className="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800">{pageError}</p>}
          <div className="flex justify-end gap-2">
            <button type="button" disabled={isSaving} onClick={() => setIsDialogOpen(false)} className="min-h-11 rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">
              Zrušiť
            </button>
            <button type="submit" disabled={isSaving || properties.length === 0} className="inline-flex min-h-11 items-center gap-2 rounded-lg bg-blue-950 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-900 disabled:cursor-wait disabled:opacity-60">
              <Save aria-hidden="true" className="size-4" />
              {isSaving ? "Vytváram…" : "Vytvoriť anketu"}
            </button>
          </div>
        </form>
      </dialog>
    </section>
  );
}

function FragmentRow({
  poll,
  propertyName,
  expanded,
  busy,
  onToggleResults,
  onClose,
  onDelete,
}: {
  poll: Poll;
  propertyName: string;
  expanded: boolean;
  busy: boolean;
  onToggleResults: () => void;
  onClose: () => void;
  onDelete: () => void;
}) {
  return (
    <>
      <tr className="border-b border-slate-100 odd:bg-white even:bg-slate-50/50">
        <th scope="row" className="px-3 py-2 text-left font-semibold text-slate-900">
          <span>{poll.question}</span>
          {poll.description && <span className="mt-1 block max-w-xl whitespace-pre-line font-normal text-slate-600">{poll.description}</span>}
        </th>
        <td className="px-3 py-2">{propertyName}</td>
        <td className="px-3 py-2">
          <span className={poll.status === "active" ? "font-semibold text-emerald-700" : "font-semibold text-slate-500"}>
            {poll.status === "active" ? "Aktívna" : "Ukončená"}
          </span>
        </td>
        <td className="px-3 py-2">{poll.results.total_votes}</td>
        <td className="whitespace-nowrap px-3 py-2">{poll.expires_at > 0 ? formatDate(poll.expires_at) : "—"}</td>
        <td className="px-3 py-1.5">
          <div className="flex items-center gap-1">
            <ActionIconButton action="edit" compact icon={Eye} label={`${expanded ? "Skryť" : "Zobraziť"} výsledky ankety ${poll.question}`} onClick={onToggleResults} />
            {poll.status === "active" && (
              <ActionIconButton action="edit" compact icon={CircleStop} label={`Ukončiť anketu ${poll.question}`} disabled={busy} onClick={onClose} />
            )}
            <ActionIconButton action="delete" compact icon={Trash2} label={`Odstrániť anketu ${poll.question}`} disabled={busy} onClick={onDelete} />
          </div>
        </td>
      </tr>
      {expanded && (
        <tr className="border-b border-slate-200 bg-white">
          <td colSpan={6} className="px-4 py-5 sm:px-6">
            <PollResults results={{ ano: poll.results.ano, nie: poll.results.nie, zdrzal: poll.results.zdrzal_sa }} />
          </td>
        </tr>
      )}
    </>
  );
}
