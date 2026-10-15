import { useCallback, useEffect, useMemo, useState, type FormEvent } from "react";
import axios from "axios";
import { Trash2 } from "lucide-react";
import { apiClient } from "../api/client";
import { ActionIconButton } from "./ActionIconButton";

type Tenant = {
  id: number;
  name: string;
  email: string;
};

type OwnedUnit = {
  id: number;
  title: string;
  tenants: Tenant[];
};

type UnitResponse = { units: OwnedUnit[] };

function getErrorMessage(error: unknown) {
  if (axios.isAxiosError<{ message?: string }>(error)) {
    return error.response?.data?.message ?? "Požiadavku sa nepodarilo dokončiť. Skúste to znova.";
  }
  return error instanceof Error ? error.message : "Požiadavku sa nepodarilo dokončiť. Skúste to znova.";
}

export function ManageTenants() {
  const [units, setUnits] = useState<OwnedUnit[]>([]);
  const [unitId, setUnitId] = useState("");
  const [email, setEmail] = useState("");
  const [loading, setLoading] = useState(true);
  const [submitting, setSubmitting] = useState(false);
  const [removingTenantId, setRemovingTenantId] = useState<number | null>(null);
  const [error, setError] = useState("");
  const [success, setSuccess] = useState("");

  const selectedUnit = useMemo(
    () => units.find((unit) => String(unit.id) === unitId) ?? null,
    [units, unitId],
  );

  const loadUnits = useCallback(async () => {
    setLoading(true);
    setError("");
    try {
      const { data } = await apiClient.get<UnitResponse>("smartsused/v1/tenant/units");
      if (!Array.isArray(data.units)) {
        throw new Error("Server vrátil neplatný zoznam bytových jednotiek.");
      }
      setUnits(data.units);
      setUnitId((current) =>
        data.units.some((unit) => String(unit.id) === current)
          ? current
          : data.units[0]
            ? String(data.units[0].id)
            : "",
      );
    } catch (requestError) {
      setError(getErrorMessage(requestError));
    } finally {
      setLoading(false);
    }
  }, []);

  useEffect(() => {
    void loadUnits();
  }, [loadUnits]);

  async function sendInvite(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!unitId || submitting) return;

    setSubmitting(true);
    setError("");
    setSuccess("");

    try {
      await apiClient.post("smartsused/v1/tenant/invite", {
        email: email.trim(),
        unit_id: Number(unitId),
      });
      setEmail("");
      setSuccess("Pozvánka bola úspešne odoslaná na e-mail nájomcu.");
      await loadUnits();
    } catch (requestError) {
      setError(getErrorMessage(requestError));
    } finally {
      setSubmitting(false);
    }
  }

  async function removeTenant(tenant: Tenant) {
    if (!selectedUnit || removingTenantId !== null) return;
    const confirmed = window.confirm(
      `Naozaj chcete odobrať nájomcu ${tenant.name || tenant.email} z bytu ${selectedUnit.title}? Účet používateľa zostane zachovaný.`,
    );
    if (!confirmed) return;

    setRemovingTenantId(tenant.id);
    setError("");
    setSuccess("");
    try {
      await apiClient.delete(
        `smartsused/v1/tenant/${tenant.id}/unit/${selectedUnit.id}`,
      );
      setSuccess("Nájomca bol odobratý z bytovej jednotky.");
      await loadUnits();
    } catch (requestError) {
      setError(getErrorMessage(requestError));
    } finally {
      setRemovingTenantId(null);
    }
  }

  return (
    <section
      aria-labelledby="manage-tenants-title"
      className="rounded-2xl border border-slate-300 bg-white p-5 shadow-sm sm:p-7"
    >
      <header>
        <p className="text-sm font-bold text-emerald-800">Správa nájomcov</p>
        <h2 id="manage-tenants-title" className="mt-1 text-2xl font-bold text-primary">
          Pozvať nájomcu do bytu
        </h2>
        <p className="mt-2 text-base leading-7 text-slate-700">
          Pozvánku môže poslať vlastník bytu. Odkaz na registráciu príde nájomcovi e-mailom.
        </p>
      </header>

      {error && (
        <p role="alert" className="mt-5 rounded-xl border-2 border-red-700 bg-red-50 p-4 font-semibold text-red-950">
          {error}
        </p>
      )}
      {success && (
        <p role="status" className="mt-5 rounded-xl border-2 border-emerald-700 bg-emerald-50 p-4 font-semibold text-emerald-950">
          {success}
        </p>
      )}

      <form className="mt-6 grid gap-5 md:grid-cols-[1fr_1fr_auto] md:items-end" onSubmit={sendInvite}>
        <label className="block text-base font-semibold text-slate-950">
          E-mail nájomcu
          <input
            autoComplete="email"
            className="form-input mt-2 w-full"
            type="email"
            required
            value={email}
            onChange={(event) => {
              setEmail(event.target.value);
              setSuccess("");
            }}
          />
        </label>
        <label className="block text-base font-semibold text-slate-950">
          Bytová jednotka
          <select
            className="form-input mt-2 w-full"
            required
            disabled={loading || units.length === 0}
            value={unitId}
            onChange={(event) => {
              setUnitId(event.target.value);
              setSuccess("");
            }}
          >
            <option value="">Vyberte byt</option>
            {units.map((unit) => (
              <option key={unit.id} value={unit.id}>
                {unit.title}
              </option>
            ))}
          </select>
        </label>
        <button
          type="submit"
          className="min-h-12 rounded-xl bg-emerald-800 px-5 py-3 font-bold text-white shadow-sm transition hover:bg-emerald-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-emerald-700 disabled:cursor-not-allowed disabled:opacity-60"
          disabled={loading || units.length === 0 || submitting}
        >
          {submitting ? "Odosiela sa…" : "Odoslať pozvánku"}
        </button>
      </form>

      <div className="mt-8 border-t border-slate-200 pt-6">
        <h3 className="text-xl font-bold text-primary">Aktuálni nájomcovia</h3>
        {selectedUnit && (
          <p className="mt-1 text-base text-slate-700">
            Byt: <span className="font-semibold">{selectedUnit.title}</span>
          </p>
        )}
        {loading ? (
          <p role="status" className="mt-4 text-base font-medium text-slate-700">
            Načítavam zoznam bytov a nájomcov…
          </p>
        ) : units.length === 0 ? (
          <p className="mt-4 rounded-xl bg-amber-50 p-4 text-base font-medium text-amber-950">
            K vášmu účtu zatiaľ nie je priradený žiadny byt.
          </p>
        ) : selectedUnit?.tenants.length ? (
          <ul className="mt-4 divide-y divide-slate-200 rounded-xl border border-slate-300">
            {selectedUnit.tenants.map((tenant) => (
              <li
                key={tenant.id}
                className="flex flex-col gap-4 p-4 sm:flex-row sm:items-center sm:justify-between"
              >
                <div className="min-w-0">
                  <p className="break-words font-semibold text-slate-950">{tenant.name || "Nájomca"}</p>
                  <p className="break-all text-sm text-slate-700">{tenant.email}</p>
                </div>
                <ActionIconButton
                  action="delete"
                  icon={Trash2}
                  label={`Odobrať nájomcu ${tenant.name || tenant.email}`}
                  disabled={removingTenantId !== null}
                  onClick={() => void removeTenant(tenant)}
                />
              </li>
            ))}
          </ul>
        ) : (
          <p className="mt-4 rounded-xl bg-slate-50 p-4 text-base text-slate-700">
            K tomuto bytu zatiaľ nie je priradený žiadny nájomca.
          </p>
        )}
      </div>
    </section>
  );
}
