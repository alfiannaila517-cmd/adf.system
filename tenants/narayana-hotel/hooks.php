<?php

/**
 * Logika khusus Narayana Hotel — hanya dimuat saat bisnis ini aktif (Tenant::activate('narayana-hotel')).
 * Belum ada aturan khusus; DP 50% dan aturan lain memakai bawaan kode inti.
 *
 * Contoh bila suatu saat aturannya berbeda:
 *   Hooks::on('booking.dp_amount', function (float $dp, array $booking): float {
 *       return $booking['final_price'] * 0.5;
 *   });
 */
