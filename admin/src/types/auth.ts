export const APP_ROLES = [
  "smartsused_manager",
  "smartsused_owner",
  "smartsused_tenant",
  "administrator",
] as const;

export type AppRole = (typeof APP_ROLES)[number];

export interface AuthUser {
  id: number;
  display_name: string;
  role: AppRole;
  roles: AppRole[];
}

export interface AuthSession {
  token: string;
  user: AuthUser;
}

export interface AuthResponse {
  token: string;
  user_id: number;
  display_name: string;
  role: string;
  roles?: string[];
}

export interface LoginCredentials {
  email: string;
  password: string;
}
