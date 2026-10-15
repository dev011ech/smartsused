import { useCallback, useEffect, useRef, useState, type FormEvent } from "react";
import axios from "axios";
import { Building2, Pencil, Plus, Save, X } from "lucide-react";
import { apiClient } from "../api/client";
import { ActionIconButton } from "./ActionIconButton";

type House = {
  id: number;
  title: string;
  address: string;
  house_number: string;
  entrance_count: number;
  unit_count: number;
};

type HouseForm = Omit<House, "id">;
const emptyForm: HouseForm = {
  title: "",
  address: "",
  house_number: "",
  entrance_count: 0,
  unit_count: 0,
};

function getErrorMessage(error: unknown) {
  if (axios.isAxiosError<{ message?: string }>(error)) {
    return error.response?.data?.message ?? "Bytové domy sa nepodarilo načítať.";
  }
  return error instanceof Error ? error.message : "Bytové domy sa nepodarilo načítať.";
}

export function AdminHousesPanel() {
  const [houses, setHouses] = useState<House[]>([]);
  const [form, setForm] = useState<HouseForm>(emptyForm);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [isLoading, setIsLoading] = useState(true);
  const [isSaving, setIsSaving] = useState(false);
  const [isFormOpen, setIsFormOpen] = useState(false);
  const [editingHouse, setEditingHouse] = useState<House | null>(null);
  const editDialogRef = useRef<HTMLDialogElement>(null);

  const loadHouses = useCallback(async () => {
    setIsLoading(true);
    setError("");
    try {
      const response = await apiClient.get<House[]>("smartsused/v1/admin/houses");
      if (!Array.isArray(response.data)) throw new Error("Server vrátil neplatný zoznam domov.");
      setHouses(response.data);
    } catch (loadError) {
      setError(getErrorMessage(loadError));
    } finally {
      setIsLoading(false);
    }
  }, []);

  useEffect(() => {
    void loadHouses();
  }, [loadHouses]);

  useEffect(() => {
    const dialog = editDialogRef.current;
    if (!dialog) return;

    if (editingHouse && !dialog.open) dialog.showModal();
    if (!editingHouse && dialog.open) dialog.close();
  }, [editingHouse]);

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setError("");
    setNotice("");
    setIsSaving(true);
    try {
      if (editingHouse) {
        const response = await apiClient.put<House>(`smartsused/v1/admin/houses/${editingHouse.id}`, form);
        setHouses((current) =>
          current
            .map((house) => (house.id === response.data.id ? response.data : house))
            .sort((a, b) => a.title.localeCompare(b.title, "sk")),
        );
        setEditingHouse(null);
        setNotice("Bytový dom bol aktualizovaný.");
      } else {
        const response = await apiClient.post<House>("smartsused/v1/admin/houses", form);
        setHouses((current) => [...current, response.data].sort((a, b) => a.title.localeCompare(b.title, "sk")));
        setForm(emptyForm);
        setIsFormOpen(false);
        setNotice("Bytový dom bol vytvorený.");
      }
    } catch (saveError) {
      setError(getErrorMessage(saveError));
    } finally {
      setIsSaving(false);
    }
  }

  function openEditor(house: House) {
    setForm({
      title: house.title,
      address: house.address,
      house_number: house.house_number,
      entrance_count: house.entrance_count,
      unit_count: house.unit_count,
    });
    setError("");
    setNotice("");
    setEditingHouse(house);
  }

  return (
    <section className="space-y-5" aria-labelledby="admin-houses-heading">
      <header className="flex flex-wrap items-end justify-between gap-3">
        <div>
          <h2 id="admin-houses-heading" className="text-2xl font-bold text-primary">Bytové domy</h2>
          <p className="mt-2 text-sm leading-6 text-slate-700">Domy sú uložené ako publikované objekty SmartSused.</p>
        </div>
        <button
          type="button"
          onClick={() => setIsFormOpen((open) => !open)}
          className="inline-flex min-h-11 items-center gap-2 rounded-lg bg-blue-950 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-900"
        >
          <Plus aria-hidden="true" className="size-4" />
          {isFormOpen ? "Zavrieť formulár" : "Pridať nový bytový dom"}
        </button>
      </header>

      {error && <p role="alert" className="rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-800">{error}</p>}
      {notice && <p role="status" className="rounded-lg border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">{notice}</p>}

      {isFormOpen && (
        <form onSubmit={submit} className="grid gap-4 rounded-2xl border border-slate-300 bg-white p-5 shadow-sm sm:p-7 md:grid-cols-2">
          <Field label="Názov bytového domu" value={form.title} required onChange={(title) => setForm((current) => ({ ...current, title }))} />
          <Field label="Adresa" value={form.address} required onChange={(address) => setForm((current) => ({ ...current, address }))} />
          <Field label="Súpisné číslo" value={form.house_number} onChange={(house_number) => setForm((current) => ({ ...current, house_number }))} />
          <NumberField label="Počet vchodov" value={form.entrance_count} onChange={(entrance_count) => setForm((current) => ({ ...current, entrance_count }))} />
          <NumberField label="Počet bytov" value={form.unit_count} onChange={(unit_count) => setForm((current) => ({ ...current, unit_count }))} />
          <div className="flex items-end">
            <button
              type="submit"
              disabled={isSaving}
              className="inline-flex min-h-11 items-center gap-2 rounded-lg bg-blue-950 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-900 disabled:cursor-wait disabled:opacity-60"
            >
              <Save aria-hidden="true" className="size-4" />
              {isSaving ? "Ukladám…" : "Vytvoriť bytový dom"}
            </button>
          </div>
        </form>
      )}

      <div className="overflow-x-auto rounded-xl border border-slate-300 bg-white">
        <table className="min-w-full divide-y divide-slate-200 text-left text-sm font-medium text-slate-800">
          <thead className="bg-slate-100 text-xs font-semibold text-slate-900">
            <tr><th className="px-4 py-3">Bytový dom</th><th className="px-4 py-3">Adresa</th><th className="px-4 py-3">Súpisné číslo</th><th className="px-4 py-3">Vchody</th><th className="px-4 py-3">Byty</th><th className="px-4 py-3"><span className="sr-only">Akcie</span></th></tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {isLoading ? (
              <tr><td colSpan={6} className="px-4 py-8 text-center text-slate-500">Načítavam bytové domy…</td></tr>
            ) : houses.length === 0 ? (
              <tr><td colSpan={6} className="px-4 py-8 text-center text-slate-500">Zatiaľ nie sú vytvorené žiadne bytové domy.</td></tr>
            ) : houses.map((house) => (
              <tr key={house.id} className="border-b border-slate-100 odd:bg-white even:bg-slate-50/50">
                <td className="px-4 py-3 font-semibold text-slate-900"><Building2 aria-hidden="true" className="mr-2 inline size-4 text-blue-700" />{house.title}</td>
                <td className="px-4 py-3 text-slate-700">{house.address || "—"}</td>
                <td className="px-4 py-3 text-slate-700">{house.house_number || "—"}</td>
                <td className="px-4 py-3 text-slate-700">{house.entrance_count}</td>
                <td className="px-4 py-3 text-slate-700">{house.unit_count}</td>
                <td className="px-4 py-2 text-right">
                  <ActionIconButton
                    action="edit"
                    icon={Pencil}
                    label={`Upraviť bytový dom ${house.title}`}
                    onClick={() => openEditor(house)}
                  />
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <dialog
        ref={editDialogRef}
        aria-labelledby="edit-house-heading"
        onClose={() => setEditingHouse(null)}
        className="m-auto w-[min(42rem,calc(100%-2rem))] rounded-2xl border border-slate-200 bg-white p-0 text-slate-900 shadow-2xl backdrop:bg-slate-950/40"
      >
        <form onSubmit={submit} className="space-y-5 p-5 sm:p-7">
          <header className="flex items-start justify-between gap-4">
            <div>
              <h3 id="edit-house-heading" className="text-xl font-bold text-primary">Upraviť bytový dom</h3>
              <p className="mt-1 text-sm text-slate-600">{editingHouse?.title}</p>
            </div>
            <button
              type="button"
              aria-label="Zavrieť úpravu"
              onClick={() => setEditingHouse(null)}
              className="rounded-lg p-2 text-slate-500 hover:bg-slate-100"
            >
              <X aria-hidden="true" className="size-5" />
            </button>
          </header>
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label="Názov bytového domu" value={form.title} required onChange={(title) => setForm((current) => ({ ...current, title }))} />
            <Field label="Adresa" value={form.address} required onChange={(address) => setForm((current) => ({ ...current, address }))} />
            <Field label="Súpisné číslo" value={form.house_number} onChange={(house_number) => setForm((current) => ({ ...current, house_number }))} />
            <NumberField label="Počet vchodov" value={form.entrance_count} onChange={(entrance_count) => setForm((current) => ({ ...current, entrance_count }))} />
            <NumberField label="Počet bytov" value={form.unit_count} onChange={(unit_count) => setForm((current) => ({ ...current, unit_count }))} />
          </div>
          <div className="flex justify-end gap-2">
            <button
              type="button"
              onClick={() => setEditingHouse(null)}
              className="min-h-11 rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50"
            >
              Zrušiť
            </button>
            <button
              type="submit"
              disabled={isSaving}
              className="inline-flex min-h-11 items-center gap-2 rounded-lg bg-blue-950 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-900 disabled:cursor-wait disabled:opacity-60"
            >
              <Save aria-hidden="true" className="size-4" />
              {isSaving ? "Ukladám…" : "Uložiť zmeny"}
            </button>
          </div>
          {error && <p role="alert" className="rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-800">{error}</p>}
        </form>
      </dialog>
    </section>
  );
}

function Field({ label, value, onChange, required = false }: { label: string; value: string; onChange: (value: string) => void; required?: boolean }) {
  return (
    <label className="block text-sm font-semibold text-slate-800">
      {label}
      <input required={required} value={value} onChange={(event) => onChange(event.target.value)} className="mt-2 block min-h-11 w-full rounded-lg border border-slate-300 px-3 font-normal outline-none focus:border-blue-700 focus:ring-2 focus:ring-blue-100" />
    </label>
  );
}

function NumberField({ label, value, onChange }: { label: string; value: number; onChange: (value: number) => void }) {
  return (
    <label className="block text-sm font-semibold text-slate-800">
      {label}
      <input type="number" min={0} value={value} onChange={(event) => onChange(Number(event.target.value))} className="mt-2 block min-h-11 w-full rounded-lg border border-slate-300 px-3 font-normal outline-none focus:border-blue-700 focus:ring-2 focus:ring-blue-100" />
    </label>
  );
}
