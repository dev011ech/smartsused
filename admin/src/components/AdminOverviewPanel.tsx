import { useEffect, useState } from "react";
import axios from "axios";
import { apiClient } from "../api/client";

type AdminOverview = {
  core_version: string;
  published_houses: number;
  managers: number;
  license_status: "active" | "inactive";
};

export function AdminOverviewPanel() {
  const [overview, setOverview] = useState<AdminOverview | null>(null);
  const [error, setError] = useState("");

  useEffect(() => {
    let isMounted = true;

    apiClient
      .get<AdminOverview>("smartsused/v1/admin/overview")
      .then(({ data }) => {
        if (
          !Number.isInteger(data?.published_houses) ||
          !Number.isInteger(data?.managers) ||
          typeof data?.core_version !== "string" ||
          (data?.license_status !== "active" && data?.license_status !== "inactive")
        ) {
          throw new Error("Server vrátil neplatné štatistiky administrácie.");
        }
        if (isMounted) setOverview(data);
      })
      .catch((requestError: unknown) => {
        if (!isMounted) return;
        setError(
          axios.isAxiosError<{ message?: string }>(requestError)
            ? requestError.response?.data?.message ?? "Prehľad systému sa nepodarilo načítať."
            : requestError instanceof Error
              ? requestError.message
              : "Prehľad systému sa nepodarilo načítať.",
        );
      });

    return () => {
      isMounted = false;
    };
  }, []);

  if (error) {
    return <p role="alert" className="rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-800">{error}</p>;
  }

  const items = [
    { label: "Verzia SmartSused Core", value: overview ? `v${overview.core_version}` : "Načítavam…" },
    { label: "Aktívne bytové domy", value: overview ? String(overview.published_houses) : "…" },
    { label: "Správcovia", value: overview ? String(overview.managers) : "…" },
    { label: "Licencia", value: overview ? (overview.license_status === "active" ? "Aktívna" : "Neaktívna") : "…" },
  ];

  return (
    <section aria-label="Štatistiky systému" className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
      {items.map((item) => (
        <article key={item.label} className="rounded-2xl border border-slate-300 bg-white p-5 shadow-sm">
          <p className="text-xs font-semibold uppercase tracking-wide text-slate-500">{item.label}</p>
          <p className="mt-2 text-xl font-bold text-slate-950">{item.value}</p>
        </article>
      ))}
    </section>
  );
}
