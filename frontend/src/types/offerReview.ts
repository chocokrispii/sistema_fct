export type OfferReviewDecision = 'Aprobada' | 'Rechazada';

export interface OfferReviewPayload {
    oferta_id: number;
    decision: OfferReviewDecision;
    motivo: string;
}

export interface OfferReviewResult {
    message: string;
    decision: OfferReviewDecision;
}
