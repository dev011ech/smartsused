import { useAuth } from "../auth/AuthContext";
import { ManageTenants } from "../components/ManageTenants";
import { ResidentTickets } from "../components/ResidentTickets";

export { PropertiesPage } from "./PropertiesPage";

const cards = [
  { title: "Oznamy", description: "Dôležité informácie od správcu domu.", icon: "✦" },
  { title: "Nahlásiť poruchu", description: "Dajte správcovi vedieť o probléme.", icon: "⌁" },
  { title: "Odpočty", description: "Zadajte aktuálny stav vašich meradiel.", icon: "◷" },
];

export function ManagerDashboardPage() {
  return (
    <section>
      <p className="text-sm font-semibold text-blue-700">SmartSused · Správa</p>
      <h1 className="mt-2 text-3xl font-bold tracking-tight">Prehľad správcu</h1>
      <p className="mt-2 text-slate-600">Vitajte v správe bytových domov.</p>
    </section>
  );
}

export function ResidentDashboardPage() {
  const { user } = useAuth();

  return (
    <section>
      <p className="text-sm font-semibold text-blue-700">SmartSused · Domov</p>
      <h1 className="mt-2 text-3xl font-bold tracking-tight">Moja nástenka</h1>
      <p className="mt-2 text-slate-600">Všetko dôležité pre váš domov na jednom mieste.</p>
      <div className="mt-8 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        {cards.map((card) => (
          <article key={card.title} className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <span aria-hidden="true" className="flex size-11 items-center justify-center rounded-xl bg-cyan-50 text-xl text-cyan-800">
              {card.icon}
            </span>
            <h2 className="mt-5 text-lg font-semibold">{card.title}</h2>
            <p className="mt-2 text-sm leading-6 text-slate-600">{card.description}</p>
          </article>
        ))}
      </div>
      <div className="mt-8">
        <ResidentTickets />
      </div>
      {user?.roles.includes("smartsused_owner") && (
        <div className="mt-8">
          <ManageTenants />
        </div>
      )}
    </section>
  );
}
