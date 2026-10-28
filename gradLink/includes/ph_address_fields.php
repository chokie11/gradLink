<?php
/**
 * Philippine address fields (Province > City/Municipality > Barangay + street lines).
 *
 * Expects before include:
 *   $gl_addr = [
 *     'province' => '', 'municipality' => '', 'barangay' => '',
 *     'street_address' => '', 'subdivision' => '',
 *     'legacy' => '',          // old single-line address (shown only if no structured address yet)
 *   ];
 *   $gl_addr_base = '../';     // path prefix to project root (default '../')
 */
$gl_addr_base = $gl_addr_base ?? '../';
$gl_addr = $gl_addr ?? [];
$gl_h = static fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
$gl_has_structured = !empty($gl_addr['province']);
?>
<div class="col-12" data-ph-address data-base="<?= $gl_h($gl_addr_base) ?>assets/data/ph-address/">
    <div class="row g-3">
        <?php if (!$gl_has_structured && !empty($gl_addr['legacy'])): ?>
            <div class="col-12">
                <div class="alert alert-warning py-2 mb-0 small">
                    <strong>Previously saved address:</strong> <?= $gl_h($gl_addr['legacy']) ?><br>
                    Please select your province, city/municipality, and barangay below to update it.
                </div>
            </div>
        <?php endif; ?>

        <div class="col-md-4">
            <label class="form-label">Province</label>
            <select name="province" class="form-select" data-selected="<?= $gl_h($gl_addr['province'] ?? '') ?>">
                <option value="">Select province</option>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label">City / Municipality</label>
            <select name="municipality" class="form-select" data-selected="<?= $gl_h($gl_addr['municipality'] ?? '') ?>" disabled>
                <option value="">Select city / municipality</option>
            </select>
        </div>
        <div class="col-md-4">
            <label class="form-label">Barangay</label>
            <select name="barangay" class="form-select" data-selected="<?= $gl_h($gl_addr['barangay'] ?? '') ?>" disabled>
                <option value="">Select barangay</option>
            </select>
        </div>
        <div class="col-md-6">
            <label class="form-label">House No. / Building / Street</label>
            <input type="text" name="street_address" class="form-control" maxlength="255"
                   value="<?= $gl_h($gl_addr['street_address'] ?? '') ?>"
                   placeholder="e.g. 123 Rizal Street, Unit 4B">
        </div>
        <div class="col-md-6">
            <label class="form-label">Subdivision / Village / Purok / Sitio</label>
            <input type="text" name="subdivision" class="form-control" maxlength="255"
                   value="<?= $gl_h($gl_addr['subdivision'] ?? '') ?>"
                   placeholder="e.g. Villa Sol Subdivision, Purok 3">
        </div>
    </div>
</div>
<script src="<?= $gl_h($gl_addr_base) ?>assets/js/ph-address.js"></script>
