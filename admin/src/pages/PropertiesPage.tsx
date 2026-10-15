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
  content: { raw?: string; rendered: string };
  property_id?: number;
  floor?: string;
  entrance?: string;
};

type Editor =
  | { type: "property"; post?: WordPressPost }
  | { type: "unit"; post?: WordPressPost }
  | null;

type PropertyForm = { name: string; address: string };
type UnitForm = { propertyId: string; number: string; floor: string; entrance: string };

function postTitle(post: WordPressPost) {
  return post.title.raw ?? post.title.rendered;
}

function unitNumber(post: WordPressPost) {
  const title = postTitle(post);
  return title.match(/(?:^|,\s*)Byt\s+(.+)$/i)?.[1] ?? title.replace(/^Byt\s+/i, "");
}

async function fetchAllPosts(postType: "smartsused_property" | "smartsused_unit") {
  const posts: WordPressPost[] = [];
  let page = 1;
  let totalPages = 1;

  do {
    const response = await apiClient.get<WordPressPost[]>(`wp/v2/${postType}`, {
      params: {
        context: "edit",
        orderby: "title",
        order: "asc",
        per_page: 100,
        page,
      },
    });
    posts.push(...response.data);
    totalPages = Number(response.headers["x-wp-totalpages"]) || 1;
    page += 1;
  } while (page <= totalPages);

  return posts;
}

function getRequestError(error: unknown) {
  if (axios.isAxiosError<{ message?: string }>(error)) {
    return error.response?.data?.message ?? "Požiadavku sa nepodarilo dokončiť. Skúste to znova.";
  }
  return error instanceof Error ? error.message : "Požiadavku sa nepodarilo dokončiť. Skúste to znova.";
}

export function PropertiesPage({ section }: { section: "properties" | "units" }) {
  const [properties, setProperties] = useState<WordPressPost[]>([]);
  const [units, setUnits] = useState<WordPressPost[]>([]);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [pageError, setPageError] = useState("");
  const [formError, setFormError] = useState("");
  const [notice, setNotice] = useState("");
  const [editor, setEditor] = useState<Editor>(null);
  const dialogRef = useRef<HTMLDialogElement>(null);
  const deleteDialogRef = useRef<HTMLDialogElement>(null);
  const [unitToDelete, setUnitToDelete] = useState<WordPressPost | null>(null);
  const [deletingUnit, setDeletingUnit] = useState(false);
  const [propertyForm, setPropertyForm] = useState<PropertyForm>({ name: "", address: "" });
  const [unitForm, setUnitForm] = useState<UnitForm>({
    propertyId: "",
    number: "",
    floor: "",
    entrance: "",
  });
  const [propertyFilter, setPropertyFilter] = useState("");
  const [unitSearch, setUnitSearch] = useState("");

  const propertyNames = useMemo(
    () => new Map(properties.map((property) => [property.id, postTitle(property)])),
    [properties],
  );
  const visibleUnits = useMemo(
    () => units.filter((unit) => {
      const matchesProperty = !propertyFilter || String(unit.property_id ?? "") === propertyFilter;
      const query = normalizeSearchText(unitSearch.trim());
      const matchesSearch = !query || normalizeSearchText([
        unitNumber(unit),
        postTitle(unit),
        propertyNames.get(unit.property_id ?? 0) ?? "",
        unit.entrance ?? "",
        unit.floor ?? "",
      ].join(" ")).includes(query);
      return matchesProperty && matchesSearch;
    }),
    [propertyFilter, propertyNames, unitSearch, units],
  );

  const loadData = useCallback(async () => {
    setLoading(true);
    setPageError("");
    try {
      const propertyPosts = await fetchAllPosts("smartsused_property");
      const unitPosts = "units" === section ? await fetchAllPosts("smartsused_unit") : [];
      setProperties(propertyPosts);
      setUnits(unitPosts);
    } catch (error) {
      setPageError(getRequestError(error));
    } finally {
      setLoading(false);
    }
  }, [section]);

  useEffect(() => {
    void loadData();
  }, [loadData]);

  useEffect(() => {
    const dialog = dialogRef.current;
    if (!editor || !dialog) return;

    dialog.showModal();
    return () => dialog.close();
  }, [editor]);

  useEffect(() => {
    const dialog = deleteDialogRef.current;
    if (!dialog) return;
    if (unitToDelete && !dialog.open) dialog.showModal();
    if (!unitToDelete && dialog.open) dialog.close();
  }, [unitToDelete]);

  function openPropertyEditor(post?: WordPressPost) {
    setFormError("");
    setNotice("");
    setPropertyForm({
      name: post ? postTitle(post) : "",
      address: post ? post.content.raw ?? post.content.rendered : "",
    });
    setEditor({ type: "property", post });
  }

  function openUnitEditor(post?: WordPressPost) {
    setFormError("");
    setNotice("");
    setUnitForm({
      propertyId: post?.property_id ? String(post.property_id) : "",
      number: post ? unitNumber(post) : "",
      floor: post?.floor ?? "",
      entrance: post?.entrance ?? "",
    });
    setEditor({ type: "unit", post });
  }

  async function saveProperty(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (saving) return;

    setSaving(true);
    setFormError("");
    const payload = {
      title: propertyForm.name.trim(),
      content: propertyForm.address.trim(),
      status: "publish",
    };

    try {
      if (editor?.type === "property" && editor.post) {
        await apiClient.put(`wp/v2/smartsused_property/${editor.post.id}`, payload);
        setNotice("Bytový dom bol upravený.");
      } else {
        await apiClient.post("wp/v2/smartsused_property", payload);
        setNotice("Bytový dom bol pridaný.");
      }
      setEditor(null);
      await loadData();
    } catch (error) {
      setFormError(getRequestError(error));
    } finally {
      setSaving(false);
    }
  }

  async function saveUnit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (saving) return;

    setSaving(true);
    setFormError("");
    const payload = {
      title: `Byt ${unitForm.number.trim()}`,
      status: "publish",
      property_id: Number(unitForm.propertyId),
      floor: unitForm.floor.trim(),
      entrance: unitForm.entrance.trim(),
    };

    try {
      if (editor?.type === "unit" && editor.post) {
        await apiClient.put(`wp/v2/smartsused_unit/${editor.post.id}`, payload);
        setNotice("Bytová jednotka bola upravená.");
      } else {
        await apiClient.post("wp/v2/smartsused_unit", payload);
        setNotice("Bytová jednotka bola pridaná.");
      }
      setEditor(null);
      await loadData();
    } catch (error) {
      setFormError(getRequestError(error));
    } finally {
      setSaving(false);
    }
  }

  async function deleteUnit() {
    if (!unitToDelete || deletingUnit) return;

    setDeletingUnit(true);
    setFormError("");
    try {
      await apiClient.delete(`wp/v2/smartsused_unit/${unitToDelete.id}`);
      setUnitToDelete(null);
      setNotice(`Byt ${unitNumber(unitToDelete)} bol odstránený.`);
      await loadData();
    } catch (error) {
      setFormError(getRequestError(error));
    } finally {
      setDeletingUnit(false);
    }
  }

  const isPropertiesSection = "properties" === section;

  return (
    <section className="space-y-8">
      <header>
        <p className="text-sm font-semibold text-blue-800">SmartSused · Nehnuteľnosti</p>
        <h1 className="mt-2 text-3xl font-bold tracking-tight text-primary">
          {isPropertiesSection ? "Správa bytových domov" : "Správa bytových jednotiek"}
        </h1>
        <p className="mt-2 max-w-3xl text-base leading-7 text-slate-700">
          {isPropertiesSection
            ? "Prehľad a správa bytových domov."
            : "Prehľad bytov s filtrovaním podľa bytového domu."}
        </p>
      </header>

      {pageError && (
        <p role="alert" className="rounded-lg border-2 border-red-700 bg-red-50 p-4 font-semibold text-red-900">
          {pageError}
        </p>
      )}
      {notice && (
        <p role="status" className="rounded-lg border-2 border-emerald-700 bg-emerald-50 p-4 font-semibold text-emerald-950">
          {notice}
        </p>
      )}

      {isPropertiesSection && <section aria-labelledby="properties-title" className="rounded-2xl border border-slate-300 bg-white p-5 shadow-sm sm:p-7">
        <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <h2 id="properties-title" className="text-2xl font-bold text-primary">Bytové domy</h2>
            <p className="mt-1 text-base text-slate-700">Názvy a adresy spravovaných objektov.</p>
          </div>
          <p className="text-sm text-slate-600">Správu priradeného bytového domu vykonáva administrátor.</p>
        </div>
        <div className="mt-5 overflow-x-auto">
          <table className="w-full min-w-[52rem] border-collapse text-left">
            <thead>
              <tr className="border-b-2 border-slate-300 text-base text-slate-800">
                <th scope="col" className="px-3 py-3">Názov domu</th>
                <th scope="col" className="px-3 py-3">Adresa</th>
                <th scope="col" className="px-3 py-3">Akcie</th>
              </tr>
            </thead>
            <tbody>
              {properties.map((property) => (
                <tr key={property.id} className="border-b border-slate-200 align-top">
                  <th scope="row" className="px-3 py-4 font-semibold text-slate-950">{postTitle(property)}</th>
                  <td className="px-3 py-4 text-slate-800">{property.content.raw ?? property.content.rendered}</td>
                  <td className="px-3 py-4">
                    <ActionIconButton
                      action="edit"
                      icon={Pencil}
                      label={`Upraviť bytový dom ${postTitle(property)}`}
                      onClick={() => openPropertyEditor(property)}
                    />
                  </td>
                </tr>
              ))}
              {!loading && properties.length === 0 && (
                <tr><td colSpan={3} className="px-3 py-6 text-center text-slate-700">Zatiaľ nie sú pridané žiadne bytové domy.</td></tr>
              )}
            </tbody>
          </table>
        </div>
      </section>
      }

      {!isPropertiesSection && <section aria-labelledby="units-title" className="rounded-2xl border border-slate-300 bg-white p-5 shadow-sm sm:p-7">
        <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <h2 id="units-title" className="text-2xl font-bold text-primary">Bytové jednotky</h2>
            <p className="mt-1 text-base text-slate-700">Byty priradené k jednotlivým domom.</p>
          </div>
          <button type="button" className="btn-primary min-h-12" disabled={properties.length === 0} onClick={() => openUnitEditor()}>
            <Plus aria-hidden="true" className="mr-2" size={18} strokeWidth={1.5} />
            Pridať bytovú jednotku
          </button>
        </div>
        <div className="mt-4">
          <CollapsibleCSVImportPanel
            importType="units"
            onImported={loadData}
            toolbarContent={
              <label className="relative min-w-0 flex-1 sm:max-w-md">
                <span className="sr-only">Hľadať byt podľa čísla, vchodu alebo bytového domu</span>
                <Search aria-hidden="true" className="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-slate-400" size={16} strokeWidth={1.5} />
                <input
                  className="min-h-10 w-full rounded-lg border border-slate-300 bg-white py-2 pl-9 pr-3 text-sm text-slate-800 placeholder:text-slate-400 focus:border-primary focus:outline-none focus:ring-2 focus:ring-primary/20"
                  onChange={(event) => setUnitSearch(event.target.value)}
                  placeholder="Hľadať byt podľa čísla, vchodu..."
                  type="search"
                  value={unitSearch}
                />
              </label>
            }
          />
        </div>
        <label className="mt-5 block max-w-md font-semibold text-slate-900">
          Filtrovať podľa bytového domu
          <select className="form-input mt-2 w-full" value={propertyFilter} onChange={(event) => setPropertyFilter(event.target.value)}>
            <option value="">Všetky domy</option>
            {properties.map((property) => (
              <option key={property.id} value={property.id}>{postTitle(property)}</option>
            ))}
          </select>
        </label>
        {properties.length === 0 && !loading && (
          <p className="mt-4 rounded-lg bg-amber-50 p-3 text-base font-medium text-amber-950">
            Pred pridaním bytu najprv vytvorte bytový dom.
          </p>
        )}
        {!loading && visibleUnits.length === 0 ? (
          <div className="mt-4">
            <EmptyTableState />
          </div>
        ) : (
        <div className="mt-4 overflow-x-auto">
          <table className="w-full min-w-[44rem] border-collapse text-left text-sm font-medium text-slate-800">
            <thead>
              <tr className="border-b border-slate-100 bg-slate-100 text-xs font-semibold text-slate-800">
                <th scope="col" className="px-3 py-1.5">Číslo bytu</th>
                <th scope="col" className="px-3 py-1.5">Bytový dom</th>
                <th scope="col" className="px-3 py-1.5">Poschodie</th>
                <th scope="col" className="px-3 py-1.5">Vchod</th>
                <th scope="col" className="px-3 py-1.5">Akcie</th>
              </tr>
            </thead>
            <tbody>
              {visibleUnits.map((unit) => (
                <tr key={unit.id} className="border-b border-slate-100 odd:bg-white even:bg-slate-50/50">
                  <th scope="row" className="px-3 py-1.5 text-left font-semibold text-slate-950">{unitNumber(unit)}</th>
                  <td className="px-3 py-1.5">{propertyNames.get(unit.property_id ?? 0) ?? "Dom nie je priradený"}</td>
                  <td className="px-3 py-1.5">{unit.floor || "—"}</td>
                  <td className="px-3 py-1.5">{unit.entrance || "—"}</td>
                  <td className="px-3 py-1.5">
                    <div className="flex items-center gap-1">
                      <ActionIconButton
                        action="edit"
                        compact
                        icon={Pencil}
                        label={`Upraviť byt ${unitNumber(unit)}`}
                        onClick={() => openUnitEditor(unit)}
                      />
                      <ActionIconButton
                        action="delete"
                        compact
                        icon={Trash2}
                        label={`Odstrániť byt ${unitNumber(unit)}`}
                        onClick={() => setUnitToDelete(unit)}
                      />
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
        )}
      </section>
      }

      {loading && <p role="status" className="font-semibold text-slate-800">Načítavam údaje nehnuteľností…</p>}
      {editor && (
        <dialog
          ref={dialogRef}
          aria-labelledby="property-editor-title"
          className="fixed inset-0 z-50 m-0 h-full max-h-none w-full max-w-none overflow-y-auto bg-slate-950/60 p-4 backdrop:bg-transparent"
          onCancel={(event) => {
            event.preventDefault();
            if (!saving) setEditor(null);
          }}
        >
          <div className="mx-auto my-8 w-full max-w-xl rounded-2xl border-2 border-slate-300 bg-white p-6 shadow-2xl sm:p-8">
            {editor.type === "property" ? (
              <form onSubmit={saveProperty} className="space-y-5">
                <h2 id="property-editor-title" className="text-2xl font-bold text-primary">
                  {editor.post ? "Upraviť bytový dom" : "Pridať bytový dom"}
                </h2>
                <label className="block text-base font-semibold text-slate-900">
                  Názov domu
                  <input
                    autoFocus
                    required
                    maxLength={200}
                    className="form-input mt-2 w-full"
                    value={propertyForm.name}
                    onChange={(event) => setPropertyForm({ ...propertyForm, name: event.target.value })}
                  />
                </label>
                <label className="block text-base font-semibold text-slate-900">
                  Adresa
                  <textarea
                    required
                    rows={3}
                    className="form-input mt-2 w-full"
                    value={propertyForm.address}
                    onChange={(event) => setPropertyForm({ ...propertyForm, address: event.target.value })}
                  />
                </label>
                {formError && <p role="alert" className="rounded-lg border-2 border-red-700 bg-red-50 p-3 font-semibold text-red-900">{formError}</p>}
                <div className="flex flex-wrap justify-end gap-3">
                  <button type="button" className="min-h-12 rounded-lg border-2 border-slate-500 px-5 font-semibold text-slate-900 hover:bg-slate-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent" disabled={saving} onClick={() => setEditor(null)}>Zrušiť</button>
                  <button type="submit" className="btn-primary min-h-12" disabled={saving}>{saving ? "Ukladá sa…" : "Uložiť dom"}</button>
                </div>
              </form>
            ) : (
              <form onSubmit={saveUnit} className="space-y-5">
                <h2 id="property-editor-title" className="text-2xl font-bold text-primary">
                  {editor.post ? "Upraviť bytovú jednotku" : "Pridať bytovú jednotku"}
                </h2>
                <label className="block text-base font-semibold text-slate-900">
                  Bytový dom
                  <select
                    autoFocus
                    required
                    className="form-input mt-2 w-full"
                    value={unitForm.propertyId}
                    onChange={(event) => setUnitForm({ ...unitForm, propertyId: event.target.value })}
                  >
                    <option value="">Vyberte bytový dom</option>
                    {properties.map((property) => <option key={property.id} value={property.id}>{postTitle(property)}</option>)}
                  </select>
                </label>
                <label className="block text-base font-semibold text-slate-900">
                  Číslo bytu
                  <input
                    required
                    maxLength={100}
                    className="form-input mt-2 w-full"
                    value={unitForm.number}
                    onChange={(event) => setUnitForm({ ...unitForm, number: event.target.value })}
                  />
                </label>
                <label className="block text-base font-semibold text-slate-900">
                  Poschodie
                  <input required maxLength={100} className="form-input mt-2 w-full" value={unitForm.floor} onChange={(event) => setUnitForm({ ...unitForm, floor: event.target.value })} />
                </label>
                <label className="block text-base font-semibold text-slate-900">
                  Vchod
                  <input required maxLength={100} className="form-input mt-2 w-full" value={unitForm.entrance} onChange={(event) => setUnitForm({ ...unitForm, entrance: event.target.value })} />
                </label>
                {formError && <p role="alert" className="rounded-lg border-2 border-red-700 bg-red-50 p-3 font-semibold text-red-900">{formError}</p>}
                <div className="flex flex-wrap justify-end gap-3">
                  <button type="button" className="min-h-12 rounded-lg border-2 border-slate-500 px-5 font-semibold text-slate-900 hover:bg-slate-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent" disabled={saving} onClick={() => setEditor(null)}>Zrušiť</button>
                  <button type="submit" className="btn-primary min-h-12" disabled={saving}>{saving ? "Ukladá sa…" : "Uložiť byt"}</button>
                </div>
              </form>
            )}
          </div>
        </dialog>
      )}

      {unitToDelete && (
        <dialog
          ref={deleteDialogRef}
          aria-labelledby="unit-delete-title"
          className="fixed inset-0 z-50 m-0 h-full max-h-none w-full max-w-none overflow-y-auto bg-slate-950/60 p-4 backdrop:bg-transparent"
          onCancel={(event) => {
            event.preventDefault();
            if (!deletingUnit) setUnitToDelete(null);
          }}
        >
          <div className="mx-auto my-16 w-full max-w-lg space-y-5 rounded-2xl border-2 border-slate-300 bg-white p-6 shadow-2xl sm:p-8">
            <div>
              <h2 id="unit-delete-title" className="text-2xl font-bold text-primary">Odstrániť bytovú jednotku?</h2>
              <p className="mt-3 leading-7 text-slate-800">
                Naozaj chcete odstrániť byt {unitNumber(unitToDelete)}? Priradené väzby na obyvateľov môžu byť dotknuté.
              </p>
            </div>
            {formError && <p role="alert" className="rounded-lg border-2 border-red-700 bg-red-50 p-3 font-semibold text-red-900">{formError}</p>}
            <div className="flex flex-wrap justify-end gap-3">
              <button type="button" className="min-h-12 rounded-lg border-2 border-slate-500 px-5 font-semibold text-slate-900 hover:bg-slate-100" disabled={deletingUnit} onClick={() => setUnitToDelete(null)}>Zrušiť</button>
              <button type="button" className="min-h-12 rounded-lg bg-red-700 px-5 font-semibold text-white hover:bg-red-800 disabled:opacity-60" disabled={deletingUnit} onClick={() => void deleteUnit()}>{deletingUnit ? "Odstraňuje sa…" : "Odstrániť byt"}</button>
            </div>
          </div>
        </dialog>
      )}
    </section>
  );
}
