import { SearchX } from "lucide-react";

export function EmptyTableState() {
  return (
    <div className="flex flex-col items-center justify-center gap-2 rounded-xl border border-slate-200 bg-white px-4 py-10 text-center text-sm text-slate-500">
      <SearchX aria-hidden="true" size={22} strokeWidth={1.5} />
      <p>Nenašli sa žiadne záznamy zodpovedajúce zadaným kritériám.</p>
    </div>
  );
}
