<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Settings;

use OCA\MobilityCheck\AppInfo\Application;
use OCA\MobilityCheck\Service\AccessControlService;
use OCA\MobilityCheck\Service\SettingsService;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IURLGenerator;
use OCP\Settings\ISettings;

/**
 * Administrator-only settings panel for MobilityCheck. The panel itself is a
 * thin shim around the in-app `/settings` page so configuration lives
 * close to the app it governs; this entry exists so administrators
 * discover MobilityCheck policy from the standard Nextcloud admin UI.
 */
final class AdminSettings implements ISettings
{
	public function __construct(
		private AccessControlService $access,
		private SettingsService $settings,
		private IURLGenerator $urlGenerator,
	) {
	}

	public function getForm(): TemplateResponse
	{
		$response = new TemplateResponse(
			Application::APP_ID,
			'admin-settings',
			[
				'settingsUrl' => $this->urlGenerator->linkToRoute('mobilitycheck.page.settings'),
				'policy' => $this->access->appPolicy(),
				'settings' => $this->settings->all(),
			],
			TemplateResponse::RENDER_AS_BLANK,
		);
		return $response;
	}

	public function getSection(): string
	{
		return Application::APP_ID;
	}

	public function getPriority(): int
	{
		return 50;
	}
}
