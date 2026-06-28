<script>
document.addEventListener('DOMContentLoaded', function () {
    const input = document.querySelector('[data-banner-input]');
    const preview = document.querySelector('[data-banner-preview]');

    if (!input || !preview || !window.URL || !window.URL.createObjectURL) {
        return;
    }

    const removeInput = document.querySelector('[data-banner-remove]');
    const placeholderText = preview.querySelector('[data-banner-placeholder-text]');
    let image = preview.querySelector('[data-banner-image]');
    let objectUrl = null;

    if (!image) {
        image = document.createElement('img');
        image.setAttribute('data-banner-image', '');
        image.alt = 'Preview banner restoran';
        image.hidden = true;
        preview.prepend(image);
    }

    const originalSrc = image.getAttribute('src') || '';
    const originalAlt = image.getAttribute('alt') || 'Preview banner restoran';
    const emptyText = placeholderText ? placeholderText.textContent : 'Belum ada gambar';

    const revokeObjectUrl = function () {
        if (objectUrl) {
            URL.revokeObjectURL(objectUrl);
            objectUrl = null;
        }
    };

    const showEmpty = function (message) {
        revokeObjectUrl();
        image.removeAttribute('src');
        image.hidden = true;
        image.alt = originalAlt;
        preview.classList.remove('has-image');
        preview.classList.add('is-empty');

        if (placeholderText) {
            placeholderText.textContent = message || emptyText;
        }
    };

    const showImage = function (src, alt) {
        image.src = src;
        image.alt = alt || originalAlt;
        image.hidden = false;
        preview.classList.add('has-image');
        preview.classList.remove('is-empty');

        if (placeholderText) {
            placeholderText.textContent = emptyText;
        }
    };

    const restoreOriginal = function () {
        if (originalSrc) {
            showImage(originalSrc, originalAlt);
            return;
        }

        showEmpty(emptyText);
    };

    input.addEventListener('change', function () {
        const file = input.files && input.files[0] ? input.files[0] : null;

        if (!file) {
            if (removeInput && removeInput.checked) {
                showEmpty('Banner akan dihapus');
                return;
            }

            restoreOriginal();
            return;
        }

        if (!file.type || !file.type.startsWith('image/')) {
            showEmpty('File bukan gambar');
            return;
        }

        revokeObjectUrl();
        objectUrl = URL.createObjectURL(file);
        showImage(objectUrl, 'Preview '+file.name);

        if (removeInput) {
            removeInput.checked = false;
        }
    });

    if (removeInput) {
        removeInput.addEventListener('change', function () {
            if (removeInput.checked) {
                input.value = '';
                showEmpty('Banner akan dihapus');
                return;
            }

            restoreOriginal();
        });

        if (removeInput.checked) {
            showEmpty('Banner akan dihapus');
        }
    }

    window.addEventListener('beforeunload', revokeObjectUrl);
});
</script>
