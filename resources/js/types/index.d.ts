export interface User {
    id: number;
    name: string;
    email: string;
    email_verified_at?: string;
    avatar_emoji?: string;
    color?: string;
}

export interface Member {
    id: number;
    name: string;
    avatar_emoji: string;
    color: string;
}

export interface ExpenseCategory {
    id: number;
    name: string;
    color?: string | null;
}

export interface Expense {
    id: number;
    amount: string;
    description: string;
    date: string;
    expense_category_id: number | null;
    category?: ExpenseCategory | null;
    creator?: Member | null;
}

export interface DebtPayment {
    id: number;
    amount: string;
    date: string;
    note?: string | null;
    payer?: Member | null;
}

export interface Debt {
    id: number;
    name: string;
    lender?: string | null;
    total_amount: string;
    monthly_payment?: string | null;
    due_day?: number | null;
    emoji: string;
    color: string;
    paid_amount: number;
    remaining_amount: number;
    progress: number;
    payments?: DebtPayment[];
}

export interface SavingsContribution {
    id: number;
    amount: string;
    date: string;
    note?: string | null;
    contributor?: Member | null;
}

export interface SavingsGoal {
    id: number;
    name: string;
    target_amount: string;
    target_date?: string | null;
    emoji: string;
    color: string;
    current_amount: number;
    remaining_amount: number;
    progress: number;
    contributions?: SavingsContribution[];
}

export interface Ingredient {
    id: number;
    name: string;
    category?: string | null;
    stock: string;
    unit: string;
    min_stock: string;
    pivot?: { quantity: string; unit: string };
}

export interface Recipe {
    id: number;
    title: string;
    category?: string[] | null;
    instructions?: string | null;
    prep_time_minutes: number;
    ingredients?: Ingredient[];
    is_available?: boolean;
}

export interface WeeklyPlan {
    id: number;
    date: string;
    meal_type: 'breakfast' | 'lunch' | 'dinner';
    recipe_id: number | null;
    recipe?: Recipe | null;
    is_deducted: boolean;
}

export interface Routine {
    id: number;
    title: string;
    frequency: 'daily' | 'weekly' | 'monthly';
    done_today?: boolean;
    last_completed?: string | null;
    last_by?: Member | null;
}

export interface Rates {
    bcv_usd: number | null;
    parallel_usd: number | null;
    bcv_eur: number | null;
    fetched_at?: string | null;
    rate_date?: string | null;
}

export interface TripRates {
    bcv_usd: number | null;
    parallel_usd: number | null;
    bcv_eur: number | null;
}

export interface ShoppingItem {
    id: number;
    name: string;
    brand?: string | null;
    size?: string | null;
    label: string;
    /** null = anotado sin precio todavía */
    unit_price_usd: number | null;
    quantity: number;
    subtotal_usd: number;
}

export interface ShoppingTrip {
    id: number;
    name: string;
    store?: string | null;
    status: 'active' | 'done';
    created_at: string;
    total_usd: number;
    item_count: number;
    pending_price_count: number;
    rates: TripRates;
    has_expense: boolean;
    items: ShoppingItem[];
}

export type PageProps<
    T extends Record<string, unknown> = Record<string, unknown>,
> = T & {
    auth: {
        user: User;
    };
    flash: {
        success?: string | null;
        error?: string | null;
        celebrate?: string | null;
    };
    rates: Rates | null;
    notifications: {
        vapidPublicKey: string | null;
        subscribed: boolean;
    };
};
