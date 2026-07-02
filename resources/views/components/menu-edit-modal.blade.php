@props([
    'title' => 'Edit Menu',
])

<div id="menu-edit-modal" style="position:fixed; inset:0; display:none; align-items:center; justify-content:center; background:rgba(17,24,39,.5); z-index:1200; padding:16px;">
    <div style="width:min(560px, 100%); max-height:90vh; overflow:auto; background:var(--bg-card); border:1px solid var(--border-color); border-radius:12px; box-shadow:var(--shadow-md);">
        <div class="panel-header" style="position:sticky; top:0; background:var(--bg-card); z-index:2;">
            <div class="panel-title">{{ $title }}</div>
            <button type="button" id="menu-edit-close-btn" class="btn-action detail" title="Tutup">
                <i class='bx bx-x'></i>
            </button>
        </div>

        <form id="menu-edit-form" method="POST" style="padding:20px;">
            @csrf
            @method('PUT')

            <div class="form-group" style="margin-bottom:14px;">
                <label>Nama Menu</label>
                <input type="text" id="edit-name" name="name" class="form-control" required>
            </div>
            <div class="form-group" style="margin-bottom:14px;">
                <label>Harga</label>
                <input type="number" step="0.01" min="0" id="edit-price" name="price" class="form-control">
            </div>
            <div class="form-group" style="margin-bottom:14px;">
                <label>Urutan</label>
                <input type="number" min="0" id="edit-sort-order" name="sort_order" class="form-control">
            </div>
            <div class="form-group" style="margin-bottom:14px;">
                <label>Status</label>
                <select id="edit-is-available" name="is_available" class="form-control" required>
                    <option value="1">Tersedia</option>
                    <option value="0">Tidak Tersedia</option>
                </select>
            </div>
            <div style="display:flex; justify-content:flex-end; gap:8px;">
                <button type="button" id="menu-edit-cancel-btn" class="btn" style="background:var(--bg-hover); color:var(--text-muted);">Batal</button>
                <button type="submit" class="btn btn-primary"><i class='bx bx-save'></i> Simpan</button>
            </div>
        </form>
    </div>
</div>

@once
    @push('scripts')
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        const modal = document.getElementById('menu-edit-modal');
        const editForm = document.getElementById('menu-edit-form');
        const closeBtn = document.getElementById('menu-edit-close-btn');
        const cancelBtn = document.getElementById('menu-edit-cancel-btn');
        const editButtons = document.querySelectorAll('.js-edit-menu-btn');

        if (!modal || !editForm) {
            return;
        }

        const fieldName = document.getElementById('edit-name');
        const fieldPrice = document.getElementById('edit-price');
        const fieldSortOrder = document.getElementById('edit-sort-order');
        const fieldIsAvailable = document.getElementById('edit-is-available');

        const openModal = () => {
            modal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
        };

        const closeModal = () => {
            modal.style.display = 'none';
            document.body.style.overflow = '';
        };

        editButtons.forEach((button) => {
            button.addEventListener('click', function () {
                editForm.action = button.dataset.updateUrl;
                fieldName.value = button.dataset.name || '';
                fieldPrice.value = button.dataset.price || '';
                fieldSortOrder.value = button.dataset.sortOrder || '0';
                fieldIsAvailable.value = button.dataset.isAvailable || '1';
                openModal();
                fieldName.focus();
            });
        });

        closeBtn.addEventListener('click', closeModal);
        cancelBtn.addEventListener('click', closeModal);

        modal.addEventListener('click', function (event) {
            if (event.target === modal) {
                closeModal();
            }
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && modal.style.display === 'flex') {
                closeModal();
            }
        });
    });
    </script>
    @endpush
@endonce
