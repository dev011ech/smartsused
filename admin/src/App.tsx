import { GoogleOAuthProvider } from "@react-oauth/google";
import { BrowserRouter, HashRouter, Link, Route, Routes } from "react-router-dom";
import { AuthProvider } from "./auth/AuthContext";
import { AppLayout } from "./components/AppLayout";
import { ManagerDashboardPage, PropertiesPage, ResidentDashboardPage } from "./pages/DashboardPages";
import { OwnersPage } from "./pages/OwnersPage";
import { AdminNotices } from "./components/AdminNotices";
import { AdminTickets } from "./components/AdminTickets";
import { TechnicalPassport } from "./components/TechnicalPassport";
import { PollsPage } from "./components/PollsPage";
import { AdminDashboard } from "./components/AdminDashboard";
import { LoginPage } from "./pages/LoginPage";
import { RegisterTenant } from "./pages/RegisterTenant";
import { ProtectedRoute } from "./routing/ProtectedRoute";

function NotFoundPage() {
  return (
    <main className="grid min-h-screen place-items-center bg-slate-50 px-6 text-center">
      <div>
        <p className="text-sm font-semibold text-blue-700">Chyba 404</p>
        <h1 className="mt-2 text-3xl font-bold">Túto stránku sme nenašli</h1>
        <Link className="mt-5 inline-block font-semibold text-blue-700 hover:underline" to="/">
          Späť na úvod
        </Link>
      </div>
    </main>
  );
}

function AppRoutes() {
  return (
    <Routes>
      <Route path="/login" element={<LoginPage />} />
      <Route path="/register-tenant" element={<RegisterTenant />} />
      <Route element={<ProtectedRoute allowedRoles={["smartsused_manager"]} />}>
        <Route element={<AppLayout />}>
          <Route index element={<ManagerDashboardPage />} />
          <Route path="/admin/properties" element={<PropertiesPage section="properties" />} />
          <Route path="/admin/units" element={<PropertiesPage section="units" />} />
          <Route path="/admin/owners" element={<OwnersPage />} />
          <Route path="/vlastnici" element={<OwnersPage />} />
          <Route path="/admin/tickets" element={<AdminTickets />} />
          <Route path="/admin/notices" element={<AdminNotices />} />
        </Route>
        <Route element={<ProtectedRoute allowedRoles={["smartsused_manager", "smartsused_owner", "smartsused_tenant"]} />}>
          <Route element={<AppLayout />}>
            <Route path="/admin/polls" element={<PollsPage />} />
          </Route>
        </Route>
      </Route>
      <Route element={<ProtectedRoute allowedRoles={["administrator"]} />}>
        <Route element={<AppLayout />}>
          <Route path="/admin/dashboard" element={<AdminDashboard />} />
          <Route path="/admin/licence" element={<AdminDashboard />} />
          <Route path="/admin/houses" element={<AdminDashboard />} />
          <Route path="/admin/manager-assignments" element={<AdminDashboard />} />
          <Route path="/admin/google-auth" element={<AdminDashboard />} />
          <Route path="/admin/module-updates" element={<AdminDashboard />} />
        </Route>
      </Route>
      <Route element={<ProtectedRoute allowedRoles={["smartsused_manager", "smartsused_owner"]} />}>
        <Route element={<AppLayout />}>
          <Route path="/admin/technical-passport" element={<TechnicalPassport />} />
        </Route>
      </Route>
      <Route element={<ProtectedRoute allowedRoles={["smartsused_owner", "smartsused_tenant"]} />}>
        <Route element={<AppLayout />}>
          <Route path="/resident/dashboard" element={<ResidentDashboardPage />} />
        </Route>
      </Route>
      <Route path="*" element={<NotFoundPage />} />
    </Routes>
  );
}

function prepareWordPressUpdaterEntry() {
  const url = new URL(window.location.href);
  if (url.searchParams.get("smartsused_updater") !== "1") return;

  localStorage.removeItem("smartsused_auth_session");
  localStorage.removeItem("smartsused_jwt");
  url.searchParams.delete("smartsused_updater");
  window.history.replaceState(window.history.state, "", url);
}

export function App() {
  prepareWordPressUpdaterEntry();
  const embeddedInWordPressAdmin = window.location.pathname.endsWith("/admin.php");
  const Router = embeddedInWordPressAdmin ? HashRouter : BrowserRouter;
  const googleClientId =
    window.smartsusedConfig?.google_client_id ??
    (import.meta.env.VITE_GOOGLE_CLIENT_ID as string | undefined);

  const application = (
    <AuthProvider>
      <Router future={{ v7_startTransition: true, v7_relativeSplatPath: true }}>
        <AppRoutes />
      </Router>
    </AuthProvider>
  );

  if (!googleClientId) {
    return application;
  }

  return (
    <GoogleOAuthProvider clientId={googleClientId}>{application}</GoogleOAuthProvider>
  );
}
