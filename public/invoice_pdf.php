<?php
/**
 * Fatura PDF'i — e-postadaki imzalı bağlantıyla (giriş gerektirmez):
 *   /invoice_pdf.php?invoice=ID&t=TOKEN   (TOKEN: lib/links.php invoice_link_token)
 */

declare(strict_types=1);
require __DIR__ . '/../lib/store.php';
require __DIR__ . '/../lib/links.php';

$id = (int) ($_GET['invoice'] ?? 0);
if (!invoice_link_valid($id, (string) ($_GET['t'] ?? '')) || !($pdf = invoice_receipt_pdf($id))) {
    http_response_code(404);
    exit('Fatura bulunamadı.');
}
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $pdf['name'] . '"');
header('Cache-Control: private, no-store');
echo $pdf['content'];
