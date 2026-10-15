/**
 * Slovak formatting helpers shared by SmartSused admin UI components.
 */

export type MeterUnit = "m³" | "kWh";

const slovakNumber = (minimumFractionDigits: number, maximumFractionDigits: number) =>
  new Intl.NumberFormat("sk-SK", {
    useGrouping: true,
    minimumFractionDigits,
    maximumFractionDigits,
  });

const toFiniteNumber = (value: number | string): number => {
  if (typeof value !== "number" && typeof value !== "string") {
    throw new TypeError("Hodnota musí byť číslo.");
  }

  if (typeof value === "string" && value.trim() === "") {
    throw new TypeError("Hodnota musí byť číslo.");
  }

  const number = Number(value);
  if (!Number.isFinite(number)) {
    throw new TypeError("Hodnota musí byť konečné číslo.");
  }

  return number;
};

/**
 * Format an amount as euros with Slovak decimal and grouping separators.
 */
export const formatCurrency = (value: number | string): string =>
  `${slovakNumber(2, 2).format(toFiniteNumber(value))}\u00a0€`;

/**
 * Format a MySQL date or datetime (YYYY-MM-DD[ HH:mm:ss]) as a Slovak date.
 *
 * The input is treated as a wall-clock value, so the browser timezone cannot
 * shift its date or time.
 */
export const formatDate = (value: string, withTime = false): string => {
  const match = /^(\d{4})-(\d{2})-(\d{2})(?: (\d{2}):(\d{2}):(\d{2}))?$/.exec(value);
  if (!match) {
    throw new TypeError("Dátum musí mať formát YYYY-MM-DD alebo YYYY-MM-DD HH:mm:ss.");
  }

  const [, yearText, monthText, dayText, hourText = "00", minuteText = "00", secondText = "00"] =
    match;
  const year = Number(yearText);
  const month = Number(monthText);
  const day = Number(dayText);
  const hour = Number(hourText);
  const minute = Number(minuteText);
  const second = Number(secondText);
  const date = new Date(0);
  date.setUTCFullYear(year, month - 1, day);
  date.setUTCHours(hour, minute, second, 0);

  if (
    date.getUTCFullYear() !== year ||
    date.getUTCMonth() !== month - 1 ||
    date.getUTCDate() !== day ||
    date.getUTCHours() !== hour ||
    date.getUTCMinutes() !== minute ||
    date.getUTCSeconds() !== second
  ) {
    throw new TypeError("Dátum je neplatný.");
  }

  const formattedDate = `${day}. ${month}. ${year}`;
  if (!withTime || hourText === "00" && minuteText === "00" && secondText === "00" && !value.includes(" ")) {
    return formattedDate;
  }

  return `${formattedDate} o ${hourText}:${minuteText}`;
};

/**
 * Format a water or heat meter reading to three decimal places.
 */
export const formatMeter = (value: number | string, unit: MeterUnit): string => {
  if (unit !== "m³" && unit !== "kWh") {
    throw new TypeError("Jednotka musí byť m³ alebo kWh.");
  }

  return `${slovakNumber(3, 3).format(toFiniteNumber(value))}\u00a0${unit}`;
};
