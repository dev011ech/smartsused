import { useCallback, useEffect, useRef, useState, type FormEvent } from "react";
import axios from "axios";
import { Pencil, Plus, Trash2, X } from "lucide-react";
import { useAuth } from "../auth/AuthContext";
import { apiClient } from "../api/client";
import { ActionIconButton } from "./ActionIconButton";
import { EmptyTableState } from "./EmptyTableState";

type Revision = {
  id: number;
  title: string;
  property_id: number;
  revision_type: string;
  last_date: string;
  next_due_date: string;
  status: string;
};

type Vendor = {
  id: number;
  title: string;
  property_id: number;
  vendor_type: string;
  phone: string;
  email: string;
};

type Property = {
  id: number;
  title: { raw?: string; rendered: string };
};

type RevisionForm = {
  property_id: string;
  revision_type: string;
  last_date: string;
  next_due_date: string;
};

type VendorForm = {
  property_id: string;
  title: string;
  vendor_type: string;
  phone: string;
  email: string;
};

type ActiveEditor =
  | { kind: "revision"; record: Revision | null }
  | { kind: "vendor"; record: Vendor | null };

const technicalApi = "smartsused/v1/technical";
const vendorTypes = ["Havarijná služba", "Údržba", "Výťahy", "Upratovanie"];

const emptyRevisionForm: RevisionForm = {
  property_id: "",
  revision_type: "",
  last_date: "",
  next_due_date: "",
};

const emptyVendorForm: VendorForm = {
  property_id: "",
  title: "",
  vendor_type: vendorTypes[0],
  phone: "",
  email: "",
};

function getErrorMessage(error: unknown) {
  if (axios.isAxiosError<{ message?: string }>(error)) {
    return error.response?.data?.message ?? "Požiadavku sa nepodarilo dokončiť. Skúste to znova.";
  }

  return error instanceof Error
    ? error.message
    : "Požiadavku sa nepodarilo dokončiť. Skúste to znova.";
}

function formatDate(value: string) {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);
  if (!match) return value || "—";
  return `${Number(match[3])}. ${Number(match[2])}. ${match[1]}`;
}

function isRevisionExpired(revision: Revision) {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(revision.next_due_date)) {
    return revision.status === "EXSPIROVANÉ";
  }

  const now = new Date();
  const today = `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, "0")}-${String(now.getDate()).padStart(2, "0")}`;
  return revision.next_due_date < today;
}

function phoneHref(phone: string) {
  const normalized = phone.replace(/[^\d+]/g, "").replace(/(?!^)\+/g, "");
  return normalized ? `tel:${normalized}` : undefined;
}

function propertyTitle(property: Property) {
  return property.title.raw ?? property.title.rendered;
}

function TableLoadError({ onRetry }: { onRetry: () => void }) {
  return (
    <div className="space-y-2 p-4 text-sm">
      <p className="font-medium text-red-800">Údaje sa nepodarilo načítať.</p>
      <button
        type="button"
        onClick={onRetry}
        className="font-semibold text-blue-800 underline underline-offset-2 hover:text-blue-950 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
      >
        Skúsiť znova
      </button>
    </div>
  );
}

function PropertySelect({
  properties,
  value,
  onChange,
}: {
  properties: Property[];
  value: string;
  onChange: (value: string) => void;
}) {
  if (properties.length <= 1) return null;

  return (
    <label className="block text-sm font-medium text-slate-800">
      Bytový dom
      <select
        required
        className="form-input mt-1.5 w-full text-sm"
        value={value}
        onChange={(event) => onChange(event.target.value)}
      >
        <option value="">Vyberte bytový dom</option>
        {properties.map((property) => (
          <option key={property.id} value={property.id}>
            {propertyTitle(property)}
          </option>
        ))}
      </select>
    </label>
  );
}

export function TechnicalPassport() {
  const { user } = useAuth();
  const dialogRef = useRef<HTMLDialogElement>(null);
  const isManager = user?.roles.includes("smartsused_manager") ?? false;
  const isOwner = user?.roles.includes("smartsused_owner") ?? false;
  const [revisions, setRevisions] = useState<Revision[]>([]);
  const [vendors, setVendors] = useState<Vendor[]>([]);
  const [properties, setProperties] = useState<Property[]>([]);
  const [revisionForm, setRevisionForm] = useState<RevisionForm>(emptyRevisionForm);
  const [vendorForm, setVendorForm] = useState<VendorForm>(emptyVendorForm);
  const [editor, setEditor] = useState<ActiveEditor | null>(null);
  const [isOpen, setIsOpen] = useState(false);
  const [isLoading, setIsLoading] = useState(true);
  const [isSaving, setIsSaving] = useState(false);
  const [pageError, setPageError] = useState("");
  const [formError, setFormError] = useState("");
  const [message, setMessage] = useState("");

  const loadData = useCallback(async () => {
    setIsLoading(true);
    setPageError("");

    try {
      const [revisionResponse, vendorResponse, propertyResponse] = await Promise.all([
        isManager
          ? apiClient.get<{ revisions: Revision[] }>(`${technicalApi}/revisions`)
          : Promise.resolve(null),
        isManager || isOwner
          ? apiClient.get<{ vendors: Vendor[] }>(`${technicalApi}/vendors`)
          : Promise.resolve(null),
        isManager
          ? apiClient.get<Property[]>("wp/v2/smartsused_property", {
              params: { context: "edit", orderby: "title", order: "asc", per_page: 100 },
            })
          : Promise.resolve(null),
      ]);

      if (isManager && !Array.isArray(revisionResponse?.data.revisions)) {
        throw new Error("Server vrátil neplatný zoznam revízií.");
      }
      if ((isManager || isOwner) && !Array.isArray(vendorResponse?.data.vendors)) {
        throw new Error("Server vrátil neplatný zoznam dodávateľov.");
      }
      if (isManager && !Array.isArray(propertyResponse?.data)) {
        throw new Error("Server vrátil neplatný zoznam bytových domov.");
      }

      setRevisions(revisionResponse?.data.revisions ?? []);
      setVendors(vendorResponse?.data.vendors ?? []);
      setProperties(propertyResponse?.data ?? []);
    } catch (error) {
      setPageError(getErrorMessage(error));
    } finally {
      setIsLoading(false);
    }
  }, [isManager, isOwner]);

  useEffect(() => {
    void loadData();
  }, [loadData]);

  useEffect(() => {
    const dialog = dialogRef.current;
    if (!dialog) return;
    if (isOpen && !dialog.open) dialog.showModal();
    if (!isOpen && dialog.open) dialog.close();
  }, [isOpen]);

  function openRevisionEditor(record: Revision | null) {
    setEditor({ kind: "revision", record });
    setRevisionForm(record
      ? {
          property_id: String(record.property_id),
          revision_type: record.revision_type,
          last_date: record.last_date,
          next_due_date: record.next_due_date,
        }
      : {
          ...emptyRevisionForm,
          property_id: properties.length === 1 ? String(properties[0].id) : "",
        });
    setFormError("");
    setIsOpen(true);
  }

  function openVendorEditor(record: Vendor | null) {
    setEditor({ kind: "vendor", record });
    setVendorForm(record
      ? {
          property_id: String(record.property_id),
          title: record.title,
          vendor_type: record.vendor_type,
          phone: record.phone,
          email: record.email,
        }
      : {
          ...emptyVendorForm,
          property_id: properties.length === 1 ? String(properties[0].id) : "",
        });
    setFormError("");
    setIsOpen(true);
  }

  async function saveRecord(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!editor || isSaving) return;

    setIsSaving(true);
    setFormError("");
    setMessage("");

    try {
      if (editor.kind === "revision") {
        const payload = {
          property_id: Number(revisionForm.property_id),
          revision_type: revisionForm.revision_type.trim(),
          last_date: revisionForm.last_date,
          next_due_date: revisionForm.next_due_date,
        };
        if (editor.record) {
          await apiClient.put(`${technicalApi}/revisions/${editor.record.id}`, payload);
          setMessage("Revízia bola upravená.");
        } else {
          await apiClient.post(`${technicalApi}/revisions`, payload);
          setMessage("Revízia bola pridaná.");
        }
      } else {
        const payload = {
          property_id: Number(vendorForm.property_id),
          title: vendorForm.title.trim(),
          vendor_type: vendorForm.vendor_type,
          phone: vendorForm.phone.trim(),
          email: vendorForm.email.trim(),
        };
        if (editor.record) {
          await apiClient.put(`${technicalApi}/vendors/${editor.record.id}`, payload);
          setMessage("Dodávateľ bol upravený.");
        } else {
          await apiClient.post(`${technicalApi}/vendors`, payload);
          setMessage("Dodávateľ bol pridaný.");
        }
      }

      setIsOpen(false);
      await loadData();
    } catch (error) {
      setFormError(getErrorMessage(error));
    } finally {
      setIsSaving(false);
    }
  }

  async function deleteRecord(record: Revision | Vendor) {
    const isRevision = "revision_type" in record;
    const name = isRevision ? record.revision_type : record.title;
    if (!window.confirm(`Naozaj chcete odstrániť „${name}“?`)) return;

    setPageError("");
    setMessage("");
    try {
      await apiClient.delete(`${technicalApi}/${isRevision ? "revisions" : "vendors"}/${record.id}`);
      setMessage(isRevision ? "Revízia bola odstránená." : "Dodávateľ bol odstránený.");
      await loadData();
    } catch (error) {
      setPageError(getErrorMessage(error));
    }
  }

  if (!isManager && !isOwner) {
    return (
      <p role="alert" className="rounded-xl border border-red-200 bg-red-50 p-4 text-sm font-medium text-red-800">
        Technický pas je dostupný iba správcom a vlastníkom bytov.
      </p>
    );
  }

  return (
    <section className="space-y-5">
      <header>
        <p className="text-sm font-semibold text-blue-800">SmartSused · Technický pas</p>
        <h1 className="mt-1 text-2xl font-bold tracking-tight text-primary">Technický pas domu</h1>
      </header>

      {pageError && (
        <p role="alert" className="rounded-lg border border-red-200 bg-red-50 p-3 text-sm font-medium text-red-800">
          {pageError}
        </p>
      )}
      {message && (
        <p role="status" className="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm font-medium text-emerald-900">
          {message}
        </p>
      )}

      <div className={`grid items-start gap-5 ${isManager ? "xl:grid-cols-2" : "grid-cols-1"}`}>
        {isManager && (
          <section aria-labelledby="revision-plan-title" className="min-w-0 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
            <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-4 py-3">
              <h2 id="revision-plan-title" className="text-base font-bold text-primary">Plán revízií</h2>
              <button
                type="button"
                onClick={() => openRevisionEditor(null)}
                disabled={properties.length === 0}
                className="inline-flex min-h-10 items-center justify-center gap-2 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-800 transition hover:border-primary hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:cursor-not-allowed disabled:opacity-50"
              >
                <Plus aria-hidden="true" size={16} strokeWidth={1.5} />
                Pridať revíziu
              </button>
            </div>
            <div className="overflow-x-auto">
              {isLoading ? (
                <p role="status" className="p-5 text-sm text-slate-600">Načítavam plán revízií…</p>
              ) : pageError ? (
                <TableLoadError onRetry={() => void loadData()} />
              ) : revisions.length === 0 ? (
                <EmptyTableState />
              ) : (
                <table className="w-full min-w-[38rem] border-collapse text-left text-xs font-medium text-slate-700">
                  <thead className="bg-slate-100 text-xs font-semibold text-slate-800">
                    <tr className="border-b border-slate-200">
                      <th scope="col" className="px-3 py-2">Typ revízie</th>
                      <th scope="col" className="px-3 py-2">Posledná kontrola</th>
                      <th scope="col" className="px-3 py-2">Nasledujúca kontrola</th>
                      <th scope="col" className="px-3 py-2">Stav</th>
                      <th scope="col" className="px-3 py-2">Akcie</th>
                    </tr>
                  </thead>
                  <tbody>
                    {revisions.map((revision) => {
                      const expired = isRevisionExpired(revision);
                      return (
                        <tr key={revision.id} className="border-b border-slate-100 odd:bg-white even:bg-slate-50/50">
                          <th scope="row" className="px-3 py-2 text-left font-semibold text-slate-900">{revision.revision_type}</th>
                          <td className="whitespace-nowrap px-3 py-2">{formatDate(revision.last_date)}</td>
                          <td className="whitespace-nowrap px-3 py-2">{formatDate(revision.next_due_date)}</td>
                          <td className="px-3 py-2">
                            <span className={`inline-flex whitespace-nowrap rounded-full border px-2 py-1 text-[11px] font-semibold ${
                              expired
                                ? "border-red-200 bg-red-50 text-red-600"
                                : "border-emerald-200 bg-emerald-50 text-emerald-700"
                            }`}>
                              {expired ? "Exspirované" : "Platné"}
                            </span>
                          </td>
                          <td className="px-2 py-1">
                            <div className="flex items-center gap-0.5">
                              <ActionIconButton action="edit" icon={Pencil} label={`Upraviť revíziu ${revision.revision_type}`} onClick={() => openRevisionEditor(revision)} />
                              <ActionIconButton action="delete" icon={Trash2} label={`Odstrániť revíziu ${revision.revision_type}`} onClick={() => void deleteRecord(revision)} />
                            </div>
                          </td>
                        </tr>
                      );
                    })}
                  </tbody>
                </table>
              )}
            </div>
          </section>
        )}

        <section aria-labelledby="vendors-title" className="min-w-0 overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
          <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 px-4 py-3">
            <h2 id="vendors-title" className="text-base font-bold text-primary">Dodávatelia a havarijné služby</h2>
            {isManager && (
              <button
                type="button"
                onClick={() => openVendorEditor(null)}
                disabled={properties.length === 0}
                className="inline-flex min-h-10 items-center justify-center gap-2 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-800 transition hover:border-primary hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:cursor-not-allowed disabled:opacity-50"
              >
                <Plus aria-hidden="true" size={16} strokeWidth={1.5} />
                Pridať dodávateľa
              </button>
            )}
          </div>
          <div className="overflow-x-auto">
            {isLoading ? (
              <p role="status" className="p-5 text-sm text-slate-600">Načítavam dodávateľov…</p>
            ) : pageError ? (
              <TableLoadError onRetry={() => void loadData()} />
            ) : vendors.length === 0 ? (
              <EmptyTableState />
            ) : (
              <table className="w-full min-w-[42rem] border-collapse text-left text-xs font-medium text-slate-700">
                <thead className="bg-slate-100 text-xs font-semibold text-slate-800">
                  <tr className="border-b border-slate-200">
                    <th scope="col" className="px-3 py-2">Zameranie</th>
                    <th scope="col" className="px-3 py-2">Názov firmy</th>
                    <th scope="col" className="px-3 py-2">Telefón</th>
                    <th scope="col" className="px-3 py-2">E-mail</th>
                    {isManager && <th scope="col" className="px-3 py-2">Akcie</th>}
                  </tr>
                </thead>
                <tbody>
                  {vendors.map((vendor) => (
                    <tr key={vendor.id} className="border-b border-slate-100 odd:bg-white even:bg-slate-50/50">
                      <td className="px-3 py-2">{vendor.vendor_type}</td>
                      <th scope="row" className="px-3 py-2 text-left font-semibold text-slate-900">{vendor.title}</th>
                      <td className="whitespace-nowrap px-3 py-2">
                        {vendor.phone && phoneHref(vendor.phone) ? (
                          <a className="font-semibold text-blue-800 underline decoration-blue-300 underline-offset-2 hover:text-blue-950 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary" href={phoneHref(vendor.phone)}>
                            {vendor.phone}
                          </a>
                        ) : "Neuvedené"}
                      </td>
                      <td className="px-3 py-2">
                        {vendor.email ? (
                          <a className="break-all text-blue-800 underline decoration-blue-300 underline-offset-2 hover:text-blue-950 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary" href={`mailto:${vendor.email}`}>
                            {vendor.email}
                          </a>
                        ) : "Neuvedený"}
                      </td>
                      {isManager && (
                        <td className="px-2 py-1">
                          <div className="flex items-center gap-0.5">
                            <ActionIconButton action="edit" icon={Pencil} label={`Upraviť dodávateľa ${vendor.title}`} onClick={() => openVendorEditor(vendor)} />
                            <ActionIconButton action="delete" icon={Trash2} label={`Odstrániť dodávateľa ${vendor.title}`} onClick={() => void deleteRecord(vendor)} />
                          </div>
                        </td>
                      )}
                    </tr>
                  ))}
                </tbody>
              </table>
            )}
          </div>
        </section>
      </div>

      <dialog
        ref={dialogRef}
        aria-labelledby="technical-passport-dialog-title"
        className="fixed inset-0 z-50 m-0 h-full max-h-none w-full max-w-none overflow-y-auto bg-slate-950/60 p-4 backdrop:bg-transparent"
        onCancel={(event) => {
          event.preventDefault();
          if (!isSaving) setIsOpen(false);
        }}
        onClick={(event) => {
          if (event.target === event.currentTarget && !isSaving) setIsOpen(false);
        }}
      >
        <form
          className="mx-auto my-8 w-full max-w-lg space-y-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-2xl sm:p-6"
          onSubmit={saveRecord}
        >
          <div className="flex items-start justify-between gap-4">
            <div>
              <h2 id="technical-passport-dialog-title" className="text-xl font-bold text-primary">
                {editor?.kind === "revision"
                  ? editor.record ? "Upraviť revíziu" : "Pridať revíziu"
                  : editor?.kind === "vendor"
                    ? editor.record ? "Upraviť dodávateľa" : "Pridať dodávateľa"
                    : "Technický pas"}
              </h2>
              <p className="mt-1 text-sm text-slate-600">Vyplňte údaje a potvrďte uloženie.</p>
            </div>
            <button
              type="button"
              aria-label="Zavrieť okno"
              disabled={isSaving}
              onClick={() => setIsOpen(false)}
              className="inline-flex size-10 shrink-0 items-center justify-center rounded-lg text-slate-500 hover:bg-slate-100 hover:text-slate-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:opacity-50"
            >
              <X aria-hidden="true" size={20} />
            </button>
          </div>

          {editor?.kind === "revision" && (
            <>
              <PropertySelect
                properties={properties}
                value={revisionForm.property_id}
                onChange={(property_id) => setRevisionForm((current) => ({ ...current, property_id }))}
              />
              <label className="block text-sm font-medium text-slate-800">
                Typ revízie
                <input
                  required
                  maxLength={120}
                  autoFocus
                  className="form-input mt-1.5 w-full text-sm"
                  value={revisionForm.revision_type}
                  onChange={(event) => setRevisionForm((current) => ({ ...current, revision_type: event.target.value }))}
                />
              </label>
              <div className="grid gap-4 sm:grid-cols-2">
                <label className="block text-sm font-medium text-slate-800">
                  Posledná kontrola
                  <input
                    required
                    type="date"
                    className="form-input mt-1.5 w-full text-sm"
                    value={revisionForm.last_date}
                    onChange={(event) => setRevisionForm((current) => ({ ...current, last_date: event.target.value }))}
                  />
                </label>
                <label className="block text-sm font-medium text-slate-800">
                  Nasledujúca kontrola
                  <input
                    required
                    type="date"
                    className="form-input mt-1.5 w-full text-sm"
                    value={revisionForm.next_due_date}
                    onChange={(event) => setRevisionForm((current) => ({ ...current, next_due_date: event.target.value }))}
                  />
                </label>
              </div>
            </>
          )}

          {editor?.kind === "vendor" && (
            <>
              <PropertySelect
                properties={properties}
                value={vendorForm.property_id}
                onChange={(property_id) => setVendorForm((current) => ({ ...current, property_id }))}
              />
              <label className="block text-sm font-medium text-slate-800">
                Názov firmy
                <input
                  required
                  maxLength={200}
                  autoFocus
                  className="form-input mt-1.5 w-full text-sm"
                  value={vendorForm.title}
                  onChange={(event) => setVendorForm((current) => ({ ...current, title: event.target.value }))}
                />
              </label>
              <label className="block text-sm font-medium text-slate-800">
                Zameranie
                <select
                  required
                  className="form-input mt-1.5 w-full text-sm"
                  value={vendorForm.vendor_type}
                  onChange={(event) => setVendorForm((current) => ({ ...current, vendor_type: event.target.value }))}
                >
                  {vendorTypes.map((type) => <option key={type} value={type}>{type}</option>)}
                </select>
              </label>
              <div className="grid gap-4 sm:grid-cols-2">
                <label className="block text-sm font-medium text-slate-800">
                  Telefón
                  <input
                    type="tel"
                    maxLength={40}
                    className="form-input mt-1.5 w-full text-sm"
                    value={vendorForm.phone}
                    onChange={(event) => setVendorForm((current) => ({ ...current, phone: event.target.value }))}
                  />
                </label>
                <label className="block text-sm font-medium text-slate-800">
                  E-mail
                  <input
                    type="email"
                    maxLength={254}
                    className="form-input mt-1.5 w-full text-sm"
                    value={vendorForm.email}
                    onChange={(event) => setVendorForm((current) => ({ ...current, email: event.target.value }))}
                  />
                </label>
              </div>
            </>
          )}

          {formError && (
            <p role="alert" className="rounded-lg border border-red-200 bg-red-50 p-3 text-sm font-medium text-red-800">
              {formError}
            </p>
          )}

          <div className="flex flex-wrap justify-end gap-2 border-t border-slate-200 pt-4">
            <button
              type="button"
              disabled={isSaving}
              onClick={() => setIsOpen(false)}
              className="min-h-11 rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:opacity-50"
            >
              Zrušiť
            </button>
            <button
              type="submit"
              disabled={isSaving || !editor}
              className="btn-primary min-h-11 px-5 py-2 text-sm disabled:cursor-wait disabled:opacity-60"
            >
              {isSaving ? "Ukladám…" : "Uložiť"}
            </button>
          </div>
        </form>
      </dialog>
    </section>
  );
}
