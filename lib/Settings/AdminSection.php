<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Settings;

use OCA\MobilityCheck\AppInfo\Application;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

/**
 * Adds a "MobilityCheck" section to Nextcloud's admin settings sidebar so the
 * app's access policy and defaults are reachable from the standard
 * settings panel — same pattern as BudgetCheck and DutyCheck.
 */
final class AdminSection implements IIconSection
{
	public function __construct(
		private IL10N $l10n,
		private IURLGenerator $urlGenerator,
	) {
	}

	public function getID(): string
	{
		return Application::APP_ID;
	}

	public function getName(): string
	{
		return $this->l10n->t('MobilityCheck');
	}

	public function getPriority(): int
	{
		return 70;
	}

	public function getIcon(): string
	{
		return $this->urlGenerator->imagePath(Application::APP_ID, 'app.svg');
	}
}
