# Task Progress

- [x] Find files that create journal entries (AccountingService.php, InvoiceController.php)
- [x] Read AccountingService.php - the core entry creation logic
- [x] Read InvoiceController.php - invoice posting flow
- [x] Read SettlementEngine.php - EMPTY FILE (0 bytes)
- [x] Read Payment model - NO entity fields
- [x] Read AddPaymentRequest - NO entity fields in validation
- [x] Read payments migration - NO entity columns
- [x] Trace the full chain: POS → Invoice → Settlement → Payment → Posting → Entry
- [x] Identify the exact lines that cause the bug
- [x] Fix #1: Add entity fields to payments table (migration) ✅
- [x] Fix #2: Update Payment model with entity fields ✅
- [x] Fix #3: Update AddPaymentRequest validation ✅
- [x] Fix #4: Update InvoiceController::addPayment() to pass entity data ✅
- [x] Fix #5: Update AccountingService::createJournalEntryForInvoice() to handle entity payments ✅
- [ ] Verify with before/after code and example entries
