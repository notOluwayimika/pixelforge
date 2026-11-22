/** Amounts are exact nine-decimal USD strings ("0.481105000"), the same format as /v1/usage. */
export type ReportTotals = {
    generations: number;
    failed: number;
    charged: string;
    provider_cost: string;
    profit: string;
};

export type ReportUserRow = ReportTotals & {
    user_id: number;
    name: string;
    email: string;
};

export type ReportDay = ReportTotals & {
    date: string;
    pixelforge: {
        generations: number;
        provider_cost: string;
        matches: boolean;
    } | null;
    users: ReportUserRow[];
};

export type UsageReport = {
    start: string;
    end: string;
    totals: ReportTotals;
    days: ReportDay[];
    pixelforge_error: string | null;
};
