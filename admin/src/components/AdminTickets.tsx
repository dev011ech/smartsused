import { useEffect, useRef, useState, type FormEvent } from "react";
import axios from "axios";
import { Pencil, Trash2 } from "lucide-react";
import { apiClient } from "../api/client";
import { ActionIconButton } from "./ActionIconButton";

export type TicketStatus = "new" | "in_progress" | "resolved";

export type Ticket = {
  id: number;
  title: string;
  description?: string;
  status: TicketStatus;
  unit_id?: number;
  property_id?: number;
  internal_note?: string;
  created_at?: string;
  author_id?: number;
};

type TicketFormState = {
  status: TicketStatus;
  internal_note: string;
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
  return statusOptions.find((option) => option.value === status)?.label ?? "Nový";
}

function formatDate(value?: string) {
  if (!value) {
    return "—";
  }

  const date = new Date(value);
  if (Number.isNaN(date.getTime())) {
    return value;
  }

  return new Intl.DateTimeFormat("sk-SK", {
    day: "2-digit",
    month: "2-digit",
    year: "numeric",
  }).format(date);
}

function getErrorMessage(error: unknown) {
  if (axios.isAxiosError<{ message?: string }>(error)) {
    return error.response?.data?.message ?? "Požiadavku sa nepodarilo dokončiť.";
  }

  return error instanceof Error ? error.message : "Požiadavku sa nepodarilo dokončiť.";
}

export function AdminTickets() {
  const dialogRef = useRef<HTMLDialogElement>(null);
  const [tickets, setTickets] = useState<Ticket[]>([]);
  const [selectedTicket, setSelectedTicket] = useState<Ticket | null>(null);
  const [form, setForm] = useState<TicketFormState>({
    status: "new",
    internal_note: "",
  });
  const [isLoading, setIsLoading] = useState(true);
  const [isSaving, setIsSaving] = useState(false);
  const [isDeleting, setIsDeleting] = useState<number | null>(null);
  const [isDialogOpen, setIsDialogOpen] = useState(false);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");

  const loadTickets = async () => {
    setIsLoading(true);
    setError("");

    try {
      const response = await apiClient.get<{ tickets?: Ticket[] }>("smartsused/v1/tickets/list");
      setTickets(Array.isArray(response.data.tickets) ? response.data.tickets : []);
    } catch (requestError) {
      setError(getErrorMessage(requestError));
    } finally {
      setIsLoading(false);
    }
  };

  useEffect(() => {
    void loadTickets();
  }, []);

  useEffect(() => {
    const dialog = dialogRef.current;
    if (!dialog) {
      return;
    }

    if (isDialogOpen) {
      dialog.showModal();
      return;
    }

    dialog.close();
  }, [isDialogOpen]);

  const openEditDialog = (ticket: Ticket) => {
    setSelectedTicket(ticket);
    setForm({
      status: ticket.status,
      internal_note: ticket.internal_note ?? "",
    });
    setError("");
    setNotice("");
    setIsDialogOpen(true);
  };

  const closeEditDialog = () => {
    setIsDialogOpen(false);
    setSelectedTicket(null);
    setForm({ status: "new", internal_note: "" });
  };

  const handleSaveTicket = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();

    if (!selectedTicket) {
      return;
    }

    setIsSaving(true);
    setError("");
    setNotice("");

    try {
      await apiClient.put("smartsused/v1/tickets/update", {
        ticket_id: selectedTicket.id,
        status: form.status,
        internal_note: form.internal_note.trim(),
      });

      setTickets((currentTickets) =>
        currentTickets.map((ticket) =>
          ticket.id === selectedTicket.id
            ? {
                ...ticket,
                status: form.status,
                internal_note: form.internal_note.trim(),
              }
            : ticket,
        ),
      );

      setNotice("Zmeny boli uložené.");
      closeEditDialog();
    } catch (requestError) {
      setError(getErrorMessage(requestError));
    } finally {
      setIsSaving(false);
    }
  };

  const handleDeleteTicket = async (ticket: Ticket) => {
    const confirmed = window.confirm(`Naozaj chcete vymazať tiket „${ticket.title}“?`);
    if (!confirmed) {
      return;
    }

    setIsDeleting(ticket.id);
    setError("");
    setNotice("");

    try {
      await apiClient.delete(`smartsused/v1/tickets/${ticket.id}`);
      setTickets((currentTickets) => currentTickets.filter((item) => item.id !== ticket.id));
      setNotice("Tiketa bola odstránená.");
    } catch (requestError) {
      const axiosError = requestError as { response?: { status?: number } };
      const status = axiosError.response?.status;

      if (status === 404 || status === 405) {
        setTickets((currentTickets) => currentTickets.filter((item) => item.id !== ticket.id));
        setNotice("Tiketa bola odstránená z lokálneho zoznamu. Backend delete endpoint ešte nie je dostupný.");
        return;
      }

      setError(getErrorMessage(requestError));
    } finally {
      setIsDeleting(null);
    }
  };

  return (
    <section className="space-y-5">
      <header className="flex items-center justify-between gap-3">
        <div>
          <p className="text-sm font-semibold text-blue-800">SmartSused · Poruchy</p>
          <h1 className="mt-1 text-2xl font-bold tracking-tight text-slate-900">Správa porúch</h1>
        </div>
      </header>

      {error && (
        <div className="rounded-lg border border-red-200 bg-red-50 p-3 text-sm font-medium text-red-700" role="alert">
          {error}
        </div>
      )}

      {notice && (
        <div className="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm font-medium text-emerald-700" role="status">
          {notice}
        </div>
      )}

      <div className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
        {isLoading ? (
          <div className="flex items-center justify-center p-8 text-sm text-slate-500">Načítavam poruchy…</div>
        ) : tickets.length === 0 ? (
          <div className="p-8 text-center text-sm text-slate-500">Žiadne poruchy pre zobrazenie.</div>
        ) : (
          <div className="overflow-x-auto">
            <table className="min-w-full border-collapse text-left text-xs text-slate-700">
              <thead className="bg-slate-100 text-slate-800">
                <tr>
                  <th scope="col" className="px-3 py-1.5 font-semibold">Dátum</th>
                  <th scope="col" className="px-3 py-1.5 font-semibold">Vchod/Byt</th>
                  <th scope="col" className="px-3 py-1.5 font-semibold">Názov poruchy</th>
                  <th scope="col" className="px-3 py-1.5 font-semibold">Stav</th>
                  <th scope="col" className="px-3 py-1.5 text-right font-semibold">Akcie</th>
                </tr>
              </thead>
              <tbody>
                {tickets.map((ticket) => (
                  <tr key={ticket.id} className="border-t border-slate-200 hover:bg-slate-50/80">
                    <td className="px-3 py-1.5 text-slate-600">{formatDate(ticket.created_at)}</td>
                    <td className="px-3 py-1.5">
                      <span className="inline-flex rounded-md border border-slate-200 bg-slate-50 px-2 py-1 font-medium text-slate-700">
                        {ticket.unit_id ? `Vchod / Byt ${ticket.unit_id}` : "—"}
                      </span>
                    </td>
                    <td className="px-3 py-1.5 font-medium text-slate-800">{ticket.title || "Bez názvu"}</td>
                    <td className="px-3 py-1.5">
                      <span
                        className={`inline-flex rounded-full px-2 py-1 text-[11px] font-semibold ${statusClasses[ticket.status]}`}
                      >
                        {getStatusLabel(ticket.status)}
                      </span>
                    </td>
                    <td className="px-3 py-1.5">
                      <div className="flex items-center justify-end gap-1">
                        <ActionIconButton
                          action="edit"
                          compact
                          icon={Pencil}
                          label="Upraviť stav poruchy"
                          onClick={() => openEditDialog(ticket)}
                        />
                        <ActionIconButton
                          action="delete"
                          compact
                          icon={Trash2}
                          label="Vymazať tiket"
                          onClick={() => void handleDeleteTicket(ticket)}
                          disabled={isDeleting === ticket.id}
                        />
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>

      <dialog
        ref={dialogRef}
        className="m-auto w-full max-w-lg rounded-2xl border border-slate-200 bg-white p-0 shadow-2xl backdrop:bg-slate-900/30"
        onClose={closeEditDialog}
      >
        {selectedTicket && (
          <form onSubmit={handleSaveTicket} className="overflow-hidden rounded-2xl">
            <div className="border-b border-slate-200 bg-slate-50 px-5 py-4">
              <div className="flex items-center justify-between gap-3">
                <div>
                  <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Porucha</p>
                  <h2 className="mt-1 text-lg font-bold text-slate-900">{selectedTicket.title}</h2>
                </div>
                <button
                  type="button"
                  onClick={closeEditDialog}
                  className="rounded-lg border border-slate-200 bg-white px-2.5 py-1.5 text-sm font-medium text-slate-600 hover:bg-slate-100"
                >
                  Zavrieť
                </button>
              </div>
            </div>

            <div className="space-y-5 p-5">
              <div className="space-y-2">
                <label htmlFor="ticket-status" className="text-sm font-medium text-slate-700">
                  Stav poruchy
                </label>
                <select
                  id="ticket-status"
                  value={form.status}
                  onChange={(event) => setForm((currentForm) => ({ ...currentForm, status: event.target.value as TicketStatus }))}
                  className="w-full rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-800 shadow-sm transition focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100"
                >
                  {statusOptions.map((option) => (
                    <option key={option.value} value={option.value}>
                      {option.label}
                    </option>
                  ))}
                </select>
              </div>

              <div className="space-y-2">
                <label htmlFor="ticket-note" className="text-sm font-medium text-slate-700">
                  Interná poznámka
                </label>
                <textarea
                  id="ticket-note"
                  rows={5}
                  value={form.internal_note}
                  onChange={(event) => setForm((currentForm) => ({ ...currentForm, internal_note: event.target.value }))}
                  placeholder="Napr. Opravár výťahov objednaný na vchod B"
                  className="w-full resize-none rounded-xl border border-slate-300 bg-white px-3 py-2.5 text-sm text-slate-800 shadow-sm transition placeholder:text-slate-400 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100"
                />
              </div>
            </div>

            <div className="flex items-center justify-end gap-3 border-t border-slate-200 bg-slate-50 px-5 py-4">
              <button
                type="button"
                onClick={closeEditDialog}
                className="rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 hover:bg-slate-100"
              >
                Zrušiť
              </button>
              <button
                type="submit"
                disabled={isSaving}
                className="rounded-lg bg-blue-700 px-3 py-2 text-sm font-semibold text-white transition hover:bg-blue-800 disabled:cursor-not-allowed disabled:opacity-60"
              >
                {isSaving ? "Ukladám…" : "Uložiť zmeny"}
              </button>
            </div>
          </form>
        )}
      </dialog>
    </section>
  );
}

export default AdminTickets;
