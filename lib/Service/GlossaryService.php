<?php

declare(strict_types=1);

namespace OCA\MobilityCheck\Service;

use OCA\MobilityCheck\AppInfo\Application;
use OCP\IL10N;
use OCP\L10N\IFactory;

/**
 * §2a.4 — Inline glossary for German tax terminology that not every
 * driver knows by heart. Translators provide the language-specific
 * copy in `l10n/{en,de}.json`; this service is the single PHP entry
 * point. A JS counterpart (`window.MobilityCheckGlossary`) mirrors the
 * same key catalogue so explanations stay consistent across surfaces.
 */
final class GlossaryService
{
	public const TERMS = [
		'fahrtenbuch',
		'one_percent_rule',
		'erste_taetigkeitsstaette',
		'geldwerter_vorteil',
		'bruttolistenpreis',
		'gobd',
		'fahrtkostenabrechnung',
		'fahrunterweisung',
	];

	private IL10N $l10n;

	public function __construct(IFactory $factory)
	{
		$this->l10n = $factory->get(Application::APP_ID);
	}

	public function define(string $term): string
	{
		// Nextcloud's L10N::t() runs vsprintf() on every string. Any literal
		// "%" therefore has to be doubled to escape itself; the (de.)
		// translation does the same in the JSON file so the two stay in sync.
		return match ($term) {
			'fahrtenbuch' => $this->l10n->t('Fahrtenbuch — a legally required trip log that records every journey made in a company car. It is the basis for calculating the exact taxable private-use benefit instead of using the flat 1 %% rule.'),
			'one_percent_rule' => $this->l10n->t('1 %%-Regelung — a simplified method for taxing private use of a company car. The taxable benefit is calculated as 1 %% of the vehicle’s original manufacturer list price per month, regardless of how much the car is actually used privately.'),
			'erste_taetigkeitsstaette' => $this->l10n->t('Erste Tätigkeitsstätte — your regular, primary workplace. Trips between home and this location are classified as commute trips and are subject to their own tax rules.'),
			'geldwerter_vorteil' => $this->l10n->t('Geldwerter Vorteil — the taxable financial benefit of using a company car for private purposes. This amount is added to your gross salary and taxed accordingly.'),
			'bruttolistenpreis' => $this->l10n->t('Bruttolistenpreis — the manufacturer’s original recommended retail price including VAT, at the time of the vehicle’s first registration. This is not the purchase price and not the current resale value.'),
			'gobd' => $this->l10n->t('GoBD — Principles for the proper maintenance of digital records, issued by the German tax authorities. MobilityCheck follows these rules to ensure all records are legally admissible as evidence.'),
			'fahrtkostenabrechnung' => $this->l10n->t('Fahrtkostenabrechnung — a reimbursement claim for trips made using your own private vehicle for business purposes. You are entitled to a set rate per kilometre under German tax law.'),
			'fahrunterweisung' => $this->l10n->t('Fahrunterweisung — the annual safety and usage briefing every driver in Germany must complete. MobilityCheck does not replace the briefing; it tracks completion per driver and calendar year.'),
			default => '',
		};
	}

	/** @return array<string,string> term => translated definition */
	public function all(): array
	{
		$out = [];
		foreach (self::TERMS as $term) {
			$out[$term] = $this->define($term);
		}
		return $out;
	}
}
