# Migration Merge Plan - COMPLETED ✅

## Merges Completed:

1. ✅ **departments** - Merged `add_start_code` (+ start_code) and `add_code_and_fields` (+ code, nameAr, status, location) into create file. Removed ->after() calls.

2. ✅ **branches** - Merged `add_static_ip` (+ static_ip) into create file. Removed ->after().

3. ✅ **users** - Merged `add_soft_deletes` (+ softDeletes) and `add_branch_id` (+ branch_id FK) into create file. Removed ->after().

4. ✅ **dining_tables** - Merged `add_table_fields` (+ current_order_id), `add_has_order` (+ HAS_ORDER), and `add_pending_confirmation` (+ PENDING_CONFIRMATION) into create file. Used VARCHAR(30) + CHECK constraint for status.

5. ✅ **orders** - Merged `add_pricing_context` (+ customer_id, employee_id, supplier_id, engine_discount_amount) and `add_pending_confirmation` into create file. Used VARCHAR(30) + CHECK constraint for status.

6. ✅ **sales_invoices** - Merged `add_partial_status` and `add_type_column` (+ many columns) into create file. Used VARCHAR(40) for status.

7. ✅ **invoice_items** - Merged `add_discount_fields`, `add_missing_discount_columns`, `add_financial_fields`, `add_missing_columns`, and `add_tax_columns` into create file.

8. ✅ **invoices** - Merged `add_financial_fields`, `add_invoice_type_currency_tax_dates`, `add_missing_columns`, `add_approved_and_reference`, `add_pos_fields`, and `update_payment_method_enum` into create file. Used VARCHAR for status and payment_method.

9. ✅ **order_items** - Merged `add_discount_columns`, `add_missing_discount_columns`, and `add_tax_columns` into create file.

10. ✅ **payments** - Merged `add_entity_fields` into create file.

11. ✅ **sales_invoice_items** - Already had all columns from `add_missing_columns`. No changes needed.

12. ✅ **suppliers** - Merged `add_supplier_fields` (+ category, currency, mobile, city, credit_limit, payment_terms, opening_balance, is_opening_balance_posted, notes, gps_link) into create file.

## Key Rules Applied:
- ✅ Removed all ->after() calls (PostgreSQL compatibility)
- ✅ Used VARCHAR + CHECK constraints for enum-like fields (PostgreSQL compatibility)
- ✅ Foreign keys reference correct table names
- ✅ No duplicate columns
- ✅ up() and down() are balanced
- ✅ All modification files can now be safely deleted
