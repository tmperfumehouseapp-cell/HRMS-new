<?php
/**
 * Extra shop names for the SKU sheet, kept here so they work with ANY uploaded SKU list (the list is found by its ORIGINAL name, not by its code).
 * One line: [[names the SKU list may call the perfume (original / shop names)], the name the shop / HRMS writes on orders, optional SKU code, a word its name must contain]. Add a line, upload this file: no new Excel upload is needed.
 */
return [
    [['Valentino Uomo Intense', 'Uomo Supreme', 'Valentino Uomo Intense EDP'], 'VISupreme ntn Uomo', '179', 'uomo'],
    [['Valentino Uomo Intense', 'Uomo Supreme', 'Valentino Uomo Intense EDP'], 'VlSupreme ntn Uomo', '179', 'uomo'],      // the same name written with a small L (V-l-Supreme), as the shop sends it      // "VISupreme ntn Uomo - Inspired Perfume | TMPerfumehouse 50 ml / Ve*lentino"
    [['Afnan 9 PM', 'Afn 9 Night', 'Afnan 9PM'], 'Afn 9pm', '301', '9'],      // website order name: "Afn 9pm - LG Inspired Perfume | TMPerfumehouse 50 ml / Af*an"
    [['Dolce & Gabbana Intenso', 'DC Intenso'], 'Dolche Intenso', '265', 'intenso'],      // "Dolche Intenso – Inspired perfume | TMPerfumehouse 20 ml / D*G"
];
