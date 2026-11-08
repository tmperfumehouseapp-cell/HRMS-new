<?php
/**
 * b.php — the SHORT address of a customer's bill page, for the WhatsApp message:   https://your-domain/b.php?t=TOKEN
 *   &c=1  join the community (tapped link is counted)     &r=1  Google review (counted)     &s=1  save all our numbers (contacts file)     &g=1  friends
 * It only renames the short letters and hands over to empmanagment/store_bill.php (the long address keeps working for older messages).
 */
if (isset($_GET['c'])) $_GET['go'] = 'community';
elseif (isset($_GET['r'])) $_GET['go'] = 'review';
if (isset($_GET['s'])) $_GET['vcf'] = '1';
require __DIR__ . '/empmanagment/store_bill.php';
