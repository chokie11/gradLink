<?php
/**
 * Profile picture upload block with live preview.
 *
 * Expects before include:
 *   $gl_photo_url      string|null  current photo URL (or null)
 *   $gl_photo_initials string       fallback initials
 *   $gl_photo_accent   string       accent colour, e.g. '#800000' (optional)
 *   $gl_photo_label    string       field label (optional)
 * The form must have enctype="multipart/form-data".
 * Posts: profile_photo (file), remove_photo (checkbox).
 */
$gl_photo_url = $gl_photo_url ?? null;
$gl_photo_initials = $gl_photo_initials ?? '';
$gl_photo_accent = $gl_photo_accent ?? '#800000';
$gl_photo_label = $gl_photo_label ?? 'Profile Picture';
$gl_h2 = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
?>
<style>
    .gl-photo-field { display: flex; align-items: center; gap: 18px; flex-wrap: wrap; }
    .gl-photo-preview {
        width: 96px; height: 96px; border-radius: 50%; overflow: hidden; flex: 0 0 auto;
        display: flex; align-items: center; justify-content: center;
        background: <?= $gl_h2($gl_photo_accent) ?>; color: #fff; font-size: 30px; font-weight: 800;
        border: 3px solid #fff; box-shadow: 0 8px 22px rgba(0, 0, 0, .15);
    }
    .gl-photo-preview img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .gl-photo-actions { flex: 1 1 240px; min-width: 0; }
    .gl-photo-error { color: #b42318; font-size: 13px; margin-top: 6px; display: none; }
</style>

<div class="col-12">
    <label class="form-label"><?= $gl_h2($gl_photo_label) ?></label>
    <div class="gl-photo-field">
        <div class="gl-photo-preview" id="glPhotoPreview"
             data-initials="<?= $gl_h2($gl_photo_initials) ?>">
            <?php if ($gl_photo_url): ?>
                <img src="<?= $gl_h2($gl_photo_url) ?>" alt="Profile picture">
            <?php else: ?>
                <?= $gl_h2($gl_photo_initials) ?>
            <?php endif; ?>
        </div>
        <div class="gl-photo-actions">
            <input type="file" name="profile_photo" id="glPhotoInput" class="form-control"
                   accept="image/jpeg,image/png,image/webp">
            <div class="form-text">JPG, PNG, or WEBP. Maximum 2 MB. A square photo works best.</div>
            <div class="gl-photo-error" id="glPhotoError"></div>
            <?php if ($gl_photo_url): ?>
                <div class="form-check mt-2">
                    <input class="form-check-input" type="checkbox" name="remove_photo" value="1" id="glRemovePhoto">
                    <label class="form-check-label" for="glRemovePhoto">Remove current picture</label>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
    (function () {
        var input = document.getElementById('glPhotoInput');
        var preview = document.getElementById('glPhotoPreview');
        var err = document.getElementById('glPhotoError');
        if (!input || !preview) return;
        var originalHtml = preview.innerHTML;

        input.addEventListener('change', function () {
            err.style.display = 'none';
            var f = input.files && input.files[0];
            if (!f) { preview.innerHTML = originalHtml; return; }

            var okType = ['image/jpeg', 'image/png', 'image/webp'].indexOf(f.type) !== -1;
            if (!okType || f.size > 2 * 1024 * 1024) {
                err.textContent = !okType
                    ? 'Please choose a JPG, PNG, or WEBP image.'
                    : 'That image is larger than 2 MB. Please choose a smaller one.';
                err.style.display = 'block';
                input.value = '';
                preview.innerHTML = originalHtml;
                return;
            }

            var img = document.createElement('img');
            img.alt = 'Profile picture preview';
            img.src = URL.createObjectURL(f);
            preview.innerHTML = '';
            preview.appendChild(img);

            var rm = document.getElementById('glRemovePhoto');
            if (rm) rm.checked = false;
        });
    })();
</script>
