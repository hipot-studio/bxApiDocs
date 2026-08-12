<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Internal\Service;

use Bitrix\Bizproc\Starter\Dto\ContextDto;
use Bitrix\Bizproc\Starter\Enum\Scenario;
use Bitrix\Bizproc\Starter\Starter;
use Bitrix\Main\Loader;
use Bitrix\Timeman\V2\Internal\Entity\FullReport\FullReport;
use Bitrix\Timeman\V2\Internal\Integration\Bizproc\FullReportSentTrigger;

final class FullReportSendNotifier
{
	public function __construct(
		private readonly ReportTextNormalizerService $reportTextNormalizer,
	)
	{
	}

	/**
	 * @param array<int, int> $managerIds
	 */
	public function notifyManagerAboutSentReport(
		FullReport $report,
		int $senderId,
		array $managerIds,
	): void
	{
		if ($this->isStarterEnabled())
		{
			$fields = [
				FullReportSentTrigger::FIELD_USER_ID => $senderId,
				FullReportSentTrigger::FIELD_REPORT => $this->normalizeForChat((string)$report->report),
				FullReportSentTrigger::FIELD_REPORT_EXTENDED => $this->normalizeForChat((string)$report->reportExtended),
			];

			Starter::getByScenario(Scenario::onEvent)
				->setContext(new ContextDto('timeman'))
				->addEvent('FullReportSentTrigger', [], $fields)
				->start()
			;
		}
	}

	private function normalizeForChat(string $text): string
	{
		return $this->reportTextNormalizer->flattenParagraphsForChat(
			$this->reportTextNormalizer->normalize($text),
		);
	}

	private function isStarterEnabled(): bool
	{
		return Loader::includeModule('bizproc') && class_exists(Starter::class) && Starter::isEnabled();
	}
}
