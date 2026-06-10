<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Service;

use OCA\MobilityCheck\AppInfo\Application;
use OCP\IDateTimeFormatter;
use OCP\IDateTimeZone;
use OCP\IUserSession;
use OCP\L10N\IFactory;

/**
 * Bridges Nextcloud's locale + timezone facilities to the templates
 * and the JS client (via `data-*` attributes on `#app-content`).
 */
class LocaleFormatService
{
	public function __construct(
		private IFactory $l10nFactory,
		private IDateTimeFormatter $dateTimeFormatter,
		private IUserSession $userSession,
		private IDateTimeZone $dateTimeZone,
		private SettingsService $settings,
		private TimezoneCatalog $timezones,
	) {
	}

	public function canonicalHtmlLangFromLocaleString(?string $rawLocale): string
	{
		$locale = strtolower(trim((string)$rawLocale));
		if ($locale === '') {
			return 'en-US';
		}
		$locale = str_replace('_', '-', $locale);
		if (preg_match('/^[a-z]{2}-[a-z]{2}$/', $locale) !== 1) {
			return match ($locale) {
				'de' => 'de-DE',
				'en' => 'en-US',
				'fr' => 'fr-FR',
				'es' => 'es-ES',
				'da' => 'da-DK',
				'nl' => 'nl-NL',
				'it' => 'it-IT',
				'pl' => 'pl-PL',
				'pt' => 'pt-PT',
				default => 'en-US',
			};
		}
		$parts = explode('-', $locale);
		return strtolower($parts[0]) . '-' . strtoupper($parts[1]);
	}

	/**
	 * @return array{locale:string,htmlLang:string,timezone:string,exampleDate:string}
	 */
	public function clientHints(): array
	{
		$user = $this->userSession->getUser();
		$locale = '';
		if ($user !== null) {
			$locale = (string)$this->l10nFactory->getUserLanguage($user);
		}
		if ($locale === '') {
			$locale = (string)$this->l10nFactory->findLanguage(Application::APP_ID);
		}
		$htmlLang = $this->canonicalHtmlLangFromLocaleString($locale);
		$appTz = $this->settings->defaultTimezone();
		if ($this->timezones->isValid($appTz)) {
			$tzName = $appTz;
		} else {
			$tz = $this->dateTimeZone->getTimeZone();
			$tzName = $tz instanceof \DateTimeZone ? $tz->getName() : 'UTC';
		}
		return [
			'locale' => $htmlLang,
			'htmlLang' => $htmlLang,
			'timezone' => $tzName,
			'exampleDate' => $this->dateTimeFormatter->formatDateTime(
				(new \DateTimeImmutable('now'))->getTimestamp()
			),
		];
	}

	/**
	 * Format a UTC `Y-m-d H:i:s` value from the database for the current user's locale and timezone.
	 */
	public function formatUtcSqlDatetimeForUser(string $utcSql): string
	{
		$dt = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $utcSql, new \DateTimeZone('UTC'));
		if ($dt === false) {
			return $utcSql;
		}
		return $this->dateTimeFormatter->formatDateTime($dt->getTimestamp());
	}
}
