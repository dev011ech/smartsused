import { useState } from "react";
import axios from "axios";
import { apiClient } from "../api/client";
import { PollResults } from "./PollResults";

export type PollWidgetData = {
  id: number;
  question: string;
  description: string;
  hasVoted: boolean;
  results: {
    ano: number;
    nie: number;
    zdrzal: number;
  };
};

type PollVote = "ano" | "nie" | "zdrzal_sa";
type PollChoice = "ano" | "nie" | "zdrzal";

type PollResultsResponse = {
  total_votes: number;
  results: {
    ano: { count: number };
    nie: { count: number };
    zdrzal_sa: { count: number };
  };
};

type PollWidgetProps = PollWidgetData;

const choices: Array<{
  value: PollChoice;
  label: string;
  vote: PollVote;
  color: string;
}> = [
  {
    value: "ano",
    label: "Áno",
    vote: "ano",
    color: "border-emerald-600",
  },
  {
    value: "nie",
    label: "Nie",
    vote: "nie",
    color: "border-red-600",
  },
  {
    value: "zdrzal",
    label: "Zdržiavam sa",
    vote: "zdrzal_sa",
    color: "border-slate-500",
  },
];

function getErrorMessage(error: unknown) {
  if (axios.isAxiosError<{ message?: string }>(error)) {
    return error.response?.data?.message ?? "Hlas sa nepodarilo odoslať. Skúste to znova.";
  }

  return error instanceof Error ? error.message : "Hlas sa nepodarilo odoslať. Skúste to znova.";
}

export function PollWidget({
  id,
  question,
  description,
  hasVoted,
  results,
}: PollWidgetProps) {
  const [selectedChoice, setSelectedChoice] = useState<PollChoice | null>(null);
  const [hasSubmittedVote, setHasSubmittedVote] = useState(hasVoted);
  const [currentResults, setCurrentResults] = useState(results);
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [error, setError] = useState("");

  async function submitVote() {
    if (!selectedChoice || isSubmitting || hasSubmittedVote) {
      return;
    }

    const choice = choices.find((item) => item.value === selectedChoice);

    if (!choice) {
      return;
    }

    setIsSubmitting(true);
    setError("");

    try {
      await apiClient.post("smartsused/v1/polls/vote", {
        poll_id: id,
        vote: choice.vote,
      });

      setHasSubmittedVote(true);
      setCurrentResults((previous) => ({
        ...previous,
        [selectedChoice]: previous[selectedChoice] + 1,
      }));

      try {
        const response = await apiClient.get<PollResultsResponse>(
          "smartsused/v1/polls/results",
          { params: { id } },
        );
        const responseResults = response.data?.results;

        if (
          responseResults
          && Number.isFinite(responseResults.ano?.count)
          && Number.isFinite(responseResults.nie?.count)
          && Number.isFinite(responseResults.zdrzal_sa?.count)
        ) {
          setCurrentResults({
            ano: responseResults.ano.count,
            nie: responseResults.nie.count,
            zdrzal: responseResults.zdrzal_sa.count,
          });
        } else {
          setError("Hlas bol zaznamenaný, ale výsledky sa nepodarilo načítať.");
        }
      } catch {
        setError("Hlas bol zaznamenaný, ale výsledky sa nepodarilo načítať.");
      }
    } catch (requestError) {
      setError(getErrorMessage(requestError));
    } finally {
      setIsSubmitting(false);
    }
  }

  return (
    <article className="w-full rounded-2xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
      <h2 className="text-lg font-bold leading-7 text-primary sm:text-xl">{question}</h2>
      {description && (
        <p className="mt-2 whitespace-pre-line text-sm leading-6 text-slate-600">
          {description}
        </p>
      )}

      {hasVoted || hasSubmittedVote ? (
        <section className="mt-6">
          <PollResults results={currentResults} />
          {hasSubmittedVote && (
            <p className="mt-4 text-sm font-medium text-emerald-800">
              Ďakujeme, váš hlas bol zaznamenaný.
            </p>
          )}
        </section>
      ) : (
        <section className="mt-6">
          <fieldset disabled={isSubmitting} className="space-y-3">
            <legend className="mb-3 text-sm font-semibold text-slate-700">
              Vyberte svoju odpoveď
            </legend>
            {choices.map((choice) => {
              const isSelected = selectedChoice === choice.value;

              return (
                <label
                  key={choice.value}
                  className={`flex min-h-14 cursor-pointer items-center gap-3 rounded-xl border-2 px-4 py-3 text-base font-semibold transition-colors focus-within:ring-2 focus-within:ring-accent focus-within:ring-offset-2 ${
                    isSelected
                      ? `${choice.color} bg-emerald-50 text-primary`
                      : "border-slate-200 bg-white text-slate-700 hover:border-slate-400"
                  } ${isSubmitting ? "cursor-not-allowed opacity-60" : ""}`}
                >
                  <input
                    type="radio"
                    name={`poll-${id}`}
                    value={choice.value}
                    checked={isSelected}
                    onChange={() => setSelectedChoice(choice.value)}
                    className="size-5 accent-emerald-700"
                  />
                  {choice.label}
                </label>
              );
            })}
          </fieldset>

          {error && (
            <p role="alert" className="mt-4 rounded-lg border border-red-200 bg-red-50 p-3 text-sm font-medium text-red-800">
              {error}
            </p>
          )}

          <button
            type="button"
            onClick={submitVote}
            disabled={!selectedChoice || isSubmitting}
            className="btn-primary mt-5 w-full sm:w-auto"
          >
            {isSubmitting ? (
              <>
                <svg
                  aria-hidden="true"
                  viewBox="0 0 24 24"
                  fill="none"
                  className="-ml-1 mr-2 size-5 animate-spin"
                >
                  <circle cx="12" cy="12" r="9" stroke="currentColor" strokeOpacity=".25" strokeWidth="3" />
                  <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" strokeWidth="3" strokeLinecap="round" />
                </svg>
                Odosiela sa…
              </>
            ) : (
              "Odoslať hlas"
            )}
          </button>
        </section>
      )}
    </article>
  );
}
