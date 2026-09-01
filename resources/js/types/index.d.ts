export interface User {
    id: number;
    name: string;
    email: string;
    email_verified_at?: string;
    avatar_emoji?: string;
    avatar_url?: string | null;
    /** Color de acento del miembro; ademas define el color de la app. */
    color?: string;
    /** Apariencia elegida: 'light', 'dark' o 'system'. */
    theme?: 'light' | 'dark' | 'system';
    created_at?: string;
}

/** Pagina de resultados servida por un paginador de Laravel. */
export interface Paginated<T> {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
}

export interface Member {
    id: number;
    name: string;
    avatar_emoji: string;
    avatar_url?: string | null;
    color: string;
}

export interface ExpenseCategory {
    id: number;
    name: string;
    color?: string | null;
    /** Cuantos gastos la usan; solo llega en la pantalla de Gastos. */
    expenses_count?: number;
}

export interface Expense {
    id: number;
    amount: string;
    description: string;
    date: string;
    expense_category_id: number | null;
    category?: ExpenseCategory | null;
    creator?: Member | null;
    /** Foto del comprobante, o null si no se adjunto ninguna. */
    receipt_url?: string | null;
}

export interface DebtPayment {
    id: number;
    amount: string;
    date: string;
    note?: string | null;
    payer?: Member | null;
    receipt_url?: string | null;
}

export interface Debt {
    id: number;
    payments_count?: number;
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
    receipt_url?: string | null;
}

/**
 * Un movimiento de dinero visto desde la pantalla de detalle: un abono a una
 * deuda y un aporte a una meta se pintan igual.
 */
export interface MoneyEntry {
    id: number;
    amount: number;
    date: string;
    note?: string | null;
    receipt_url?: string | null;
    member?: Member | null;
}

/** Deuda o meta, con lo comun a las dos para la vista de detalle. */
export interface MoneyAccount {
    kind: 'debt' | 'goal';
    id: number;
    name: string;
    emoji: string;
    color: string;
    /** Monto total de la deuda / meta de ahorro. */
    target: number;
    /** Lo ya abonado o ya ahorrado. */
    moved: number;
    remaining: number;
    progress: number;
    lender?: string | null;
    monthly_payment?: number | null;
    due_day?: number | null;
    target_date?: string | null;
}

export interface MoneyTotals {
    count: number;
    sum: number;
    average: number;
    this_month: number;
    first_date: string | null;
}

export interface SavingsGoal {
    id: number;
    contributions_count?: number;
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
    /** Dias ISO (1 = lunes ... 7 = domingo). Solo para frequency 'weekly'. */
    days: number[];
    schedule_label: string;
    /** Si toca hoy segun sus dias. */
    due_today: boolean;
    done_today: boolean;
    /** Veces que se completo en el mes en curso. */
    done_this_month: number;
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
    /** Foto de la factura, si la compra nacio de un escaneo. */
    receipt_url?: string | null;
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
    /** Funciones que dependen de configuracion del servidor. */
    features: {
        /** Hay clave de Anthropic: se puede leer una factura por foto. */
        invoiceScan: boolean;
    };
};
