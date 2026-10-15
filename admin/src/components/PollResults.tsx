type PollResultsProps = {
  results: {
    ano: number;
    nie: number;
    zdrzal: number;
  };
};

const choices = [
  { key: "ano", label: "Áno", color: "bg-emerald-500" },
  { key: "nie", label: "Nie", color: "bg-red-500" },
  { key: "zdrzal", label: "Zdržal sa", color: "bg-slate-400" },
] as const;

function voteLabel(count: number) {
  return count === 1 ? "hlas" : count >= 2 && count <= 4 ? "hlasy" : "hlasov";
}

export function PollResults({ results }: PollResultsProps) {
  const totalVotes = results.ano + results.nie + results.zdrzal;

  return (
    <section aria-label="Výsledky ankety">
      <div className="mb-4 flex items-center justify-between gap-3">
        <h3 className="font-semibold text-primary">Výsledky ankety</h3>
        <p className="text-sm text-slate-600">
          Spolu: <span className="font-semibold text-primary">{totalVotes}</span> {voteLabel(totalVotes)}
        </p>
      </div>
      <div className="space-y-4">
        {choices.map((choice) => {
          const count = results[choice.key];
          const percentage = totalVotes > 0 ? Math.round((count / totalVotes) * 100) : 0;

          return (
            <div key={choice.key}>
              <div className="mb-1.5 flex items-baseline justify-between gap-3 text-sm">
                <span className="font-semibold text-primary">{choice.label}</span>
                <span className="shrink-0 text-slate-600">
                  {count} {voteLabel(count)} · {percentage} %
                </span>
              </div>
              <div
                className="h-3 overflow-hidden rounded-full bg-slate-100"
                role="progressbar"
                aria-label={`${choice.label}: ${percentage} percent`}
                aria-valuemin={0}
                aria-valuemax={100}
                aria-valuenow={percentage}
              >
                <div
                  className={`h-full rounded-full transition-[width] duration-500 ${choice.color}`}
                  style={{ width: `${percentage}%` }}
                />
              </div>
            </div>
          );
        })}
      </div>
    </section>
  );
}
