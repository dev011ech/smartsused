import { useLocation } from "react-router-dom";
import { AdminHousesPanel } from "./AdminHousesPanel";
import { AdminLicencePanel } from "./AdminLicencePanel";
import { ManagerAssignmentsPanel } from "./ManagerAssignmentsPanel";
import { ModuleUpdater } from "./ModuleUpdater";
import { AdminGoogleAuthPanel } from "./AdminGoogleAuthPanel";
import { AdminOverviewPanel } from "./AdminOverviewPanel";

export function AdminDashboard() {
  const { pathname } = useLocation();

  return (
    <main className="space-y-6">
      {pathname === "/admin/dashboard" ? (
        <>
          <header>
            <p className="text-sm font-semibold text-blue-800">SmartSused · Správa</p>
            <h1 className="mt-2 text-3xl font-bold tracking-tight text-primary">Administrátorský prehľad</h1>
            <p className="mt-2 max-w-3xl text-base leading-7 text-slate-700">Stav systému a základné štatistiky.</p>
          </header>
          <AdminOverviewPanel />
        </>
      ) : pathname === "/admin/houses" ? (
        <AdminHousesPanel />
      ) : pathname === "/admin/manager-assignments" ? (
        <ManagerAssignmentsPanel />
      ) : pathname === "/admin/google-auth" ? (
        <AdminGoogleAuthPanel />
      ) : pathname === "/admin/module-updates" ? (
        <ModuleUpdater />
      ) : (
        <AdminLicencePanel />
      )}
    </main>
  );
}
