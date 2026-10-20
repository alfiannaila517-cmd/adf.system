<?php

/**
 * Logika khusus hotel ini — hanya dimuat saat bisnis ini aktif.
 * Contoh: DP 30%, dan wajib lunas bila check-in kurang dari 3 hari lagi.
 *
 *   Hooks::on('booking.dp_amount', function (float $dp, array $booking): float {
 *       $daysLeft = (strtotime($booking['check_in_date']) - time()) / 86400;
 *       return $daysLeft < 3 ? (float)$booking['final_price'] : $booking['final_price'] * 0.3;
 *   });
 */
