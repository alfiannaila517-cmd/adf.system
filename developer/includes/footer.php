    </main>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // HP: tabel biasa tampil sebagai kartu (label dari judul kolom). Tabel matrix centang
        // (Akses Owner) dan tabel yang diberi data-no-cards tetap berbentuk tabel.
        document.querySelectorAll('.main-content table').forEach(function (table) {
            if (table.classList.contains('oa-table') || table.hasAttribute('data-no-cards')) return;
            var heads = Array.prototype.map.call(table.querySelectorAll('thead th'), function (th) { return th.textContent.trim(); });
            if (!heads.length) return;
            table.classList.add('m-cards');
            table.querySelectorAll('tbody tr').forEach(function (tr) {
                Array.prototype.forEach.call(tr.children, function (td, i) {
                    if (!td.hasAttribute('data-label')) td.setAttribute('data-label', td.hasAttribute('colspan') ? '' : (heads[i] || ''));
                });
            });
        });
    </script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        // Confirm delete
        function confirmDelete(url, name) {
            Swal.fire({
                title: 'Are you sure?',
                text: `Delete "${name}"? This cannot be undone!`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                cancelButtonColor: '#6c757d',
                confirmButtonText: 'Yes, delete it!'
            }).then((result) => {
                if (result.isConfirmed) {
                    window.location.href = url;
                }
            });
        }
        
        // Toast notification
        function showToast(message, type = 'success') {
            const Toast = Swal.mixin({
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 3000,
                timerProgressBar: true
            });
            
            Toast.fire({
                icon: type,
                title: message
            });
        }
        
        // Show success message if exists
        <?php if (isset($_SESSION['success_message'])): ?>
        showToast('<?php echo addslashes($_SESSION['success_message']); ?>', 'success');
        <?php unset($_SESSION['success_message']); endif; ?>
        
        <?php if (isset($_SESSION['error_message'])): ?>
        showToast('<?php echo addslashes($_SESSION['error_message']); ?>', 'error');
        <?php unset($_SESSION['error_message']); endif; ?>
    </script>
</body>
</html>
