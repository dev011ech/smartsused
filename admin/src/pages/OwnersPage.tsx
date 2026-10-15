import { useCallback, useEffect, useMemo, useRef, useState, type FormEvent } from "react";
import axios from "axios";
import { Pencil, Plus, Search, Trash2 } from "lucide-react";
import { apiClient } from "../api/client";
import { ActionIconButton } from "../components/ActionIconButton";
import { CollapsibleCSVImportPanel } from "../components/CSVImportZone";
import { EmptyTableState } from "../components/EmptyTableState";
import { normalizeSearchText } from "../utils/search";

type WordPressPost = {
  id: number;
  title: { raw?: string; rendered: string };
};

type OwnerUnit = {
  id: number;
  title: string;
  property_id: number;
  property_name: string;
  floor: string;
  entrance: string;
  resident_type: "owner" | "tenant";
};

type Owner = {
  id: number;
  name: string;
  first_name: string;
  last_name: string;
  email: string;
  phone: string;
  resident_type: "owner" | "tenant";
  units: OwnerUnit[];
};

type AvailableUnit = {
  id: number;
  title: string;
  floor: string;
  entrance: string;
};

type OwnerForm = {
  first_name: string;
  last_name: string;
  email: string;
  phone: string;
  unit_id: string;
};

const emptyForm: OwnerForm = {
  first_name: "",
  last_name: "",
  email: "",
  phone: "",
  unit_id: "",
};

function getTitle(post: WordPressPost) {
  return post.title.raw ?? post.title.rendered;
}

function getErrorMessage(error: unknown) {
  if (axios.isAxiosError<{ message?: string }>(error)) {
    return error.response?.data?.message ?? "Požiadavku sa nepodarilo dokončiť. Skúste to znova.";
  }

  return error instanceof Error ? error.message : "Požiadavku sa nepodarilo dokončiť. Skúste to znova.";
}

async function fetchProperties() {
  const properties: WordPressPost[] = [];
  let page = 1;
  let totalPages = 1;

  do {
    const response = await apiClient.get<WordPressPost[]>("wp/v2/smartsused_property", {
      params: { context: "edit", orderby: "title", order: "asc", per_page: 100, page },
    });
    properties.push(...response.data);
    totalPages = Number(response.headers["x-wp-totalpages"]) || 1;
    page += 1;
  } while (page <= totalPages);

  return properties;
}

export function OwnersPage() {
  const dialogRef = useRef<HTMLDialogElement>(null);
  const deleteDialogRef = useRef<HTMLDialogElement>(null);
  const [owners, setOwners] = useState<Owner[]>([]);
  const [properties, setProperties] = useState<WordPressPost[]>([]);
  const [availableUnits, setAvailableUnits] = useState<AvailableUnit[]>([]);
  const [form, setForm] = useState<OwnerForm>(emptyForm);
  const [selectedPropertyId, setSelectedPropertyId] = useState("");
  const [search, setSearch] = useState("");
  const [residentTypeFilter, setResidentTypeFilter] = useState<"" | "owner" | "tenant">("");
  const [isLoading, setIsLoading] = useState(true);
  const [isLoadingUnits, setIsLoadingUnits] = useState(false);
  const [isSaving, setIsSaving] = useState(false);
  const [isOpen, setIsOpen] = useState(false);
  const [ownerToEdit, setOwnerToEdit] = useState<Owner | null>(null);
  const [ownerToRemove, setOwnerToRemove] = useState<Owner | null>(null);
  const [isRemoving, setIsRemoving] = useState(false);
  const [pageError, setPageError] = useState("");
  const [formError, setFormError] = useState("");
  const [notice, setNotice] = useState("");

  const loadOwners = useCallback(async () => {
    setIsLoading(true);
    setPageError("");
    try {
      const [ownerResponse, propertyPosts] = await Promise.all([
        apiClient.get<{ owners: Owner[] }>("smartsused/v1/owners"),
        fetchProperties(),
      ]);
      if (!Array.isArray(ownerResponse.data.owners)) {
        throw new Error("Server vrátil neplatný zoznam vlastníkov.");
      }
      setOwners(ownerResponse.data.owners);
      setProperties(propertyPosts);
    } catch (error) {
      setPageError(getErrorMessage(error));
    } finally {
      setIsLoading(false);
    }
  }, []);

  useEffect(() => {
    void loadOwners();
  }, [loadOwners]);

  useEffect(() => {
    const dialog = dialogRef.current;
    if (!dialog) return;
    if (isOpen && !dialog.open) dialog.showModal();
    if (!isOpen && dialog.open) dialog.close();
  }, [isOpen]);

  useEffect(() => {
    const dialog = deleteDialogRef.current;
    if (!dialog) return;
    if (ownerToRemove && !dialog.open) dialog.showModal();
    if (!ownerToRemove && dialog.open) dialog.close();
  }, [ownerToRemove]);

  const visibleOwners = useMemo(() => {
    const query = normalizeSearchText(search.trim());
    return owners.filter((owner) => {
      const matchesType =
        !residentTypeFilter ||
        owner.units.some((unit) => unit.resident_type === residentTypeFilter);
      const matchesSearch =
        !query ||
        normalizeSearchText(
          [
            owner.name,
            owner.first_name,
            owner.last_name,
            owner.email,
            owner.phone,
            ...owner.units.flatMap((unit) => [
              unit.title,
              unit.property_name,
              unit.entrance,
              unit.floor,
              unit.resident_type === "owner" ? "vlastník" : "nájomca",
            ]),
          ].join(" "),
        ).includes(query);
      return matchesType && matchesSearch;
    });
  }, [owners, residentTypeFilter, search]);

  async function selectProperty(propertyId: string) {
    setSelectedPropertyId(propertyId);
    setForm((current) => ({ ...current, unit_id: "" }));
    setAvailableUnits([]);
    setFormError("");
    if (!propertyId) return;

    setIsLoadingUnits(true);
    try {
      const response = await apiClient.get<{ units: AvailableUnit[] }>("smartsused/v1/owners/units", {
        params: { property_id: Number(propertyId) },
      });
      if (!Array.isArray(response.data.units)) {
        throw new Error("Server vrátil neplatný zoznam voľných bytov.");
      }
      setAvailableUnits(response.data.units);
    } catch (error) {
      setFormError(getErrorMessage(error));
    } finally {
      setIsLoadingUnits(false);
    }
  }

  function openCreateDialog() {
    setOwnerToEdit(null);
    setForm(emptyForm);
    setSelectedPropertyId("");
    setAvailableUnits([]);
    setFormError("");
    setNotice("");
    setIsOpen(true);
  }

  function openEditDialog(owner: Owner) {
    setOwnerToEdit(owner);
    setForm({
      first_name: owner.first_name,
      last_name: owner.last_name,
      email: owner.email,
      phone: owner.phone,
      unit_id: "",
    });
    setFormError("");
    setNotice("");
    setIsOpen(true);
  }

  async function saveOwner(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (isSaving) return;

    setIsSaving(true);
    setFormError("");
    setNotice("");
    try {
      const response = ownerToEdit
        ? await apiClient.put<{ message: string }>(`smartsused/v1/owners/${ownerToEdit.id}`, {
            first_name: form.first_name,
            last_name: form.last_name,
            email: form.email,
            phone: form.phone,
          })
        : await apiClient.post<{ message: string }>("smartsused/v1/owners/create", {
            ...form,
            unit_id: Number(form.unit_id),
          });
      setIsOpen(false);
      setNotice(response.data.message);
      await loadOwners();
    } catch (error) {
      setFormError(getErrorMessage(error));
    } finally {
      setIsSaving(false);
    }
  }

  async function removeOwnerRelationships() {
    if (!ownerToRemove || isRemoving) return;
    setIsRemoving(true);
    setPageError("");
    try {
      const response = await apiClient.delete<{ message: string }>(`smartsused/v1/owners/${ownerToRemove.id}`);
      setOwnerToRemove(null);
      setNotice(response.data.message);
      await loadOwners();
    } catch (error) {
      setPageError(getErrorMessage(error));
    } finally {
      setIsRemoving(false);
    }
  }

  return (
    <section className="space-y-8">
      <header>
        <p className="text-sm font-semibold text-blue-800">SmartSused · Správa</p>
        <h1 className="mt-2 text-3xl font-bold tracking-tight text-primary">Správa vlastníkov</h1>
        <p className="mt-2 max-w-3xl text-base leading-7 text-slate-700">
          Vyhľadávajte obyvateľov, spravujte ich priradené byty a importujte vlastníkov aj nájomcov z jedného súboru.
        </p>
      </header>

      {pageError && <p role="alert" className="rounded-lg border-2 border-red-700 bg-red-50 p-4 font-semibold text-red-900">{pageError}</p>}
      {notice && <p role="status" className="rounded-lg border-2 border-emerald-700 bg-emerald-50 p-4 font-semibold text-emerald-950">{notice}</p>}

      <section aria-labelledby="owners-title" className="rounded-2xl border border-slate-300 bg-white p-5 shadow-sm sm:p-7">
        <div className="flex justify-end">
          <button className="btn-primary min-h-12" onClick={openCreateDialog} type="button">
            <Plus aria-hidden="true" className="mr-2" size={18} strokeWidth={1.5} />
            Pridať vlastníka manuálne
          </button>
        </div>

        <div className="mt-5">
          <CollapsibleCSVImportPanel
            importType="owners"
            onImported={loadOwners}
            toolbarContent={
              <>
                <label className="relative min-w-0 flex-1 sm:max-w-md">
                  <span className="sr-only">Hľadať vlastníka alebo nájomcu podľa mena, e-mailu, telefónu alebo bytu</span>
                  <Search aria-hidden="true" className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" size={16} strokeWidth={1.5} />
                  <input
                    className="min-h-10 w-full rounded-lg border border-slate-300 bg-white py-2 pl-9 pr-3 text-sm text-slate-800 placeholder:text-slate-400 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20"
                    onChange={(event) => setSearch(event.target.value)}
                    placeholder="Hľadať obyvateľa podľa mena, e-mailu..."
                    type="search"
                    value={search}
                  />
                </label>
                <label>
                  <span className="sr-only">Filtrovať podľa typu obyvateľa</span>
                  <select
                    className="min-h-10 rounded-lg border border-slate-300 bg-white px-3 py-2 text-sm font-medium text-slate-700 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20"
                    value={residentTypeFilter}
                    onChange={(event) => setResidentTypeFilter(event.target.value as "" | "owner" | "tenant")}
                  >
                    <option value="">Všetci obyvatelia</option>
                    <option value="owner">Iba vlastníci</option>
                    <option value="tenant">Iba nájomcovia</option>
                  </select>
                </label>
              </>
            }
          />
        </div>

        {!isLoading && visibleOwners.length === 0 ? (
          <div className="mt-4">
            <EmptyTableState />
          </div>
        ) : (
        <div className="mt-4 overflow-x-auto rounded-xl border border-slate-300">
          <table className="min-w-full divide-y divide-slate-200 text-left text-sm font-medium text-slate-800">
            <thead className="bg-slate-100 text-xs font-semibold text-slate-900">
              <tr>
                <th className="px-3 py-1.5" scope="col">Meno a priezvisko</th>
                <th className="px-3 py-1.5" scope="col">Typ obyvateľa</th>
                <th className="px-3 py-1.5" scope="col">E-mail</th>
                <th className="px-3 py-1.5" scope="col">Telefón</th>
                <th className="px-3 py-1.5" scope="col">Priradené byty</th>
                <th className="px-3 py-1.5" scope="col">Akcie</th>
              </tr>
            </thead>
            <tbody>
              {visibleOwners.map((owner) => (
                <tr key={owner.id} className="border-b border-slate-100 odd:bg-white even:bg-slate-50/50">
                  <th className="px-3 py-1.5 text-left font-semibold text-slate-950" scope="row">
                    {owner.first_name || owner.last_name
                      ? `${owner.first_name} ${owner.last_name}`.trim()
                      : owner.name}
                  </th>
                  <td className="px-3 py-1.5">
                    <span className={`inline-flex rounded-full px-2 py-0.5 text-xs font-semibold ${
                      owner.resident_type === "owner" ? "bg-blue-50 text-blue-800" : "bg-emerald-50 text-emerald-800"
                    }`}>
                      {owner.resident_type === "owner" ? "Vlastník" : "Nájomca"}
                    </span>
                  </td>
                  <td className="px-3 py-1.5">{owner.email}</td>
                  <td className="px-3 py-1.5">{owner.phone || "—"}</td>
                  <td className="px-3 py-1.5">
                    {owner.units.length ? (
                      <ul className="space-y-1">
                        {owner.units.map((unit) => (
                          <li key={unit.id} className="rounded-md border border-slate-200 bg-white/70 px-2 py-1">
                            <span className="font-semibold text-slate-950">{unit.property_name}</span>
                            <span className="block text-xs text-slate-700">
                              {unit.title}
                              {unit.entrance ? ` · Vchod ${unit.entrance}` : ""}
                              {unit.floor ? ` · Poschodie ${unit.floor}` : ""}
                            </span>
                          </li>
                        ))}
                      </ul>
                    ) : (
                      <span className="text-slate-600">Bez priradeného bytu</span>
                    )}
                  </td>
                  <td className="px-3 py-1.5">
                    {owner.resident_type === "owner" ? (
                    <div className="flex items-center gap-1">
                      <ActionIconButton
                        action="edit"
                        compact
                        icon={Pencil}
                        label={`Upraviť údaje vlastníka ${owner.name}`}
                        onClick={() => openEditDialog(owner)}
                      />
                      <ActionIconButton
                        action="delete"
                        compact
                        icon={Trash2}
                        label={`Odobrať vlastníka ${owner.name} z priradených bytov`}
                        onClick={() => setOwnerToRemove(owner)}
                      />
                    </div>
                    ) : <span className="text-slate-400">—</span>}
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        )}
        {isLoading && <p role="status" className="mt-4 font-semibold text-slate-700">Načítavam vlastníkov…</p>}
      </section>

      <dialog
        ref={dialogRef}
        aria-labelledby="owner-form-title"
        className="fixed inset-0 z-50 m-0 h-full max-h-none w-full max-w-none overflow-y-auto bg-slate-950/60 p-4 backdrop:bg-transparent"
        onCancel={(event) => {
          event.preventDefault();
          if (!isSaving) setIsOpen(false);
        }}
      >
        <form
          className="mx-auto my-8 w-full max-w-2xl space-y-5 rounded-2xl border-2 border-slate-300 bg-white p-6 shadow-2xl sm:p-8"
          onSubmit={saveOwner}
        >
          <div>
            <h2 className="text-2xl font-bold text-primary" id="owner-form-title">
              {ownerToEdit ? "Upraviť údaje vlastníka" : "Pridať vlastníka manuálne"}
            </h2>
            <p className="mt-2 text-sm leading-6 text-slate-700">
              {ownerToEdit
                ? "Upravte osobné údaje vlastníka. Priradené byty sa nezmenia."
                : "Vlastníkovi vytvoríme účet a pošleme odkaz na nastavenie hesla."}
            </p>
          </div>

          <div className="grid gap-4 sm:grid-cols-2">
            <label className="block font-semibold text-slate-900">
              Meno
              <input autoComplete="given-name" className="form-input mt-2 w-full" maxLength={100} onChange={(event) => setForm({ ...form, first_name: event.target.value })} required value={form.first_name} />
            </label>
            <label className="block font-semibold text-slate-900">
              Priezvisko
              <input autoComplete="family-name" className="form-input mt-2 w-full" maxLength={100} onChange={(event) => setForm({ ...form, last_name: event.target.value })} required value={form.last_name} />
            </label>
            <label className="block font-semibold text-slate-900">
              E-mail
              <input autoComplete="email" className="form-input mt-2 w-full" onChange={(event) => setForm({ ...form, email: event.target.value })} required type="email" value={form.email} />
            </label>
            <label className="block font-semibold text-slate-900">
              Telefón
              <input autoComplete="tel" className="form-input mt-2 w-full" onChange={(event) => setForm({ ...form, phone: event.target.value })} type="tel" value={form.phone} />
            </label>
          </div>

          {!ownerToEdit && <label className="block font-semibold text-slate-900">
            Bytový dom
            <select
              className="form-input mt-2 w-full"
              onChange={(event) => void selectProperty(event.target.value)}
              required
              value={selectedPropertyId}
            >
              <option value="">Vyberte bytový dom</option>
              {properties.map((property) => <option key={property.id} value={property.id}>{getTitle(property)}</option>)}
            </select>
          </label>}

          {!ownerToEdit && <label className="block font-semibold text-slate-900">
            Priradiť k voľnému bytu
            <select
              className="form-input mt-2 w-full"
              disabled={!selectedPropertyId || isLoadingUnits || availableUnits.length === 0}
              onChange={(event) => setForm({ ...form, unit_id: event.target.value })}
              required
              value={form.unit_id}
            >
              <option value="">Vyberte voľný byt</option>
              {availableUnits.map((unit) => (
                <option key={unit.id} value={unit.id}>
                  {unit.title}{unit.entrance ? ` · Vchod ${unit.entrance}` : ""}{unit.floor ? ` · Poschodie ${unit.floor}` : ""}
                </option>
              ))}
            </select>
            {isLoadingUnits && <span className="mt-2 block text-sm text-slate-600">Načítavam voľné byty…</span>}
            {!isLoadingUnits && selectedPropertyId && availableUnits.length === 0 && (
              <span className="mt-2 block text-sm text-slate-600">Pre vybraný dom nie sú dostupné voľné byty.</span>
            )}
          </label>}

          {formError && <p role="alert" className="rounded-lg border-2 border-red-700 bg-red-50 p-3 font-semibold text-red-900">{formError}</p>}

          <div className="flex flex-col-reverse justify-end gap-3 sm:flex-row">
            <button className="min-h-12 rounded-lg border-2 border-slate-400 px-5 font-semibold text-slate-900 hover:bg-slate-100" disabled={isSaving} onClick={() => setIsOpen(false)} type="button">
              Zrušiť
            </button>
            <button className="btn-primary min-h-12" disabled={isSaving || isLoadingUnits || (!ownerToEdit && properties.length === 0)} type="submit">
              {isSaving ? "Ukladá sa…" : ownerToEdit ? "Uložiť zmeny" : "Vytvoriť vlastníka"}
            </button>
          </div>
        </form>
      </dialog>

      {ownerToRemove && (
        <dialog
          ref={deleteDialogRef}
          aria-labelledby="owner-delete-title"
          className="fixed inset-0 z-50 m-0 h-full max-h-none w-full max-w-none overflow-y-auto bg-slate-950/60 p-4 backdrop:bg-transparent"
          onCancel={(event) => {
            event.preventDefault();
            if (!isRemoving) setOwnerToRemove(null);
          }}
        >
          <div className="mx-auto my-16 w-full max-w-lg space-y-5 rounded-2xl border-2 border-slate-300 bg-white p-6 shadow-2xl sm:p-8">
            <div>
              <h2 id="owner-delete-title" className="text-2xl font-bold text-primary">Odobrať vlastníka?</h2>
              <p className="mt-3 leading-7 text-slate-800">
                Odobrať {ownerToRemove.name} zo všetkých bytov v spravovanom dome? Používateľský účet a jeho priradenia v iných domoch zostanú zachované.
              </p>
            </div>
            <div className="flex flex-wrap justify-end gap-3">
              <button type="button" className="min-h-12 rounded-lg border-2 border-slate-500 px-5 font-semibold text-slate-900 hover:bg-slate-100" disabled={isRemoving} onClick={() => setOwnerToRemove(null)}>Zrušiť</button>
              <button type="button" className="min-h-12 rounded-lg bg-red-700 px-5 font-semibold text-white hover:bg-red-800 disabled:opacity-60" disabled={isRemoving} onClick={() => void removeOwnerRelationships()}>{isRemoving ? "Odoberá sa…" : "Odobrať z bytov"}</button>
            </div>
          </div>
        </dialog>
      )}
    </section>
  );
}
