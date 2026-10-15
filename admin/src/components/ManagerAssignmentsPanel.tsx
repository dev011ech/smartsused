import { useCallback, useEffect, useState, type FormEvent } from "react";
import axios from "axios";
import { Save, UsersRound } from "lucide-react";
import { apiClient } from "../api/client";

type House = { id: number; title: string };
type Manager = { id: number; display_name: string; email: string; property_ids: number[] };
type AssignmentData = { managers: Manager[]; houses: House[] };

function getErrorMessage(error: unknown) {
  if (axios.isAxiosError<{ message?: string }>(error)) {
    return error.response?.data?.message ?? "Priradenia správcov sa nepodarilo načítať.";
  }
  return error instanceof Error ? error.message : "Priradenia správcov sa nepodarilo načítať.";
}

export function ManagerAssignmentsPanel() {
  const [data, setData] = useState<AssignmentData>({ managers: [], houses: [] });
  const [selectedManagerId, setSelectedManagerId] = useState("");
  const [selectedHouseIds, setSelectedHouseIds] = useState<number[]>([]);
  const [error, setError] = useState("");
  const [notice, setNotice] = useState("");
  const [isLoading, setIsLoading] = useState(true);
  const [isSaving, setIsSaving] = useState(false);

  const loadAssignments = useCallback(async () => {
    setIsLoading(true);
    setError("");
    try {
      const response = await apiClient.get<AssignmentData>("smartsused/v1/admin/manager-assignments");
      if (!Array.isArray(response.data.managers) || !Array.isArray(response.data.houses)) {
        throw new Error("Server vrátil neplatné údaje o správcoch.");
      }
      setData(response.data);
      const initialManagerId = response.data.managers[0]?.id.toString() ?? "";
      setSelectedManagerId(initialManagerId);
      setSelectedHouseIds(response.data.managers[0]?.property_ids ?? []);
    } catch (loadError) {
      setError(getErrorMessage(loadError));
    } finally {
      setIsLoading(false);
    }
  }, []);

  useEffect(() => {
    void loadAssignments();
  }, [loadAssignments]);

  function selectManager(managerId: string) {
    setSelectedManagerId(managerId);
    setSelectedHouseIds(data.managers.find((manager) => manager.id.toString() === managerId)?.property_ids ?? []);
    setNotice("");
  }

  function toggleHouse(houseId: number) {
    setSelectedHouseIds((current) =>
      current.includes(houseId) ? current.filter((id) => id !== houseId) : [...current, houseId],
    );
  }

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!selectedManagerId) return;
    setError("");
    setNotice("");
    setIsSaving(true);
    try {
      await apiClient.post("smartsused/v1/admin/manager-assignments", {
        manager_id: Number(selectedManagerId),
        property_ids: selectedHouseIds,
      });
      setData((current) => ({
        ...current,
        managers: current.managers.map((manager) =>
          manager.id.toString() === selectedManagerId
            ? { ...manager, property_ids: selectedHouseIds }
            : manager,
        ),
      }));
      setNotice("Priradenie správcu bolo uložené.");
    } catch (saveError) {
      setError(getErrorMessage(saveError));
    } finally {
      setIsSaving(false);
    }
  }

  const selectedManager = data.managers.find((manager) => manager.id.toString() === selectedManagerId);

  return (
    <section className="space-y-5" aria-labelledby="manager-assignments-heading">
      <header>
        <h2 id="manager-assignments-heading" className="text-2xl font-bold text-primary">Priradenie správcov</h2>
        <p className="mt-2 text-sm leading-6 text-slate-700">Každý správca vidí iba domy priradené k jeho účtu.</p>
      </header>
      {error && <p role="alert" className="rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-800">{error}</p>}
      {notice && <p role="status" className="rounded-lg border border-emerald-300 bg-emerald-50 px-4 py-3 text-sm text-emerald-900">{notice}</p>}

      {isLoading ? (
        <p className="rounded-xl border border-slate-200 bg-white p-6 text-sm text-slate-500">Načítavam správcov a bytové domy…</p>
      ) : data.managers.length === 0 ? (
        <p className="rounded-xl border border-slate-200 bg-white p-6 text-sm text-slate-600">V systéme zatiaľ nie sú účty s rolou správcu.</p>
      ) : (
        <form onSubmit={submit} className="grid gap-6 rounded-2xl border border-slate-300 bg-white p-5 shadow-sm sm:p-7 lg:grid-cols-[minmax(220px,1fr)_2fr]">
          <label className="block text-sm font-semibold text-slate-800">
            Správca
            <select
              value={selectedManagerId}
              onChange={(event) => selectManager(event.target.value)}
              className="mt-2 block min-h-11 w-full rounded-lg border border-slate-300 bg-white px-3 outline-none focus:border-blue-700 focus:ring-2 focus:ring-blue-100"
            >
              {data.managers.map((manager) => (
                <option key={manager.id} value={manager.id}>{manager.display_name} · {manager.email}</option>
              ))}
            </select>
          </label>
          <fieldset className="space-y-3">
            <legend className="text-sm font-semibold text-slate-800">Priradené bytové domy</legend>
            {data.houses.length === 0 ? (
              <p className="text-sm text-slate-600">Najprv vytvorte bytový dom v sekcii Bytové domy.</p>
            ) : (
              <div className="grid gap-2 sm:grid-cols-2">
                {data.houses.map((house) => (
                  <label key={house.id} className="flex min-h-12 cursor-pointer items-center gap-3 rounded-lg border border-slate-200 px-3 py-2 text-sm hover:bg-slate-50">
                    <input type="checkbox" checked={selectedHouseIds.includes(house.id)} onChange={() => toggleHouse(house.id)} className="size-4 accent-blue-950" />
                    <span><UsersRound aria-hidden="true" className="mr-2 inline size-4 text-blue-700" />{house.title}</span>
                  </label>
                ))}
              </div>
            )}
            <p className="text-xs text-slate-500">
              {selectedManager ? `Vybraných domov: ${selectedHouseIds.length}` : "Vyberte správcu."}
            </p>
            <button
              type="submit"
              disabled={isSaving || !selectedManager}
              className="inline-flex min-h-11 items-center gap-2 rounded-lg bg-blue-950 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-900 disabled:cursor-not-allowed disabled:opacity-60"
            >
              <Save aria-hidden="true" className="size-4" />
              {isSaving ? "Ukladám…" : "Uložiť priradenia"}
            </button>
          </fieldset>
        </form>
      )}
    </section>
  );
}
