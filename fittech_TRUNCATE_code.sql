

USE fittech;

SET FOREIGN_KEY_CHECKS = 0;

-- ── CLEAR MEMBER-RELATED & ANALYTICS DATA ────────────────────
DELETE FROM order_items;
DELETE FROM orders;
DELETE FROM bookings;
DELETE FROM attendance;
DELETE FROM workouts;
DELETE FROM renewal_requests;
DELETE FROM password_reset_tokens;
DELETE FROM members;

-- ── RESET AUTO-INCREMENT FOR CLEARED TABLES ──────────────────
ALTER TABLE order_items           AUTO_INCREMENT = 1;
ALTER TABLE orders                AUTO_INCREMENT = 1;
ALTER TABLE bookings              AUTO_INCREMENT = 1;
ALTER TABLE attendance            AUTO_INCREMENT = 1;
ALTER TABLE workouts              AUTO_INCREMENT = 1;
ALTER TABLE renewal_requests      AUTO_INCREMENT = 1;
ALTER TABLE password_reset_tokens AUTO_INCREMENT = 1;
ALTER TABLE members               AUTO_INCREMENT = 1;



SET FOREIGN_KEY_CHECKS = 1;

