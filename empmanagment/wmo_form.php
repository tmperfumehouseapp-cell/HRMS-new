<?php
/**
 * wmo_form.php — the customer's address page of a manual order (PUBLIC: no login). The link is sent in the chat: .../empmanagment/wmo_form.php?t=TOKEN
 * It only hands over to api.php (action wmo.form, see wmo.php). Put this file in the SAME folder as api.php.
 */
$_GET['action'] = 'wmo.form';
require __DIR__ . '/api.php';
