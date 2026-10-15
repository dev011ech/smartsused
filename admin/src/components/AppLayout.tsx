import { useEffect, useState } from "react";
import axios from "axios";
import { Moon, Sun } from "lucide-react";
import { NavLink, Outlet } from "react-router-dom";
import { apiClient } from "../api/client";
import { useAuth } from "../auth/AuthContext";
import { useTheme } from "../theme/ThemeContext";

type ModuleStatus = {
  polls: boolean;
  core_version: string;
};

export function AppLayout() {
  const { user, logout } = useAuth();
  const { theme, toggleTheme } = useTheme();
  const [moduleStatus, setModuleStatus] = useState<ModuleStatus | null>(null);
  const isManager = user?.role === "smartsused_manager";
  const isAdministrator = user?.roles.includes("administrator") ?? false;

  useEffect(() => {
    let isMounted = true;

    apiClient
      .get<ModuleStatus>("smartsused/v1/modules/status")
      .then(({ data }) => {
        if (
          isMounted &&
          typeof data?.polls === "boolean" &&
          typeof data?.core_version === "string"
        ) {
          setModuleStatus(data);
        }
      })
      .catch((error: unknown) => {
        if (axios.isAxiosError(error)) {
          console.error("Nepodarilo sa načítať stav modulov SmartSused.", error.message);
        } else {
          console.error("Nepodarilo sa načítať stav modulov SmartSused.", error);
        }
      });

    return () => {
      isMounted = false;
    };
  }, []);

  const links = isAdministrator
    ? [
        { to: "/admin/dashboard", label: "Prehľad administrátora" },
        { to: "/admin/licence", label: "Licencia" },
        { to: "/admin/houses", label: "Bytové domy" },
        { to: "/admin/manager-assignments", label: "Priradenie správcov" },
        { to: "/admin/google-auth", label: "Google Auth" },
        { to: "/admin/module-updates", label: "Aktualizácie modulov" },
      ]
    : isManager
    ? [
        { to: "/", label: "Prehľad" },
        { to: "/admin/properties", label: "Bytové domy" },
        { to: "/admin/units", label: "Bytové jednotky" },
        { to: "/vlastnici", label: "Vlastníci" },
        { to: "/admin/tickets", label: "Poruchy" },
        { to: "/admin/notices", label: "Digitálna nástenka" },
        { to: "/admin/technical-passport", label: "Technický pas" },
        ...(moduleStatus?.polls ? [{ to: "/admin/polls", label: "Ankety" }] : []),
      ]
    : [
        { to: "/resident/dashboard", label: "Moja nástenka" },
        ...(user?.roles.includes("smartsused_owner")
          ? [{ to: "/admin/technical-passport", label: "Technický pas" }]
          : []),
        ...(moduleStatus?.polls ? [{ to: "/admin/polls", label: "Ankety" }] : []),
      ];

  return (
    <div className="min-h-screen bg-slate-50 text-slate-900">
      <header className="border-b border-slate-200 bg-white">
        <div className="mx-auto flex max-w-7xl items-center justify-between gap-4 px-4 py-4 sm:px-6 lg:px-8">
          {isAdministrator ? (
            <span className="text-xl font-bold tracking-tight text-blue-950">SmartSused</span>
          ) : (
            <NavLink to={isManager ? "/" : "/resident/dashboard"} className="text-xl font-bold tracking-tight text-blue-950">
              SmartSused
            </NavLink>
          )}
          <div className="flex items-center gap-3">
            <button
              type="button"
              onClick={toggleTheme}
              aria-label={theme === "dark" ? "Prepnúť na svetlý režim" : "Prepnúť na tmavý režim"}
              title={theme === "dark" ? "Svetlý režim" : "Tmavý režim"}
              className="inline-flex size-11 shrink-0 items-center justify-center rounded-lg border border-slate-300 text-slate-700 transition-colors hover:bg-slate-100 focus:outline-none focus:ring-2 focus:ring-blue-600 dark:border-slate-600 dark:text-slate-200 dark:hover:bg-slate-700"
            >
              {theme === "dark" ? (
                <Sun aria-hidden="true" className="size-5" />
              ) : (
                <Moon aria-hidden="true" className="size-5" />
              )}
            </button>
            <span className="hidden text-sm text-slate-600 sm:inline">
              {user?.display_name}
              {user?.display_name ? " · " : ""}
              {isAdministrator ? "Administrátor" : user?.role === "smartsused_manager" ? "Správca" : user?.role === "smartsused_owner" ? "Vlastník" : "Nájomca"}
            </span>
            <button
              type="button"
              onClick={logout}
              className="min-h-11 rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-blue-600 dark:border-slate-600 dark:text-slate-100 dark:hover:bg-slate-700"
            >
              Odhlásiť sa
            </button>
          </div>
        </div>
      </header>
      <div className="mx-auto grid max-w-7xl gap-6 px-4 py-6 sm:px-6 lg:grid-cols-[230px_1fr] lg:px-8">
        <div className="flex flex-col justify-between gap-6">
          <nav aria-label="Hlavná navigácia" className="flex gap-2 overflow-x-auto lg:flex-col">
            {links.map((link) => (
              <NavLink
                key={link.to}
                to={link.to}
                className={({ isActive }) =>
                  `shrink-0 rounded-xl px-4 py-3 text-sm font-medium ${isActive ? "bg-blue-950 text-white" : "text-slate-700 hover:bg-white"}`
                }
              >
                {link.label}
              </NavLink>
            ))}
          </nav>
          <p className="hidden text-xs text-slate-400 lg:block">
            Verzia aplikácie: {moduleStatus?.core_version ? `v${moduleStatus.core_version}` : "…"}
          </p>
        </div>
        <main className="min-w-0">
          <Outlet />
        </main>
      </div>
    </div>
  );
}
