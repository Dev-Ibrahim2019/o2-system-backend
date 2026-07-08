// src/services/tableService.ts — Unified Tables API for POS, Hospitality, Accountant, Manager
import api from "../api/axios";

export interface TableOrderSummary {
  id: string;
  order_number: string;
  status: string;
  total: number;
  customer_name: string | null;
}

export interface TableFromApi {
  id: string;
  dining_zone_id?: string;
  table_number: string;
  number: number;
  capacity: number;
  status: string;
  seated_at: string | null;
  customer_count: number;
  current_order_id: string | null;
  current_order: TableOrderSummary | null;
}

export interface DiningZoneFromApi {
  id: string;
  name: string;
  code: string;
  tables: TableFromApi[];
}

export interface SeatTablePayload {
  customer_count?: number;
  customer_name?: string;
  customer_phone?: string;
}

export interface SeatTableResponse {
  success: boolean;
  message: string;
  data: {
    table: TableFromApi;
    order: any;
  };
}

export interface TransferPayload {
  from_table_id: number;
  to_table_id: number;
}

export interface MergePayload {
  table_ids: number[];
  target_table_id: number;
}

const unwrapData = (response: any): any => {
  if (response?.data?.data) return response.data.data;
  if (response?.data) return response.data;
  return response;
};

export const tableService = {
  /**
   * جلب القاعات والطاولات حسب الفرع
   * GET /api/tables?branch_id=1
   */
  async getTables(branchId?: number): Promise<DiningZoneFromApi[]> {
    const params: Record<string, any> = {};
    if (branchId) params.branch_id = branchId;

    const response = await api.get("/tables", { params });
    const data = unwrapData(response);
    return Array.isArray(data) ? data : [];
  },

  /**
   * جلب طاولة واحدة
   * GET /api/tables/{id}
   */
  async getTable(id: number): Promise<TableFromApi> {
    const response = await api.get(`/tables/${id}`);
    return unwrapData(response);
  },

  /**
   * تسكين طاولة (إنشاء طلب جديد وربطه)
   * POST /api/tables/{id}/seat
   */
  async seatTable(id: number, payload: SeatTablePayload = {}): Promise<SeatTableResponse> {
    const response = await api.post(`/tables/${id}/seat`, payload);
    return response.data;
  },

  /**
   * تغيير حالة الطاولة
   * PUT /api/tables/{id}/status
   */
  async updateStatus(id: number, status: string): Promise<TableFromApi> {
    const response = await api.put(`/tables/${id}/status`, { status });
    return unwrapData(response);
  },

  /**
   * تحرير الطاولة (بعد الدفع)
   * POST /api/tables/{id}/free
   */
  async freeTable(id: number): Promise<TableFromApi> {
    const response = await api.post(`/tables/${id}/free`);
    return unwrapData(response);
  },

  /**
   * تحويل طاولة
   * POST /api/tables/transfer
   */
  async transfer(payload: TransferPayload): Promise<void> {
    await api.post("/tables/transfer", payload);
  },

  /**
   * دمج طاولات
   * POST /api/tables/merge
   */
  async merge(payload: MergePayload): Promise<void> {
    await api.post("/tables/merge", payload);
  },
};

export default tableService;
