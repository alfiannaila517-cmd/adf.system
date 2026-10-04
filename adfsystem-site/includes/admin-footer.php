</main>
</div>
</div>
<script>
    // HP: tiap sel tabel diberi label dari judul kolomnya, supaya di layar kecil tabel tampil sebagai kartu.
    document.querySelectorAll('.admin-body table').forEach(function (table) {
        var heads = Array.prototype.map.call(table.querySelectorAll('thead th'), function (th) { return th.textContent.trim(); });
        if (!heads.length) return;
        table.classList.add('m-cards');
        table.querySelectorAll('tbody tr').forEach(function (tr) {
            Array.prototype.forEach.call(tr.children, function (td, i) {
                if (!td.hasAttribute('data-label')) td.setAttribute('data-label', td.hasAttribute('colspan') ? '' : (heads[i] || ''));
            });
        });
    });

    // Dibuka sebagai aplikasi (Add to Home Screen): Developer Panel dibuka di jendela yang sama, tidak lempar ke Safari.
    if (window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone) {
        document.querySelectorAll('form[action="developer-sso.php"]').forEach(function (f) { f.removeAttribute('target'); });
    }
</script>
</body>

</html>
