import { StrictMode } from "react";
import { createRoot } from "react-dom/client";
import { App } from "./App";
import { ThemeProvider } from "./theme/ThemeContext";
import "./index.css";

const rootElement =
  document.getElementById("smartsused-root") ??
  document.getElementById("smartsused-admin-app");

if (!rootElement) {
  throw new Error("Nenašiel sa koreňový element aplikácie SmartSused.");
}

createRoot(rootElement).render(
  <StrictMode>
    <ThemeProvider>
      <App />
    </ThemeProvider>
  </StrictMode>,
);
