# WooCommerce Product Importer

This directory contains the core `WooCommerce_Product_Importer` class responsible for creating and updating WooCommerce products based on standardized data provided by a Platform Mapper.

## Expected Data Structure (`$wc_data`)

The `WooCommerce_Product_Importer::import_product` method expects a single argument: a PHP associative array (`$wc_data`) containing standardized product information. Platform Mappers (implementing `Platform_Mapper_Interface`) must transform their platform-specific data into this structure.

Below is a breakdown of the expected keys and their formats within the `$wc_data` array:

**Required Keys:**

*   `original_product_id` (string|int): The unique identifier of the product on the original platform. Used for finding existing products via the `_original_product_id` meta field.
*   `name` (string): The product title.
*   `is_variable` (bool): `true` if the product has variations, `false` otherwise.

**Core Product Details (Optional/Recommended):**

*   `slug` (string): The desired product slug.
*   `description` (string): The main product description (HTML is allowed).
*   `status` (string): The desired WooCommerce product status (e.g., `publish`, `draft`, `pending`). Defaults to `draft` if not provided.
*   `date_created_gmt` (string): The product creation date in UTC/GMT timezone (e.g., `YYYY-MM-DD HH:MM:SS`).
*   `catalog_visibility` (string): WooCommerce catalog visibility setting (e.g., `visible`, `catalog`, `search`, `hidden`). Defaults to `visible`.
*   `original_url` (string): The URL of the product on the original platform (stored in `_original_url` meta).

**Taxonomies (Optional):**

*   `categories` (array): An array of category arrays.
    *   Each category array: `['name' => (string)Name, 'slug' => (string)Slug]`
*   `tags` (array): An array of tag arrays.
    *   Each tag array: `['name' => (string)Name, 'slug' => (string)Slug]`
*   `brand` (array): An array representing the brand (assumes `product_brand` taxonomy).
    *   Brand array: `['name' => (string)Name, 'slug' => (string)Slug]`

**Images (Optional):**

*   `images` (array): An array of image arrays.
    *   Each image array: `['original_id' => (string|int)ID, 'url' => (string)URL, 'alt' => (string)AltText, 'is_featured' => (bool)Featured]`
        *   `original_id`: Used for mapping and preventing re-uploads.
        *   `url`: Publicly accessible URL for sideloading.
        *   `is_featured`: `true` marks the image as the main product image.

**Simple Product Specific Fields (Used if `is_variable` is `false`):**

*   `regular_price` (string|float|null): The regular price.
*   `sale_price` (string|float|null): The sale price (or `null`/empty string if not on sale).
*   `sku` (string|null): Product SKU.
*   `manage_stock` (bool): `true` to enable stock management at the product level.
*   `stock_status` (string): `instock` or `outofstock`.
*   `stock_quantity` (int|null): Stock quantity if `manage_stock` is true.
*   `weight` (string|float|null): Product weight (assumed to be in the store's configured unit).
*   `original_variant_id` (string|int|null): Original ID of the single variant (stored in `_original_variant_id` meta).

**Variable Product Specific Fields (Used if `is_variable` is `true`):**

*   `attributes` (array): An array of product attribute arrays.
    *   Each attribute array: `['name' => (string)Name, 'options' => (array)Values, 'position' => (int)Pos, 'is_visible' => (bool)Visible, 'is_variation' => (bool)ForVariations]`
        *   `options`: Array of string values for the attribute (e.g., `['Red', 'Blue']`).
*   `variations` (array): An array of variation arrays.
    *   Each variation array:
        *   `original_id` (string|int): The unique ID of the variation on the source platform.
        *   `regular_price` (string|float|null)
        *   `sale_price` (string|float|null)
        *   `sku` (string|null)
        *   `manage_stock` (bool)
        *   `stock_status` (string): `instock` or `outofstock`.
        *   `stock_quantity` (int|null)
        *   `weight` (string|float|null)
        *   `image_original_id` (string|int|null): The `original_id` (from the main `images` array) to assign to this variation.
        *   `menu_order` (int): Position/order of the variation.
        *   `attributes` (array): Associative array mapping attribute *name* (matching the name in the parent `attributes` array) to the specific attribute *value* for this variation (e.g., `['Color' => 'Red', 'Size' => 'Large']`).

**Metafields (Optional):**

*   `metafields` (array): An associative array of generic key-value pairs to be potentially handled.
    *   Example (Yoast): `['global_title_tag' => '...', 'global_description_tag' => '...']` (The importer specifically looks for these Yoast keys if Yoast SEO is active). Other keys might be ignored or require custom handling within the importer.

By adhering to this standardized structure, different platform Mappers can provide data consistently to the core `WooCommerce_Product_Importer`.
