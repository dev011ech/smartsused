import { useCallback, useEffect, useState } from "react";
import axios from "axios";
import { apiClient } from "../api/client";
import { PollWidget, type PollWidgetData } from "./PollWidget";
import { ManagerPollsPanel } from "./ManagerPollsPanel";
import { useAuth } from "../auth/AuthContext";

type ActivePoll = {
  id: number;
  question: string;
  description: string;
  hasVoted: boolean;
};

type ActivePollsResponse = {
  polls: ActivePoll[];
};

type PollResultsResponse = {
  hasVoted: boolean;
  results: {
    ano: { count: number };
    nie: { count: number };
    zdrzal_sa: { count: number };
  };
};

function getErrorMessage(error: unknown) {
  if (axios.isAxiosError<{ message?: string }>(error)) {
    return error.response?.data?.message ?? "Ankety sa nepodarilo načítať.";
  }

  return error instanceof Error ? error.message : "Ankety sa nepodarilo načítať.";
}

export function PollsPage() {
  const { user } = useAuth();

  return user?.roles.includes("smartsused_manager")
    ? <ManagerPollsPanel />
    : <ResidentPollsPage />;
}

function ResidentPollsPage() {
  const [polls, setPolls] = useState<PollWidgetData[]>([]);
  const [isLoading, setIsLoading] = useState(true);
  const [error, setError] = useState("");

  const loadPolls = useCallback(async () => {
    setIsLoading(true);
    setError("");

    try {
      const { data } = await apiClient.get<ActivePollsResponse>("smartsused/v1/polls/active");
      const activePolls = Array.isArray(data?.polls) ? data.polls : [];
      const widgets = await Promise.all(
        activePolls.map(async (poll) => {
          const resultResponse = await apiClient.get<PollResultsResponse>(
            "smartsused/v1/polls/results",
            { params: { id: poll.id } },
          );
          const results = resultResponse.data.results;

          return {
            ...poll,
            hasVoted: poll.hasVoted || resultResponse.data.hasVoted,
            results: {
              ano: results.ano.count,
              nie: results.nie.count,
              zdrzal: results.zdrzal_sa.count,
            },
          };
        }),
      );
      setPolls(widgets);
    } catch (requestError) {
      setError(getErrorMessage(requestError));
    } finally {
      setIsLoading(false);
    }
  }, []);

  useEffect(() => {
    void loadPolls();
  }, [loadPolls]);

  return (
    <section className="space-y-5" aria-labelledby="polls-heading">
      <header>
        <p className="text-sm font-semibold uppercase tracking-wide text-emerald-700">SmartSused</p>
        <h1 id="polls-heading" className="mt-1 text-2xl font-bold text-slate-950">Ankety</h1>
        <p className="mt-2 text-sm text-slate-600">Aktívne ankety vášho bytového domu.</p>
      </header>

      {error && (
        <div role="alert" className="rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-800">
          <p>{error}</p>
          <button type="button" onClick={() => void loadPolls()} className="mt-2 font-semibold underline">
            Skúsiť znova
          </button>
        </div>
      )}

      {isLoading ? (
        <p role="status" className="rounded-xl border border-slate-200 bg-white p-6 text-sm text-slate-600">
          Načítavam ankety…
        </p>
      ) : !error && polls.length === 0 ? (
        <p className="rounded-xl border border-slate-200 bg-white p-6 text-sm text-slate-600">
          Momentálne nie sú dostupné žiadne aktívne ankety.
        </p>
      ) : (
        <div className="space-y-4">
          {polls.map((poll) => <PollWidget key={poll.id} {...poll} />)}
        </div>
      )}
    </section>
  );
}
