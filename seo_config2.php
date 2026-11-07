<?php
/* TM SEO — settings. Edit only this file.
   domains = the public address of each store, exactly as customers open it (no slash at the end).
   Only list stores that really have their own pages: tmfragrance.com sends everyone to tmperfumehouse.com, so it is left out. */
return [
  'domains' => [
    'tmperfumehouse' => 'https://tmperfumehouse.com',
  ],
  'batch_pages'   => 12,    // pages checked in one go
  'batch_links'   => 30,    // links checked in one go
  'time_budget'   => 22,    // seconds one go may take (keep under your server's limit)
  'timeout'       => 15,    // seconds to wait for one page
  'ua'            => 'TMSeoCheck/1.0 (+own site audit)',
  /* addresses that are not real pages and must never be reported as broken */
  'skip_paths'    => '#^/(cart|account|checkout|checkouts|apps|cdn|services|collections/vendors|search|policies)(/|$)#i',
];
