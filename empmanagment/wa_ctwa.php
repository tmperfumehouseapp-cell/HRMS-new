<?php
/**
 * Click-to-WhatsApp ad tracking: hands a COPY of an incoming WhatsApp webhook to the separate ctwa-roas service
 * (see /ctwa-roas), which stores the ad click (ctwa_clid) and later tells Meta about the purchase.
 *
 * - Only messages that carry an ad "referral" are copied; everything else stays in the HRMS only.
 * - The copy is sent with Meta's own signature header, so the service checks it with the same WA_APP_SECRET.
 * - Called AFTER the HRMS has stored the messages. A slow or failed copy never changes what the HRMS does.
 * - config.php (optional): define('CTWA_FORWARD_URL', 'off') switches it off; any https URL sends the copy there instead.
 */
const CTWA_FORWARD_DEFAULT = 'https://ctwa.fourpirates.space/webhooks/whatsapp';

function wa_ctwa_url(): string
{
    $u = defined('CTWA_FORWARD_URL') ? trim((string)constant('CTWA_FORWARD_URL')) : CTWA_FORWARD_DEFAULT;
    return ($u === '' || strtolower($u) === 'off') ? '' : $u;
}

/** Returns '' when nothing was to be sent, 'ok' when the service took it, else a short error text. */
function wa_ctwa_forward(string $raw, string $signature, ?string $url = null): string
{
    $url = $url ?? wa_ctwa_url();
    if ($url === '' || $signature === '' || strpos($raw, '"referral"') === false || !function_exists('curl_init')) return '';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_POSTFIELDS => $raw, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-Hub-Signature-256: ' . $signature],
        CURLOPT_CONNECTTIMEOUT => 2, CURLOPT_TIMEOUT => 4,
    ]);
    curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
    return ($code >= 200 && $code < 300) ? 'ok' : ('HTTP ' . $code . ($err !== '' ? ' ' . $err : ''));
}
