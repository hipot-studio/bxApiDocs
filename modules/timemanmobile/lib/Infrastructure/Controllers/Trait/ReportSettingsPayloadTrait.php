<?php

namespace Bitrix\TimemanMobile\Infrastructure\Controllers\Trait;

use Bitrix\Mobile\Config\Feature;
use Bitrix\Timeman\V2\Public\Provider;
use Bitrix\TimemanMobile\Public\Features\WorkReportsWithAiFeature;
use Bitrix\TimemanMobile\Public\Features\WorkReportsWithoutAiFeature;
use Bitrix\TimemanMobile\Public\Providers\SettingsProvider;

trait ReportSettingsPayloadTrait
{
	/**
	 * The payload describes the user who asks for it: the AI reports setting is answered per department,
	 * so it belongs to the reader of the screen and to nobody else.
	 *
	 * Two answers about the AI reports layer live here, and they answer different questions.
	 * `isAiReportsEnabled` tells whether the app shows the content of that layer, and the portal flag turns
	 * it off on its own. `isAiReportsSchemeOn` tells which scheme the reports of this user are stored in,
	 * and only the setting of the department answers that, exactly as the web page answers it: a flag of
	 * the mobile client hides content, it moves no report from one scheme to the other, and a write sent
	 * into the scheme a report does not live in is refused by the gates of the legacy layer.
	 */
	protected function getReportSettingsPayload(): array
	{
		$isAiReportsEnabled = Feature::isEnabled(WorkReportsWithAiFeature::class);
		$provider = $this->reportSettingsProvider();
		$isAiReportsSchemeOn = $provider->isReportsEnabledWithAi();

		if (Feature::isEnabled(WorkReportsWithoutAiFeature::class))
		{
			return [
				'isReportsSettingTurnedOn' => true,
				'isReportGenerationAvailable' => false,
				'hasAiReportAccess' => false,
				'isAiReportsEnabled' => $isAiReportsEnabled,
				'isAiReportsSchemeOn' => $isAiReportsSchemeOn,
			];
		}

		return [
			'isReportsSettingTurnedOn' => $provider->isReportsSettingTurnedOn(),
			'isReportGenerationAvailable' => $provider->isReportGenerationAvailable(),
			'hasAiReportAccess' => $provider->hasAiReportAccess(),
			'isAiReportsEnabled' => $isAiReportsEnabled,
			'isAiReportsSchemeOn' => $isAiReportsSchemeOn,
		];
	}

	/**
	 * The settings of the reader are reached through a method so that a test can answer for them: the
	 * payload is a projection of those settings, and the settings of a portal are no fixture — they live
	 * in the structure of departments and in the agents of the planning service.
	 */
	protected function reportSettingsProvider(): SettingsProvider
	{
		return new SettingsProvider();
	}

	/**
	 * The scheme the reports of a named user are stored in, asked about somebody else than the current
	 * one: a decision is addressed to the owner of the report, and the manager writing it may read another
	 * scheme. The setting of the department is the only condition, the way the web page reads it — the
	 * portal flag of the mobile client hides the AI content of the app and stores nothing anywhere, while
	 * the gate inside the legacy publication asks this very setting about this very owner.
	 */
	protected function isAiReportsEnabledFor(int $userId): bool
	{
		return (new Provider\SettingsProvider($userId))->isReportsEnabledWithAi();
	}
}
