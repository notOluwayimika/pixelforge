export type GenerationStatus =
    | 'submitting'
    | 'queued'
    | 'processing'
    | 'succeeded'
    | 'failed';

/** Money arrives pre-formatted from the server ("$0.046125"): the client never does money maths. */
export type Generation = {
    id: string;
    status: GenerationStatus;
    prompt: string;
    size: string;
    quality: string;
    reference_images: string[];
    estimated_typical_charge: string;
    estimated_max_charge: string;
    charge: string | null;
    usage: Record<string, number> | null;
    submit_attempts: number;
    error_code: string | null;
    error_message: string | null;
    image_url: string | null;
    created_at: string;
    completed_at: string | null;
};

export type Estimate = {
    /** nanodollars, for comparing with the balance */
    max_charge: number;
    typical_charge_formatted: string;
    max_charge_formatted: string;
    typical_tokens: Record<string, number>;
    max_tokens: Record<string, number>;
};
