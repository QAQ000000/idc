import { api } from "./request";

export interface PublicSubCategory {
  id: number;
  parent_id: number;
  name: string;
  slug: string;
  type: string;
  headline: string;
  description: string;
  order_num: number;
}

export interface PublicCategoryGroup {
  id: number;
  name: string;
  slug: string;
  type: string;
  headline: string;
  description: string;
  order_num: number;
  children: PublicSubCategory[];
}

export async function fetchPublicCategories(): Promise<PublicCategoryGroup[]> {
  return await api<PublicCategoryGroup[]>("/categories");
}
