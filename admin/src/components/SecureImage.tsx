import { useEffect, useState } from "react";
import { apiClient } from "../api/client";

type ImageState =
  | { fileName: string; status: "loading" }
  | { fileName: string; status: "loaded"; objectUrl: string }
  | { fileName: string; status: "error" };

type SecureImageProps = {
  src: string;
  alt: string;
  className?: string;
};

export function SecureImage({
  src,
  alt,
  className = "",
}: SecureImageProps) {
  const [image, setImage] = useState<ImageState>({
    fileName: src,
    status: "loading",
  });

  useEffect(() => {
    const controller = new AbortController();
    let objectUrl: string | undefined;
    let isActive = true;

    setImage({ fileName: src, status: "loading" });

    void apiClient
      .get<Blob>("smartsused/v1/files/download", {
        params: { file: src },
        responseType: "blob",
        signal: controller.signal,
      })
      .then(({ data }) => {
        if (!(data instanceof Blob) || !data.type.startsWith("image/")) {
          throw new Error("Server nevrátil platný obrázok.");
        }

        if (!isActive) {
          return;
        }

        objectUrl = URL.createObjectURL(data);
        setImage({ fileName: src, status: "loaded", objectUrl });
      })
      .catch(() => {
        if (isActive) {
          setImage({ fileName: src, status: "error" });
        }
      });

    return () => {
      isActive = false;
      controller.abort();
      if (objectUrl) {
        URL.revokeObjectURL(objectUrl);
      }
    };
  }, [src]);

  const isCurrentImage = image.fileName === src;
  const imageClassName = `block ${className}`.trim();

  if (!isCurrentImage || image.status === "loading") {
    return (
      <div
        aria-label="Načítava sa obrázok"
        aria-busy="true"
        className={`animate-pulse rounded-md bg-slate-200 ${imageClassName}`}
      />
    );
  }

  if (image.status === "error") {
    return (
      <div
        role="status"
        className={`flex items-center justify-center rounded-md bg-slate-100 p-4 text-sm text-slate-600 ${imageClassName}`}
      >
        Obrázok sa nepodarilo načítať
      </div>
    );
  }

  return (
    <img
      src={image.objectUrl}
      alt={alt}
      className={imageClassName}
      onError={() => {
        URL.revokeObjectURL(image.objectUrl);
        setImage({ fileName: src, status: "error" });
      }}
    />
  );
}
