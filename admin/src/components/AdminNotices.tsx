import { useCallback, useEffect, useMemo, useRef, useState, type FormEvent } from "react";
import axios from "axios";
import { Pencil, Plus, Trash2 } from "lucide-react";
import { apiClient } from "../api/client";
import { ActionIconButton } from "./ActionIconButton";
import { EmptyTableState } from "./EmptyTableState";

type Property = {
  id: number;
  title: { raw?: string; rendered: string };
};

type Unit = {
  id: number;
  property_id?: number;
  entrance?: string;
};

type NoticeScope = "single_property" | "single_entrance";

type Notice = {
  id: number;
  title: string;
  content: string;
  notice_scope: NoticeScope;
  property_id: number;
  entrance: string;
  is_critical: boolean;
  date: string;
};

type NoticeForm = {
  title: string;
  content: string;
  notice_scope: NoticeScope;
  property_id: string;
  entrance: string;
  is_critical: boolean;
};

const emptyForm: NoticeForm = {
  title: "",
  content: "",
  notice_scope: "single_property",
  property_id: "",
  entrance: "",
  is_critical: false,
};

function postTitle(property: Property) {
  return property.title.raw ?? property.title.rendered;
}

function getErrorMessage(error: unknown) {
  if (axios.isAxiosError<{ message?: string }>(error)) {
    return error.response?.data?.message ?? "Požiadavku sa nepodarilo dokončiť. Skúste to znova.";
  }

  return error instanceof Error ? error.message : "Požiadavku sa nepodarilo dokončiť. Skúste to znova.";
}

async function fetchAll<T>(path: string) {
  const items: T[] = [];
  let page = 1;
  let totalPages = 1;

  do {
    const response = await apiClient.get<T[]>(path, {
      params: { context: "edit", orderby: "title", order: "asc", per_page: 100, page },
    });
    items.push(...response.data);
    totalPages = Number(response.headers["x-wp-totalpages"]) || 1;
    page += 1;
  } while (page <= totalPages);

  return items;
}

export function AdminNotices() {
  const dialogRef = useRef<HTMLDialogElement>(null);
  const [notices, setNotices] = useState<Notice[]>([]);
  const [properties, setProperties] = useState<Property[]>([]);
  const [units, setUnits] = useState<Unit[]>([]);
  const [form, setForm] = useState<NoticeForm>(emptyForm);
  const [editingNotice, setEditingNotice] = useState<Notice | null>(null);
  const [isOpen, setIsOpen] = useState(false);
  const [isLoading, setIsLoading] = useState(true);
  const [isSaving, setIsSaving] = useState(false);
  const [pageError, setPageError] = useState("");
  const [formError, setFormError] = useState("");
  const [noticeMessage, setNoticeMessage] = useState("");

  const loadData = useCallback(async () => {
    setIsLoading(true);
    setPageError("");
    try {
      const [noticeResponse, propertyPosts, unitPosts] = await Promise.all([
        apiClient.get<{ notices: Notice[] }>("smartsused/v1/notices/manage"),
        fetchAll<Property>("wp/v2/smartsused_property"),
        fetchAll<Unit>("wp/v2/smartsused_unit"),
      ]);
      if (!Array.isArray(noticeResponse.data.notices)) {
        throw new Error("Server vrátil neplatný zoznam oznamov.");
      }
      setNotices(noticeResponse.data.notices);
      setProperties(propertyPosts);
      setUnits(unitPosts);
    } catch (error) {
      setPageError(getErrorMessage(error));
    } finally {
      setIsLoading(false);
    }
  }, []);

  useEffect(() => {
    void loadData();
  }, [loadData]);

  useEffect(() => {
    const dialog = dialogRef.current;
    if (!dialog) return;
    if (isOpen && !dialog.open) dialog.showModal();
    if (!isOpen && dialog.open) dialog.close();
  }, [isOpen]);

  const entrances = useMemo(() => {
    const propertyId = Number(form.property_id);
    return Array.from(new Set(
      units
        .filter((unit) => unit.property_id === propertyId && unit.entrance?.trim())
        .map((unit) => unit.entrance!.trim()),
    )).sort((left, right) => left.localeCompare(right, "sk"));
  }, [form.property_id, units]);
  const propertyNames = useMemo(
    () => new Map(properties.map((property) => [property.id, postTitle(property)])),
    [properties],
  );

  function openCreateForm() {
    setEditingNotice(null);
    setFormError("");
    setNoticeMessage("");
    setForm({ ...emptyForm, property_id: properties.length === 1 ? String(properties[0].id) : "" });
    setIsOpen(true);
  }

  function openEditForm(notice: Notice) {
    setEditingNotice(notice);
    setFormError("");
    setNoticeMessage("");
    setForm({
      title: notice.title,
      content: notice.content,
      notice_scope: notice.notice_scope,
      property_id: String(notice.property_id),
      entrance: notice.entrance,
      is_critical: notice.is_critical,
    });
    setIsOpen(true);
  }

  async function saveNotice(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (isSaving) return;

    setIsSaving(true);
    setFormError("");
    const payload = {
      title: form.title.trim(),
      content: form.content.trim(),
      notice_scope: form.notice_scope,
      property_id: Number(form.property_id),
      entrance: form.notice_scope === "single_entrance" ? form.entrance : "",
      is_critical: form.is_critical,
    };

    try {
      if (editingNotice) {
        await apiClient.put(`smartsused/v1/notices/${editingNotice.id}`, payload);
        setNoticeMessage("Oznam bol upravený.");
      } else {
        await apiClient.post("smartsused/v1/notices/create", payload);
        setNoticeMessage("Oznam bol zverejnený.");
      }
      setIsOpen(false);
      await loadData();
    } catch (error) {
      setFormError(getErrorMessage(error));
    } finally {
      setIsSaving(false);
    }
  }

  async function deleteNotice(notice: Notice) {
    if (!window.confirm(`Naozaj chcete odstrániť oznam „${notice.title}“?`)) return;
    setPageError("");
    setNoticeMessage("");
    try {
      await apiClient.delete(`smartsused/v1/notices/${notice.id}`);
      setNoticeMessage("Oznam bol odstránený.");
      await loadData();
    } catch (error) {
      setPageError(getErrorMessage(error));
    }
  }

  return (
    <section className="space-y-5">
      <header className="flex flex-wrap items-center justify-between gap-3">
        <div>
          <p className="text-sm font-semibold text-blue-800">SmartSused · Správa</p>
          <h1 className="mt-1 text-2xl font-bold tracking-tight text-primary">Digitálna nástenka</h1>
        </div>
        <button
          type="button"
          onClick={openCreateForm}
          disabled={properties.length === 0}
          className="inline-flex min-h-10 items-center justify-center gap-2 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 transition hover:border-slate-400 hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:cursor-not-allowed disabled:opacity-50"
        >
          <Plus aria-hidden="true" size={16} strokeWidth={1.5} />
          Nový oznam
        </button>
      </header>

      {pageError && <p role="alert" className="rounded-lg border border-red-200 bg-red-50 p-3 text-sm font-medium text-red-800">{pageError}</p>}
      {noticeMessage && <p role="status" className="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm font-medium text-emerald-900">{noticeMessage}</p>}

      <div className="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        {notices.length > 0 ? (
          <table className="w-full min-w-[48rem] border-collapse text-left text-xs font-medium text-slate-700">
            <thead className="bg-slate-100 text-xs font-semibold text-slate-800">
              <tr className="border-b border-slate-200">
                <th scope="col" className="px-3 py-1.5">Dátum</th>
                <th scope="col" className="px-3 py-1.5">Nadpis</th>
                <th scope="col" className="px-3 py-1.5">Rozsah</th>
                <th scope="col" className="px-3 py-1.5">Typ</th>
                <th scope="col" className="px-3 py-1.5">Akcie</th>
              </tr>
            </thead>
            <tbody>
              {notices.map((notice) => (
                <tr key={notice.id} className="border-b border-slate-100 odd:bg-white even:bg-slate-50/50">
                  <td className="whitespace-nowrap px-3 py-1.5">
                    {new Intl.DateTimeFormat("sk-SK", { dateStyle: "medium" }).format(new Date(notice.date))}
                  </td>
                  <th scope="row" className="px-3 py-1.5 text-left font-semibold text-slate-900">{notice.title}</th>
                  <td className="px-3 py-1.5">
                    {notice.notice_scope === "single_entrance"
                      ? `${propertyNames.get(notice.property_id) ?? "Bytový dom"} · vchod ${notice.entrance}`
                      : `${propertyNames.get(notice.property_id) ?? "Bytový dom"} · celý dom`}
                  </td>
                  <td className="px-3 py-1.5">
                    {notice.is_critical ? (
                      <span className="font-semibold text-red-700">Kritický</span>
                    ) : "Bežný"}
                  </td>
                  <td className="px-3 py-1.5">
                    <div className="flex items-center gap-1">
                      <ActionIconButton action="edit" compact icon={Pencil} label={`Upraviť oznam ${notice.title}`} onClick={() => openEditForm(notice)} />
                      <ActionIconButton action="delete" compact icon={Trash2} label={`Odstrániť oznam ${notice.title}`} onClick={() => void deleteNotice(notice)} />
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        ) : !isLoading ? (
          <EmptyTableState />
        ) : (
          <p role="status" className="p-5 text-sm text-slate-600">Načítavam oznamy…</p>
        )}
      </div>

      <dialog
        ref={dialogRef}
        aria-labelledby="admin-notice-form-title"
        className="fixed inset-0 z-50 m-0 h-full max-h-none w-full max-w-none overflow-y-auto bg-slate-950/60 p-4 backdrop:bg-transparent"
        onCancel={(event) => {
          event.preventDefault();
          if (!isSaving) setIsOpen(false);
        }}
      >
        <form
          className="mx-auto my-8 w-full max-w-xl space-y-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-2xl sm:p-6"
          onSubmit={saveNotice}
        >
          <div>
            <h2 id="admin-notice-form-title" className="text-xl font-bold text-primary">
              {editingNotice ? "Upraviť oznam" : "Nový oznam"}
            </h2>
            <p className="mt-1 text-sm text-slate-600">Oznam sa zverejní pre vybraný bytový dom alebo vchod.</p>
          </div>

          <label className="block text-sm font-medium text-slate-800">
            Nadpis oznamu
            <input
              required
              maxLength={200}
              className="form-input mt-1.5 w-full text-sm"
              value={form.title}
              onChange={(event) => setForm((current) => ({ ...current, title: event.target.value }))}
            />
          </label>

          <label className="block text-sm font-medium text-slate-800">
            Obsah oznamu
            <textarea
              required
              rows={5}
              className="form-input mt-1.5 w-full resize-y text-sm"
              value={form.content}
              onChange={(event) => setForm((current) => ({ ...current, content: event.target.value }))}
            />
          </label>

          <fieldset className="space-y-2">
            <legend className="text-sm font-medium text-slate-800">Cieľová skupina</legend>
            <label className="flex items-center gap-2 text-sm text-slate-700">
              <input
                type="radio"
                name="notice-scope"
                checked={form.notice_scope === "single_property"}
                onChange={() => setForm((current) => ({ ...current, notice_scope: "single_property", entrance: "" }))}
              />
              Celý dom
            </label>
            <label className="flex items-center gap-2 text-sm text-slate-700">
              <input
                type="radio"
                name="notice-scope"
                checked={form.notice_scope === "single_entrance"}
                onChange={() => setForm((current) => ({ ...current, notice_scope: "single_entrance" }))}
              />
              Konkrétny vchod
            </label>
          </fieldset>

          {properties.length > 1 && (
            <label className="block text-sm font-medium text-slate-800">
              Bytový dom
              <select
                required
                className="form-input mt-1.5 w-full text-sm"
                value={form.property_id}
                onChange={(event) => setForm((current) => ({ ...current, property_id: event.target.value, entrance: "" }))}
              >
                <option value="">Vyberte bytový dom</option>
                {properties.map((property) => (
                  <option key={property.id} value={property.id}>{postTitle(property)}</option>
                ))}
              </select>
            </label>
          )}

          {form.notice_scope === "single_entrance" && (
            <label className="block text-sm font-medium text-slate-800">
              Vchod
              <select
                required
                className="form-input mt-1.5 w-full text-sm"
                value={form.entrance}
                onChange={(event) => setForm((current) => ({ ...current, entrance: event.target.value }))}
              >
                <option value="">Vyberte vchod</option>
                {entrances.map((entrance) => <option key={entrance} value={entrance}>{entrance}</option>)}
              </select>
              {form.property_id && entrances.length === 0 && (
                <span className="mt-1 block text-xs text-amber-800">V tomto dome nie sú evidované vchody.</span>
              )}
            </label>
          )}

          <label className="flex items-start gap-2 text-sm text-slate-700">
            <input
              type="checkbox"
              className="mt-0.5"
              checked={form.is_critical}
              onChange={(event) => setForm((current) => ({ ...current, is_critical: event.target.checked }))}
            />
            <span>Kritický oznam (Odoslať e-mailovú notifikáciu)</span>
          </label>

          {formError && <p role="alert" className="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800">{formError}</p>}

          <div className="flex flex-wrap justify-end gap-2 pt-2">
            <button
              type="button"
              disabled={isSaving}
              onClick={() => setIsOpen(false)}
              className="min-h-10 rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50 disabled:opacity-50"
            >
              Zrušiť
            </button>
            <button type="submit" className="btn-primary min-h-10" disabled={isSaving || !properties.length}>
              {isSaving ? "Ukladá sa…" : editingNotice ? "Uložiť zmeny" : "Zverejniť oznam"}
            </button>
          </div>
        </form>
      </dialog>
    </section>
  );
}
