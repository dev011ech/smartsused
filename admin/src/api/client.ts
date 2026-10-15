import axios from "axios";

declare global {
  interface Window {
    smartsusedAdmin?: {
      restUrl?: string;
      nonce?: string;
      canUpdatePlugins?: boolean;
      wordpressDisplayName?: string;
    };
    smartsusedConfig?: {
      google_client_id?: string;
      rest_url?: string;
    };
  }
}

const restUrl =
  window.smartsusedAdmin?.restUrl ??
  window.smartsusedConfig?.rest_url ??
  import.meta.env.VITE_WORDPRESS_REST_URL ??
  "";

export const apiClient = axios.create({
  baseURL: restUrl,
  headers: {
    Accept: "application/json",
    "Content-Type": "application/json",
  },
});

apiClient.interceptors.request.use((config) => {
  if (!restUrl) {
    throw new Error(
      "Chýba URL WordPress REST API. Nastavte ju cez wp_localize_script alebo VITE_WORDPRESS_REST_URL.",
    );
  }

  const isModuleUpdateRequest =
    typeof config.url === "string" &&
    /(?:^|\/)smartsused\/v1\/modules\/update(?:\?|$)/.test(config.url);
  const useWordPressAdminSession =
    isModuleUpdateRequest && window.smartsusedAdmin?.canUpdatePlugins === true;
  const token = useWordPressAdminSession ? null : localStorage.getItem("smartsused_jwt");
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }

  const nonce = window.smartsusedAdmin?.nonce;
  if (nonce) {
    config.headers["X-WP-Nonce"] = nonce;
  }

  return config;
});
