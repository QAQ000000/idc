import { adminApi } from "./admin";

export interface CategoryItem {
  id: number;
  parent_id: number | null;
  name: string;
  slug: string;
  type: string;
  headline: string;
  description: string;
  seo_title: string;
  seo_keywords: string;
  seo_description: string;
  order_num: number;
  status: number;
  products_count: number;
  subcategories_count: number;
  version: string;
  created_at: string;
  updated_at: string;
  children?: CategoryItem[];
}

export interface AdminCategoriesResponse {
  items: CategoryItem[];
  tree: CategoryItem[];
}

export interface CategoryPayload {
  parent_id?: number | null;
  name: string;
  slug: string;
  type?: string;
  headline?: string;
  description?: string;
  seo_title?: string;
  seo_keywords?: string;
  seo_description?: string;
  order_num?: number;
  status?: number;
  expected_version?: string;
}

export async function fetchAdminCategories(): Promise<AdminCategoriesResponse> {
  return await adminApi<AdminCategoriesResponse>("/categories");
}

export async function createCategory(payload: CategoryPayload): Promise<CategoryItem> {
  return await adminApi<CategoryItem>("/categories", {
    method: "POST",
    body: payload,
  });
}

export async function updateCategory(id: number, payload: CategoryPayload): Promise<CategoryItem> {
  return await adminApi<CategoryItem>(`/categories/${id}`, {
    method: "PATCH",
    body: payload,
  });
}

export async function deleteCategory(id: number, expected_version: string): Promise<{ success: boolean }> {
  return await adminApi<{ success: boolean }>(`/categories/${id}`, {
    method: "DELETE",
    body: { expected_version },
  });
}
