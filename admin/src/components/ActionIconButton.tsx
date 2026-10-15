import type { LucideIcon } from "lucide-react";
import type { ButtonHTMLAttributes } from "react";

type ActionIconButtonProps = Omit<ButtonHTMLAttributes<HTMLButtonElement>, "aria-label"> & {
  icon: LucideIcon;
  label: string;
  action: "edit" | "delete" | "download" | "upload";
  compact?: boolean;
};

const actionClasses: Record<ActionIconButtonProps["action"], string> = {
  edit: "hover:bg-blue-50 hover:text-blue-600",
  delete: "hover:bg-red-50 hover:text-red-600",
  download: "hover:bg-emerald-50 hover:text-emerald-600",
  upload: "hover:bg-emerald-50 hover:text-emerald-600",
};

export function ActionIconButton({
  action,
  className = "",
  compact = false,
  icon: Icon,
  label,
  type = "button",
  ...buttonProps
}: ActionIconButtonProps) {
  return (
    <button
      {...buttonProps}
      aria-label={label}
      className={`inline-flex shrink-0 items-center justify-center text-slate-400 transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary disabled:cursor-wait disabled:opacity-50 ${
        compact ? "rounded-md p-1.5" : "size-11 rounded-lg"
      } ${actionClasses[action]} ${className}`}
      title={label}
      type={type}
    >
      <Icon aria-hidden="true" size={compact ? 16 : 18} strokeWidth={1.5} />
    </button>
  );
}
