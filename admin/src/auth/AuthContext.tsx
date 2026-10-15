import {
  createContext,
  useCallback,
  useContext,
  useMemo,
  useState,
  type PropsWithChildren,
} from "react";
import axios from "axios";
import { apiClient } from "../api/client";
import {
  APP_ROLES,
  type AppRole,
  type AuthResponse,
  type AuthSession,
  type AuthUser,
  type LoginCredentials,
} from "../types/auth";

const SESSION_STORAGE_KEY = "smartsused_auth_session";

interface AuthContextValue {
  session: AuthSession | null;
  user: AuthUser | null;
  isAuthenticated: boolean;
  login: (credentials: LoginCredentials) => Promise<void>;
  loginWithGoogle: (idToken: string) => Promise<void>;
  logout: () => void;
}

const AuthContext = createContext<AuthContextValue | null>(null);

const isAppRole = (role: string): role is AppRole =>
  APP_ROLES.some((appRole) => appRole === role);

const parseSession = (value: string | null): AuthSession | null => {
  if (!value) return null;

  try {
    const parsed: unknown = JSON.parse(value);
    if (
      typeof parsed !== "object" ||
      parsed === null ||
      !("token" in parsed) ||
      typeof parsed.token !== "string" ||
      !("user" in parsed) ||
      typeof parsed.user !== "object" ||
      parsed.user === null ||
      !("id" in parsed.user) ||
      typeof parsed.user.id !== "number" ||
      !("display_name" in parsed.user) ||
      typeof parsed.user.display_name !== "string" ||
      !("roles" in parsed.user) ||
      !Array.isArray(parsed.user.roles)
    ) {
      return null;
    }

    const roles = parsed.user.roles.filter(
      (role: unknown): role is AppRole => typeof role === "string" && isAppRole(role),
    );
    const displayName =
      "display_name" in parsed.user && typeof parsed.user.display_name === "string"
        ? parsed.user.display_name
        : "";
    const roleValue = "role" in parsed.user ? parsed.user.role : roles[0];
    if (typeof roleValue !== "string" || !isAppRole(roleValue) || roles.length === 0) {
      return null;
    }

    return {
      token: parsed.token,
      user: {
        id: parsed.user.id,
        display_name: displayName,
        role: roleValue,
        roles,
      },
    };
  } catch {
    return null;
  }
};

const toSession = (response: AuthResponse): AuthSession => {
  const roles = Array.isArray(response.roles)
    ? response.roles.filter(isAppRole)
    : isAppRole(response.role)
      ? [response.role]
      : [];

  if (
    !response.token ||
    !Number.isInteger(response.user_id) ||
    typeof response.display_name !== "string" ||
    roles.length === 0
  ) {
    throw new Error("Server vrátil neúplné údaje o prihlásení alebo neznámu rolu.");
  }

  return {
    token: response.token,
    user: {
      id: response.user_id,
      display_name: response.display_name,
      role: isAppRole(response.role) ? response.role : roles[0],
      roles,
    },
  };
};

const persistSession = (session: AuthSession): void => {
  localStorage.setItem(SESSION_STORAGE_KEY, JSON.stringify(session));
  localStorage.setItem("smartsused_jwt", session.token);
};

const getAuthError = (error: unknown): Error => {
  if (axios.isAxiosError<{ message?: string }>(error)) {
    return new Error(error.response?.data?.message ?? "Prihlásenie sa nepodarilo. Skúste to znova.");
  }

  return error instanceof Error ? error : new Error("Prihlásenie sa nepodarilo. Skúste to znova.");
};

const getGoogleAuthError = (error: unknown): Error => {
  if (axios.isAxiosError(error) && error.response?.status === 403) {
    return new Error(
      "Prístup zamietnutý. Tento Google účet nie je povolený alebo jeho e-mail nie je registrovaný v systéme správcu. Kontaktujte svojho správcu.",
    );
  }

  return getAuthError(error);
};

export function AuthProvider({ children }: PropsWithChildren) {
  const [session, setSession] = useState<AuthSession | null>(() => {
    const stored = parseSession(localStorage.getItem(SESSION_STORAGE_KEY));
    if (stored) {
      localStorage.setItem("smartsused_jwt", stored.token);
      return stored;
    }

    localStorage.removeItem(SESSION_STORAGE_KEY);
    localStorage.removeItem("smartsused_jwt");
    return null;
  });

  const acceptAuthResponse = useCallback((response: AuthResponse) => {
    const newSession = toSession(response);
    persistSession(newSession);
    setSession(newSession);
  }, []);

  const login = useCallback(
    async ({ email, password }: LoginCredentials) => {
      try {
        const { data } = await apiClient.post<AuthResponse>("smartsused/v1/auth/login", {
          email,
          password,
        });
        acceptAuthResponse(data);
      } catch (error) {
        throw getAuthError(error);
      }
    },
    [acceptAuthResponse],
  );

  const loginWithGoogle = useCallback(
    async (idToken: string) => {
      try {
        const { data } = await apiClient.post<AuthResponse>("smartsused/v1/auth/google", {
          id_token: idToken,
        });
        acceptAuthResponse(data);
      } catch (error) {
        throw getGoogleAuthError(error);
      }
    },
    [acceptAuthResponse],
  );

  const logout = useCallback(() => {
    localStorage.removeItem(SESSION_STORAGE_KEY);
    localStorage.removeItem("smartsused_jwt");
    setSession(null);
  }, []);

  const value = useMemo<AuthContextValue>(
    () => ({
      session,
      user: session?.user ?? null,
      isAuthenticated: session !== null,
      login,
      loginWithGoogle,
      logout,
    }),
    [session, login, loginWithGoogle, logout],
  );

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>;
}

export function useAuth(): AuthContextValue {
  const context = useContext(AuthContext);
  if (!context) {
    throw new Error("useAuth musí byť použitý vo vnútri AuthProvider.");
  }
  return context;
}

export const roleHome: Record<AppRole, string> = {
  smartsused_manager: "/",
  smartsused_owner: "/resident/dashboard",
  smartsused_tenant: "/resident/dashboard",
  administrator: "/admin/dashboard",
};
