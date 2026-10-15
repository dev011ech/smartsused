import { useEffect, useState, type FormEvent } from "react";
import axios from "axios";
import { apiClient } from "../api/client";

export type TicketStatus = "new" | "in_progress" | "resolved";

type Ticket = {
  id: number;
  title: string;
  description?: string;
  status: TicketStatus;
  unit_id?: number;
  property_id?: number;
  created_at?: string;
};

type UnitOption = {
  id: number;
  title?: { rendered?: string; raw?: string };
  name?: string;
  slug?: string;
  unit_number?: string;
};

type FormState = {
  title: string;
  description: string;
  unit_id: string;
};

const statusOptions: Array<{ value: TicketStatus; label: string }> = [
  { value: "new", label: "Nový" },
  { value: "in_progress", label: "V riešení" },
  { value: "resolved", label: "Ukončené" },
];

const statusClasses: Record<TicketStatus, string> = {
  new: "text-red-600 bg-red-50 border border-red-200",
  in_progress: "text-amber-600 bg-amber-50 border border-amber-200",
  resolved: "text-emerald-600 bg-emerald-50 border border-emerald-200",
};

function getStatusLabel(status: TicketStatus) {
  const match = statusOptions.find((option) => option.value === status);
  return match?.label ?? "Nový";
}

function formatDate(value?: string) {
  if (!value) {
    return "—";
  }

  const parsed = new Date(value);
  if (Number.isNaN(parsed.getTime())) {
    return value;
  }

  return new Intl.DateTimeFormat("sk-SK", {
    day: "2-digit",
    month: "2-digit",
    year: "numeric",
  }).format(parsed);
}

function getErrorMessage(error: unknown) {
  if (axios.isAxiosError<{ message?: string }>(error)) {
    return error.response?.data?.message ?? "Požiadavku sa nepodarilo dokončiť.";
  }

  return error instanceof Error ? error.message : "Požiadavku sa nepodarilo dokončiť.";
}

export function ResidentTickets() {
  const [tickets, setTickets] = useState<Ticket[]>([]);
  const [units, setUnits] = useState<UnitOption[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [isSaving, setIsSaving] = useState(false);
  const [error, setError] = useState("");
  const [success, setSuccess] = useState("");
  const [form, setForm] = useState<FormState>({
    title: "",
    description: "",
    unit_id: "",
  });

  const loadTickets = async () => {
    setError("");
    try {
      const response = await apiClient.get<{ tickets?: Ticket[] }>("smartsused/v1/tickets/list");
      setTickets(Array.isArray(response.data.tickets) ? response.data.tickets : []);
    } catch (requestError) {
      setError(getErrorMessage(requestError));
    }
  };

  const loadUnits = async () => {
    try {
      const response = await apiClient.get<UnitOption[]>("wp/v2/smartsused_unit", {
        params: { per_page: 100, context: "view" },
      });
      setUnits(Array.isArray(response.data) ? response.data : []);
    } catch {
      setUnits([]);
    }
  };

  useEffect(() => {
    void Promise.all([loadTickets(), loadUnits()]).finally(() => setIsLoading(false));
  }, []);

  const handleSubmit = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();

    if (!form.title.trim() || !form.description.trim() || !form.unit_id) {
      setError("Vyplňte názov, popis poruchy a vyberte byt.");
      return;
    }

    setIsSaving(true);
    setError("");
    setSuccess("");

    try {
      await apiClient.post("smartsused/v1/tickets/create", {
        title: form.title.trim(),
        description: form.description.trim(),
        unit_id: Number(form.unit_id),
      });

      setForm({ title: "", description: "", unit_id: "" });
      setSuccess("Porucha bola úspešne nahlásená.");
      await loadTickets();
    } catch (requestError) {
      setError(getErrorMessage(requestError));
    } finally {
      setIsSaving(false);
    }
  };

  return (
    <section className="mt-8 space-y-6 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
      <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <p className="text-sm font-semibold text-blue-700">SmartSused · Poruchy</p>
          <h2 className="mt-1 text-2xl font-bold tracking-tight text-slate-900">Nahlásené poruchy</h2>
        </div>
      </div>

      {error && (
        <div className="rounded-lg border border-red-200 bg-red-50 p-3 text-sm font-medium text-red-700" role="alert">
          {error}
        </div>
      )}

      {success && (
        <div className="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm font-medium text-emerald-700" role="status">
          {success}
        </div>
      )}

      <form onSubmit={handleSubmit} className="grid gap-4 rounded-xl border border-slate-200 bg-slate-50 p-4 md:grid-cols-2">
        <div className="space-y-2 md:col-span-2">
          <label htmlFor="ticket-title" className="text-sm font-medium text-slate-700">
            Názov poruchy
          </label>
          <input
            id="ticket-title"
            value={form.title}
            onChange={(event) => setForm((current) => ({ ...current, title: event.target.value }))}
            className="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-800 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100"
            placeholder="Napíšte krátky popis problému"
          />
        </div>

        <div className="space-y-2 md:col-span-2">
          <label htmlFor="ticket-description" className="text-sm font-medium text-slate-700">
            Popis poruchy
          </label>
          <textarea
            id="ticket-description"
            rows={4}
            value={form.description}
            onChange={(event) => setForm((current) => ({ ...current, description: event.target.value }))}
            className="w-full resize-none rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-800 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100"
            placeholder="Opíšte, čo sa pokazuje, kde a kedy sa to deje."
          />
        </div>

        <div className="space-y-2 md:col-span-2">
          <label htmlFor="ticket-unit" className="text-sm font-medium text-slate-700">
            Vyberte byt
          </label>
          <select
            id="ticket-unit"
            value={form.unit_id}
            onChange={(event) => setForm((current) => ({ ...current, unit_id: event.target.value }))}
            className="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-800 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100"
          >
            <option value="">Vyberte byt</option>
            {units.map((unit) => {
              const title = unit.title?.rendered || unit.title?.raw || unit.name || unit.slug || `Byt ${unit.id}`;
              return (
                <option key={unit.id} value={String(unit.id)}>
                  {title}
                </option>
              );
            })}
          </select>
        </div>

        <div className="md:col-span-2 flex justify-end">
          <button
            type="submit"
            disabled={isSaving}
            className="inline-flex min-h-10 items-center justify-center rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white transition hover:bg-blue-800 disabled:cursor-not-allowed disabled:opacity-60"
          >
            {isSaving ? "Odosielam…" : "Nahlásiť poruchu"}
          </button>
        </div>
      </form>

      <div className="overflow-hidden rounded-xl border border-slate-200 bg-white">
        {isLoading ? (
          <div className="p-6 text-center text-sm text-slate-500">Načítavam vaše poruchy…</div>
        ) : tickets.length === 0 ? (
          <div className="p-6 text-center text-sm text-slate-500">Nemáte zatiaľ nahlásené žiadne poruchy.</div>
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-full border-collapse text-left text-xs text-slate-700">
              <thead className="bg-slate-100 text-slate-800">
                <tr>
                  <th scope="col" className="px-3 py-1.5 font-semibold">Dátum</th>
                  <th scope="col" className="px-3 py-1.5 font-semibold">Byt</th>
                  <th scope="col" className="px-3 py-1.5 font-semibold">Názov poruchy</th>
                  <th scope="col" className="px-3 py-1.5 font-semibold">Stav</th>
                </tr>
              </thead>
              <tbody>
                {tickets.map((ticket) => (
                  <tr key={ticket.id} className="border-t border-slate-200 hover:bg-slate-50/90">
                    <td className="px-3 py-1.5 text-slate-600">{formatDate(ticket.created_at)}</td>
                    <td className="px-3 py-1.5 font-medium text-slate-700">{ticket.unit_id ?? "—"}</td>
                    <td className="px-3 py-1.5 font-medium text-slate-800">{ticket.title || "Bez názvu"}</td>
                    <td className="px-3 py-1.5">
                      <span className={`inline-flex rounded-full px-2 py-1 text-[11px] font-semibold ${statusClasses[ticket.status]}`}>
                        {getStatusLabel(ticket.status)}
                      </span>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </section>
  );
}

export default ResidentTickets;
