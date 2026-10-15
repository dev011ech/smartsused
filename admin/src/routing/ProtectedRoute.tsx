import { Navigate, Outlet, useLocation } from "react-router-dom";
import { roleHome, useAuth } from "../auth/AuthContext";
import type { AppRole } from "../types/auth";

interface ProtectedRouteProps {
  allowedRoles: AppRole[];
}

export function ProtectedRoute({ allowedRoles }: ProtectedRouteProps) {
  const { isAuthenticated, user } = useAuth();
  const location = useLocation();

  if (!isAuthenticated || !user) {
    return <Navigate to="/login" replace state={{ from: location }} />;
  }

  if (!allowedRoles.some((role) => user.roles.includes(role))) {
    return <Navigate to={roleHome[user.role]} replace />;
  }

  return <Outlet />;
}
