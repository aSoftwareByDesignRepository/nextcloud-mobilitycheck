<?php
/**
 * Settings — fleet admin can view; only app admins can save the access
 * policy and global defaults (§2.2 / §2.4). Role assignment and the
 * audit log are available to fleet admins.
 *
 * @var array $_
 * @var \OCP\IL10N $l
 */
include __DIR__ . '/common/page-start.php';
$isAppAdmin = !empty($_['isAppAdmin']);
?>
<?php if (!$isAppAdmin): ?>
<section class="mc-card mc-section mc-callout mc-callout--info" aria-labelledby="mc-set-ro-h" role="note">
	<header class="mc-section__header">
		<div>
			<h2 id="mc-set-ro-h"><?php p($l->t('You can manage roles and read the audit log here.')); ?></h2>
			<p class="mc-section__sub"><?php p($l->t('Changing the access policy or global defaults requires app administrator rights. Please ask your Nextcloud administrator or another app admin to make those changes.')); ?></p>
		</div>
	</header>
</section>
<?php endif; ?>

<section class="mc-card mc-section" aria-labelledby="mc-set-pol-h">
	<header class="mc-section__header">
		<div>
			<h2 id="mc-set-pol-h"><?php p($l->t('App access policy')); ?></h2>
			<p class="mc-section__sub"><?php p($l->t('Decide who can open MobilityCheck at all. Granular roles are below.')); ?></p>
		</div>
	</header>
	<form id="mc-set-policy" class="mc-form" novalidate <?php if (!$isAppAdmin): ?>aria-disabled="true"<?php endif; ?>>
		<div class="mc-grid-2">
			<div class="mc-form-row">
				<label for="mc-set-mode"><?php p($l->t('Access mode')); ?></label>
				<select id="mc-set-mode" name="mode" <?php if (!$isAppAdmin): ?>disabled<?php endif; ?>>
					<option value="open"><?php p($l->t('Open — every Nextcloud user with a MobilityCheck role can open the app')); ?></option>
					<option value="restricted"><?php p($l->t('Restricted — only allow-listed users and groups can open the app')); ?></option>
				</select>
				<p class="mc-form-row__hint"><?php p($l->t('System administrators and delegated app administrators always pass — they bootstrap the install.')); ?></p>
			</div>
		</div>
		<div class="mc-form-row">
			<label for="<?php p($isAppAdmin ? 'mc-set-groups-search' : 'mc-set-groups'); ?>"><?php p($l->t('Allowed groups')); ?></label>
			<?php if ($isAppAdmin): ?>
				<div id="mc-set-groups-ui" class="mc-multi-id-picker-host"></div>
				<textarea id="mc-set-groups" name="allowedGroups" class="mc-sr-only" tabindex="-1" aria-hidden="true" aria-describedby="mc-set-groups-hint mc-set-groups-err"></textarea>
			<?php else: ?>
				<textarea id="mc-set-groups" name="allowedGroups" rows="3" placeholder="<?php p($l->t('One group ID per line')); ?>" aria-describedby="mc-set-groups-hint mc-set-groups-err" readonly></textarea>
			<?php endif; ?>
			<p class="mc-form-row__hint" id="mc-set-groups-hint"><?php p($isAppAdmin
				? $l->t('Pick groups from the directory — one entry per chip. The list below stays in sync for saving.')
				: $l->t('Nextcloud group IDs (one per line). Members of any listed group can open MobilityCheck.')); ?></p>
			<p class="mc-form-row__error" id="mc-set-groups-err" role="alert"></p>
		</div>
		<div class="mc-form-row">
			<label for="<?php p($isAppAdmin ? 'mc-set-users-allowed-search' : 'mc-set-users-allowed'); ?>"><?php p($l->t('Allowed users')); ?></label>
			<?php if ($isAppAdmin): ?>
				<div id="mc-set-users-allowed-ui" class="mc-multi-id-picker-host"></div>
				<textarea id="mc-set-users-allowed" name="allowedUsers" class="mc-sr-only" tabindex="-1" aria-hidden="true" aria-describedby="mc-set-users-allowed-hint mc-set-users-allowed-err"></textarea>
			<?php else: ?>
				<textarea id="mc-set-users-allowed" name="allowedUsers" rows="3" placeholder="<?php p($l->t('One user ID per line')); ?>" aria-describedby="mc-set-users-allowed-hint mc-set-users-allowed-err" readonly></textarea>
			<?php endif; ?>
			<p class="mc-form-row__hint" id="mc-set-users-allowed-hint"><?php p($isAppAdmin
				? $l->t('Pick users from the directory — one entry per chip. The list below stays in sync for saving.')
				: $l->t('Nextcloud user IDs (one per line). For larger sets prefer “Allowed groups”.')); ?></p>
			<p class="mc-form-row__error" id="mc-set-users-allowed-err" role="alert"></p>
		</div>
		<div class="mc-form-row">
			<label for="<?php p($isAppAdmin ? 'mc-set-appadmins-search' : 'mc-set-appadmins'); ?>"><?php p($l->t('Delegated app administrators')); ?></label>
			<?php if ($isAppAdmin): ?>
				<div id="mc-set-appadmins-ui" class="mc-multi-id-picker-host"></div>
				<textarea id="mc-set-appadmins" name="appAdmins" class="mc-sr-only" tabindex="-1" aria-hidden="true" aria-describedby="mc-set-appadmins-hint"></textarea>
			<?php else: ?>
				<textarea id="mc-set-appadmins" name="appAdmins" rows="3" placeholder="<?php p($l->t('One user ID per line')); ?>" aria-describedby="mc-set-appadmins-hint" readonly></textarea>
			<?php endif; ?>
			<p class="mc-form-row__hint" id="mc-set-appadmins-hint"><?php p($isAppAdmin
				? $l->t('Delegated administrators (search to add). System administrators are always app administrators.')
				: $l->t('Non system-admin users who may change the access policy and global defaults. System administrators are always app administrators.')); ?></p>
		</div>
		<?php if ($isAppAdmin): ?>
		<div class="mc-form-actions">
			<button type="submit" class="button button-primary"><?php p($l->t('Save policy')); ?></button>
		</div>
		<?php endif; ?>
	</form>
</section>

<section class="mc-card mc-section" aria-labelledby="mc-set-ops-h">
	<header class="mc-section__header">
		<div>
			<h2 id="mc-set-ops-h"><?php p($l->t('Operational defaults')); ?></h2>
			<p class="mc-section__sub"><?php p($l->t('Currency, VAT, approval workflow and other knobs that affect every record. Changes apply to new records only; historical data is never rewritten.')); ?></p>
		</div>
	</header>
	<form id="mc-set-ops" class="mc-form" novalidate <?php if (!$isAppAdmin): ?>aria-disabled="true"<?php endif; ?>>
		<div class="mc-grid-2">
			<div class="mc-form-row mc-form-row--catalog">
				<span id="mc-set-curr-label" class="mc-form-row__label"><?php p($l->t('Currency (ISO 4217)')); ?></span>
				<?php
				$pickerId = 'mc-set-curr';
				$pickerName = 'currency';
				$pickerDefault = (string)($_['currency'] ?? 'EUR');
				$pickerDisabled = !$isAppAdmin;
				$pickerDescribedBy = 'mc-set-curr-hint';
				include __DIR__ . '/common/mc-currency-picker.php';
				?>
				<p class="mc-form-row__hint" id="mc-set-curr-hint"><?php p($l->t('Supported ISO 4217 codes only. Money is stored in minor units (e.g. cents for EUR).')); ?></p>
			</div>
			<div class="mc-form-row">
				<label for="mc-set-vat"><?php p($l->t('Default VAT rate')); ?></label>
				<select id="mc-set-vat" name="defaultVatBp" <?php if (!$isAppAdmin): ?>disabled<?php endif; ?>>
					<option value="1900">19% (<?php p($l->t('standard')); ?>)</option>
					<option value="700">7% (<?php p($l->t('reduced')); ?>)</option>
					<option value="0">0% (<?php p($l->t('exempt')); ?>)</option>
				</select>
				<p class="mc-form-row__hint"><?php p($l->t('Pre-fills the VAT picker when recording new costs. Each entry can still be overridden.')); ?></p>
			</div>
			<div class="mc-form-row mc-form-row--catalog">
				<span id="mc-set-tz-label" class="mc-form-row__label"><?php p($l->t('Default timezone')); ?></span>
				<?php
				$pickerId = 'mc-set-tz';
				$pickerName = 'defaultTimezone';
				$pickerDefault = (string)($_['defaultTimezone'] ?? 'Europe/Berlin');
				$pickerDisabled = !$isAppAdmin;
				$pickerDescribedBy = 'mc-set-tz-hint';
				include __DIR__ . '/common/mc-timezone-picker.php';
				?>
				<p class="mc-form-row__hint" id="mc-set-tz-hint"><?php p($l->t('Search the full IANA list. Datetimes are stored in UTC and shown in this zone by default across the app.')); ?></p>
			</div>
			<div class="mc-form-row">
				<label for="mc-set-approval-mode"><?php p($l->t('Booking approval workflow')); ?></label>
				<select id="mc-set-approval-mode" name="approvalMode" <?php if (!$isAppAdmin): ?>disabled<?php endif; ?>>
					<option value="none"><?php p($l->t('None — bookings are approved immediately')); ?></option>
					<option value="fleet_manager"><?php p($l->t('Fleet manager only')); ?></option>
					<option value="line_manager"><?php p($l->t('Line manager only (no fleet step)')); ?></option>
					<option value="line_manager_then_fleet"><?php p($l->t('Line manager, then fleet manager')); ?></option>
				</select>
				<p class="mc-form-row__hint"><?php p($l->t('Line manager steps require an active assignment for the driver (see below). Without one, new requests go to the fleet queue.')); ?></p>
			</div>
			<div class="mc-form-row mc-form-row--checkbox">
				<label class="mc-checkbox-row" for="mc-set-fallback-lm">
					<input id="mc-set-fallback-lm" name="approvalFallbackNoLm" type="checkbox" value="1" <?php if (!$isAppAdmin): ?>disabled<?php endif; ?>>
					<span><?php p($l->t('If no line manager is assigned, send the booking to fleet approval instead')); ?></span>
				</label>
			</div>
			<div class="mc-form-row">
				<label for="mc-set-grace"><?php p($l->t('Overdue check-in grace (minutes)')); ?></label>
				<input id="mc-set-grace" name="checkinGraceMinutes" type="number" min="0" max="1440" <?php if (!$isAppAdmin): ?>disabled<?php endif; ?>>
				<p class="mc-form-row__hint"><?php p($l->t('How long after the booking ends before the overdue check-in reminder fires.')); ?></p>
			</div>
			<div class="mc-form-row">
				<label for="mc-set-upload"><?php p($l->t('Max upload size (bytes)')); ?></label>
				<input id="mc-set-upload" name="maxUploadBytes" type="number" min="1048576" <?php if (!$isAppAdmin): ?>disabled<?php endif; ?>>
				<p class="mc-form-row__hint"><?php p($l->t('Default is 10 MB. Larger licence scans and damage photos are rejected with a clear error.')); ?></p>
			</div>
			<div class="mc-form-row mc-form-row--checkbox">
				<label class="mc-checkbox-row" for="mc-set-booking-ics">
					<input id="mc-set-booking-ics" name="bookingEmailAttachIcs" type="checkbox" value="1" aria-describedby="mc-set-booking-ics-hint" <?php if (!$isAppAdmin): ?>disabled<?php endif; ?>>
					<span><?php p($l->t('Attach calendar files to booking emails')); ?></span>
				</label>
				<p class="mc-form-row__hint" id="mc-set-booking-ics-hint"><?php p($l->t('Sends a standard .ics file with each booking-request, approval, update, and escalation email so recipients can import the slot. Times are UTC as stored in MobilityCheck. Calendar clients may keep older copies of the same booking until you remove them manually.')); ?></p>
			</div>
		</div>
		<?php if ($isAppAdmin): ?>
		<div class="mc-form-actions">
			<button type="submit" class="button button-primary"><?php p($l->t('Save defaults')); ?></button>
		</div>
		<?php endif; ?>
	</form>
</section>

<section class="mc-card mc-section" aria-labelledby="mc-set-alloc-h">
	<header class="mc-section__header">
		<div>
			<h2 id="mc-set-alloc-h"><?php p($l->t('Intelligent fleet allocation')); ?></h2>
			<p class="mc-section__sub"><?php p($l->t('Balances lease kilometres, vehicle age and recent utilisation when several vehicles match the same slot. Affects smart search and background reassignment.')); ?></p>
		</div>
	</header>
	<form id="mc-set-alloc" class="mc-form" novalidate <?php if (!$isAppAdmin): ?>aria-disabled="true"<?php endif; ?>>
		<div class="mc-form-row mc-form-row--checkbox">
			<label class="mc-checkbox-row" for="mc-set-alloc-en">
				<input id="mc-set-alloc-en" name="intelligentAllocationEnabled" type="checkbox" value="1" <?php if (!$isAppAdmin): ?>disabled<?php endif; ?> aria-describedby="mc-set-alloc-en-hint">
				<span><?php p($l->t('Enable intelligent allocation (lease / utilisation scoring) when vehicles become unavailable.')); ?></span>
			</label>
			<p class="mc-form-row__hint" id="mc-set-alloc-en-hint"><?php p($l->t('Off by default after upgrades so behaviour stays predictable until you turn it on.')); ?></p>
		</div>
		<div class="mc-grid-2">
			<div class="mc-form-row">
				<label for="mc-set-alloc-mode"><?php p($l->t('Allocation mode')); ?></label>
				<select id="mc-set-alloc-mode" name="intelligentAllocationMode" <?php if (!$isAppAdmin): ?>disabled<?php endif; ?> aria-describedby="mc-set-alloc-mode-hint">
					<option value="suggest_only"><?php p($l->t('Suggestions only — fleet must accept each proposal in the reassignment list.')); ?></option>
					<option value="auto_commit"><?php p($l->t('Automatic commit')); ?></option>
				</select>
				<p class="mc-form-row__hint" id="mc-set-alloc-mode-hint"><?php p($l->t('Suggest-only is safer for the first rollout: nothing moves until a fleet manager accepts.')); ?></p>
			</div>
			<div class="mc-form-row">
				<label for="mc-set-alloc-norep"><?php p($l->t('When no replacement vehicle is found')); ?></label>
				<select id="mc-set-alloc-norep" name="intelligentAllocationOnNoReplacement" <?php if (!$isAppAdmin): ?>disabled<?php endif; ?> aria-describedby="mc-set-alloc-norep-hint">
					<option value="flag_for_manual"><?php p($l->t('Flag for manual reassignment')); ?></option>
					<option value="auto_cancel"><?php p($l->t('Auto-cancel booking')); ?></option>
				</select>
				<p class="mc-form-row__hint" id="mc-set-alloc-norep-hint"><?php p($l->t('Auto-cancel is strict: use only when your process explicitly allows cancelling future bookings without a replacement.')); ?></p>
			</div>
			<div class="mc-form-row">
				<label for="mc-set-alloc-policy"><?php p($l->t('Vehicle choice policy')); ?></label>
				<select id="mc-set-alloc-policy" name="vehicleChoicePolicy" <?php if (!$isAppAdmin): ?>disabled<?php endif; ?> aria-describedby="mc-set-alloc-policy-hint">
					<option value="driver_may_choose"><?php p($l->t('Drivers pick any eligible vehicle; the recommended choice is pre-selected when intelligent allocation is on.')); ?></option>
					<option value="auto_assign_no_choice"><?php p($l->t('Drivers do not choose — system assigns the best eligible vehicle when intelligent allocation is on.')); ?></option>
					<option value="manager_assigns"><?php p($l->t('Fleet managers assign concrete vehicles after booking (manager-assigns mode).')); ?></option>
				</select>
				<p class="mc-form-row__hint" id="mc-set-alloc-policy-hint"><?php p($l->t('This controls how strongly the booking UI steers drivers toward the scored recommendation.')); ?></p>
			</div>
			<div class="mc-form-row">
				<label for="mc-set-alloc-min-days"><?php p($l->t('Minimum remaining lease days before booking end')); ?></label>
				<input id="mc-set-alloc-min-days" name="minRemainingLeaseDaysForBooking" type="number" min="0" max="3650" <?php if (!$isAppAdmin): ?>disabled<?php endif; ?> aria-describedby="mc-set-alloc-min-days-hint">
				<p class="mc-form-row__hint" id="mc-set-alloc-min-days-hint"><?php p($l->t('0 disables this filter. When set, bookings may not end closer than this many days before the vehicle lease end date.')); ?></p>
			</div>
			<div class="mc-form-row">
				<label for="mc-set-alloc-min-km"><?php p($l->t('Minimum remaining lease km headroom (percent)')); ?></label>
				<input id="mc-set-alloc-min-km" name="minRemainingLeaseKmPercent" type="number" min="0" max="100" <?php if (!$isAppAdmin): ?>disabled<?php endif; ?> aria-describedby="mc-set-alloc-min-km-hint">
				<p class="mc-form-row__hint" id="mc-set-alloc-min-km-hint"><?php p($l->t('0 disables. Uses the linear lease-km curve vs odometer (see product documentation). Vehicles below this headroom are hidden from new searches.')); ?></p>
			</div>
		</div>
		<fieldset class="mc-fieldset" aria-labelledby="mc-set-alloc-w-h">
			<legend id="mc-set-alloc-w-h"><?php p($l->t('Scoring weights (0–10)')); ?></legend>
			<p class="mc-form-row__hint"><?php p($l->t('Higher values make that signal matter more in the total score. Zero ignores that signal.')); ?></p>
			<div class="mc-grid-2">
				<div class="mc-form-row">
					<label for="mc-set-alloc-wl"><?php p($l->t('Lease burn-down')); ?></label>
					<input id="mc-set-alloc-wl" name="wLease" type="number" min="0" max="10" step="1" <?php if (!$isAppAdmin): ?>disabled<?php endif; ?>>
				</div>
				<div class="mc-form-row">
					<label for="mc-set-alloc-wa"><?php p($l->t('Vehicle age')); ?></label>
					<input id="mc-set-alloc-wa" name="wAge" type="number" min="0" max="10" step="1" <?php if (!$isAppAdmin): ?>disabled<?php endif; ?>>
				</div>
				<div class="mc-form-row">
					<label for="mc-set-alloc-wu"><?php p($l->t('Recent utilisation')); ?></label>
					<input id="mc-set-alloc-wu" name="wUtil" type="number" min="0" max="10" step="1" <?php if (!$isAppAdmin): ?>disabled<?php endif; ?>>
				</div>
				<div class="mc-form-row">
					<label for="mc-set-alloc-wo"><?php p($l->t('Operational fit')); ?></label>
					<input id="mc-set-alloc-wo" name="wOps" type="number" min="0" max="10" step="1" <?php if (!$isAppAdmin): ?>disabled<?php endif; ?>>
				</div>
			</div>
		</fieldset>
		<?php if ($isAppAdmin): ?>
		<div class="mc-form-actions">
			<button type="submit" class="button button-primary"><?php p($l->t('Save allocation settings')); ?></button>
		</div>
		<?php endif; ?>
	</form>
</section>

<section class="mc-card mc-section" aria-labelledby="mc-set-lm-h">
	<header class="mc-section__header">
		<div>
			<h2 id="mc-set-lm-h"><?php p($l->t('Line manager assignments')); ?></h2>
			<p class="mc-section__sub"><?php p($l->t('Define which user approves bookings for which driver. Overlapping date ranges for the same driver are not allowed.')); ?></p>
		</div>
	</header>
	<form id="mc-set-lm-add" class="mc-form" novalidate>
		<div class="mc-grid-2">
			<div class="mc-form-row">
				<label for="mc-lm-driver-combo"><?php p($l->t('Driver')); ?></label>
				<div id="mc-lm-driver-wrap"></div>
			</div>
			<div class="mc-form-row">
				<label for="mc-lm-manager-combo"><?php p($l->t('Line manager')); ?></label>
				<div id="mc-lm-manager-wrap"></div>
			</div>
			<div class="mc-form-row">
				<label for="mc-lm-from"><?php p($l->t('Valid from')); ?></label>
				<input id="mc-lm-from" name="validFrom" type="date">
			</div>
			<div class="mc-form-row">
				<label for="mc-lm-notes"><?php p($l->t('Notes (optional)')); ?></label>
				<input id="mc-lm-notes" name="notes" type="text" maxlength="500" autocomplete="off">
			</div>
		</div>
		<div class="mc-form-actions">
			<button type="submit" class="button button-primary"><?php p($l->t('Add assignment')); ?></button>
		</div>
	</form>
	<div id="mc-set-lm-list"></div>
</section>

<section class="mc-card mc-section" aria-labelledby="mc-set-roles-h">
	<header class="mc-section__header">
		<div>
			<h2 id="mc-set-roles-h"><?php p($l->t('Role assignment')); ?></h2>
			<p class="mc-section__sub"><?php p($l->t('Assign MobilityCheck roles to existing Nextcloud users. Roles combine (e.g. fleet manager + driver). Workshop accounts get a minimised shell.')); ?></p>
			<div class="mc-grid-2 mc-role-overview" aria-label="<?php p($l->t('Role overview')); ?>">
				<div class="mc-role-overview__column">
					<h3 class="mc-role-overview__title"><?php p($l->t('Fleet roles')); ?></h3>
					<ul class="mc-list">
						<li><strong><?php p($l->t('Fleet admin')); ?></strong> — <?php p($l->t('full control over MobilityCheck (settings, vehicles, bookings, damage, costs, reports). Use sparingly.')); ?></li>
						<li><strong><?php p($l->t('Fleet manager')); ?></strong> — <?php p($l->t('operates the fleet day to day: approves bookings, manages vehicles, reviews damage and maintenance.')); ?></li>
						<li><strong><?php p($l->t('Workshop')); ?></strong> — <?php p($l->t('focus on technical work: see damage and maintenance, update repair status, no access to HR‑sensitive data.')); ?></li>
					</ul>
				</div>
				<div class="mc-role-overview__column">
					<h3 class="mc-role-overview__title"><?php p($l->t('Driver and audit roles')); ?></h3>
					<ul class="mc-list">
						<li><strong><?php p($l->t('Driver')); ?></strong> — <?php p($l->t('books and uses vehicles, reports damage, uploads licence, completes driver instruction.')); ?></li>
						<li><strong><?php p($l->t('Line manager')); ?></strong> — <?php p($l->t('approves bookings for supervised drivers when the approval workflow includes a line‑manager step.')); ?></li>
						<li><strong><?php p($l->t('Auditor')); ?></strong> — <?php p($l->t('read‑only access to bookings, damage, costs and the audit log for compliance reviews.')); ?></li>
					</ul>
				</div>
			</div>
		</div>
	</header>
	<form id="mc-set-search" class="mc-toolbar" role="search" aria-label="<?php p($l->t('User search')); ?>">
		<div class="mc-form-row">
			<label for="mc-set-q"><?php p($l->t('Search users')); ?></label>
			<input id="mc-set-q" name="search" type="search" placeholder="<?php p($l->t('Start typing a name or user ID…')); ?>" autocomplete="off">
			<p class="mc-form-row__hint"><?php p($l->t('Matches Nextcloud display names and user IDs. Pick a user, tick the right roles, then “Save”.')); ?></p>
		</div>
	</form>
	<div id="mc-set-users" aria-live="polite"></div>
</section>

<section class="mc-card mc-section" aria-labelledby="mc-set-audit-h">
	<header class="mc-section__header">
		<div>
			<h2 id="mc-set-audit-h"><?php p($l->t('Audit log')); ?></h2>
			<p class="mc-section__sub"><?php p($l->t('Append-only record of every state change inside MobilityCheck. Use the filters to narrow down — empty filters return the most recent entries.')); ?></p>
		</div>
	</header>
	<form id="mc-set-audit-filters" class="mc-toolbar" role="search" aria-label="<?php p($l->t('Audit log filters')); ?>">
		<div class="mc-form-row">
			<label for="mc-set-audit-entity"><?php p($l->t('Entity type')); ?></label>
			<input id="mc-set-audit-entity" name="entityType" type="text" placeholder="<?php p($l->t('e.g. booking, vehicle, damage')); ?>" autocomplete="off">
		</div>
		<div class="mc-form-row">
			<label for="mc-set-audit-user-combo"><?php p($l->t('Performed by')); ?></label>
			<div id="mc-set-audit-user-wrap"></div>
		</div>
	</form>
	<div id="mc-set-audit" aria-live="polite"></div>
</section>

<?php include __DIR__ . '/common/page-end.php'; ?>
