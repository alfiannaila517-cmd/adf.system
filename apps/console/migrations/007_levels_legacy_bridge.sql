-- Level izin jadi 5 (tambah "Ubah" = lihat+tambah+ubah tanpa hapus, flag lama 1110): Kelola pindah dari 3 ke 4.
-- Nilai di JSON selalu satu digit hasil json_encode tanpa spasi ("modul":3), jadi ganti ':3' → ':4' aman.
UPDATE roles SET permissions = REPLACE(permissions, ':3', ':4');
UPDATE memberships SET overrides = REPLACE(overrides, ':3', ':4') WHERE overrides IS NOT NULL;

-- Jembatan ke sistem lama: peran global users.role_id lama (owner/admin/manager/staff) dibawa apa adanya saat impor,
-- supaya ekspor balik tidak mengubah siapa yang boleh Owner Login.
ALTER TABLE people ADD COLUMN legacy_role VARCHAR(20) NULL COMMENT 'roles.role_code lama: owner, admin, manager, staff' AFTER legacy_user_id;
