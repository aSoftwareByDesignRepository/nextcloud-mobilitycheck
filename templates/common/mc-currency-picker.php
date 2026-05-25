<?php
/**
 * Searchable ISO currency picker shell (hydrated by catalog-pickers.js).
 *
 * @var \OCP\IL10N $l
 * @var string $pickerId id prefix (e.g. mc-set-curr)
 * @var string $pickerName form field name on the hidden native select
 * @var string $pickerDefault default ISO code
 * @var bool $pickerDisabled
 * @var string|null $pickerDescribedBy space-separated ids for aria-describedby
 */
$pickerId = (string) ($pickerId ?? 'mc-set-curr');
$pickerName = (string) ($pickerName ?? 'currency');
$pickerDefault = (string) ($pickerDefault ?? 'EUR');
$pickerDisabled = !empty($pickerDisabled);
$pickerDescribedBy = isset($pickerDescribedBy) ? (string) $pickerDescribedBy : '';
$inputId = $pickerId . '-input';
$resultsId = $pickerId . '-results';
$statusId = $pickerId . '-status';
$labelId = $pickerId . '-label';
?>
<div class="mc-catalog-picker mc-catalog-picker--currency" data-mc-currency-picker data-default-currency="<?php p($pickerDefault); ?>">
	<select id="<?php p($pickerId); ?>" name="<?php p($pickerName); ?>" class="mc-catalog-picker__native" tabindex="-1" aria-hidden="true" required<?php if ($pickerDisabled): ?> disabled<?php endif; ?>></select>
	<div class="mc-catalog-picker__control">
		<input
			type="search"
			id="<?php p($inputId); ?>"
			class="mc-input mc-catalog-picker__input"
			role="combobox"
			aria-autocomplete="list"
			aria-expanded="false"
			aria-controls="<?php p($resultsId); ?>"
			aria-labelledby="<?php p($labelId); ?>"
			<?php if ($pickerDescribedBy !== ''): ?>aria-describedby="<?php p($pickerDescribedBy); ?>" <?php endif; ?>
			autocomplete="off"
			spellcheck="false"
			inputmode="search"
			placeholder="<?php p($l->t('Search currencies (e.g. EUR, USD, or RUB)')); ?>"
			<?php if ($pickerDisabled): ?>disabled<?php endif; ?>
		>
		<button type="button" class="mc-catalog-picker__clear button" hidden
			aria-label="<?php p($l->t('Clear currency selection')); ?>"
			<?php if ($pickerDisabled): ?>disabled<?php endif; ?>>×</button>
	</div>
	<ul id="<?php p($resultsId); ?>" class="mc-catalog-picker__results" role="listbox" hidden></ul>
	<p id="<?php p($statusId); ?>" class="mc-catalog-picker__status" role="status" aria-live="polite" aria-atomic="true" hidden></p>
</div>
