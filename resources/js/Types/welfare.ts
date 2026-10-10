export interface Coupon {
    coupon_id: string;
    reward_name: string;
    store_name: string;
    discount_satang: number;
    points_used: number;
    status: string;
    requested_at: string;
    expires_at: string;
    received_at: string | null;
    holder_name?: string;
    receiver_name?: string;
    claim_id?: string | null;
}
export interface WelfareStore {
    id: number;
    name: string;
    status: string;
    code?: string;
    contact?: string | null;
    operators?: { id: number; display_name: string }[];
}
export interface Offer {
    id: number;
    name: string;
    description: string | null;
    required_points: number;
    discount_satang: number;
    inventory: number;
    valid_days: number;
    store_name?: string;
    welfare_store_id?: number;
    store?: { id: number; name: string };
    is_active?: boolean;
    start_date?: string | null;
    end_date?: string | null;
}
export interface ClaimItem {
    id: number;
    coupon_id: string;
    reward_name: string;
    holder_name: string;
    discount_satang: number;
    received_at: string;
}
export interface Claim {
    claim_id: string;
    organization_name: string;
    store_name: string;
    start_date: string;
    end_date: string;
    coupon_count: number;
    total_satang: number;
    note: string | null;
    created_at: string;
    items?: ClaimItem[];
}
export const couponStatus: Record<string, string> = {
    APPROVED: 'พร้อมใช้',
    RECEIVED: 'ใช้แล้ว',
    EXPIRED: 'หมดอายุ',
    PENDING: 'รออนุมัติ',
    SUSPENDED: 'ระงับร้าน',
};
export const money = (satang: number) =>
    (satang / 100).toLocaleString('th-TH', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
