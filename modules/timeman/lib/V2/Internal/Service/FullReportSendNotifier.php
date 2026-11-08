<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Internal\Service;

use Bitrix\Bizproc\Starter\Dto\ContextDto;
use Bitrix\Bizproc\Starter\Enum\Scenario;
use Bitrix\Bizproc\Starter\Starter;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;
use Bitrix\Timeman\V2\Internal\Entity\FullReport\FullReport;
use Bitrix\Timeman\V2\Internal\Entity\Report\RecordReportType;
use Bitrix\Timeman\V2\Internal\Integration\Bizproc\FullReportSentTrigger;

final class FullReportSendNotifier
{
	private const SOURCE_TYPE_AI = 'AI';
	private const SOURCE_TYPE_ROBO = 'ROBO';

	public function __construct(
		private readonly ReportTextNormalizerService $reportTextNormalizer,
		private readonly ReportPeriodPhraseFormatter $reportPeriodPhraseFormatter,
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
			$fields = $this->buildEventFields($report, $senderId);

			// The most direct manager (first of the already priority-sorted recipients) is the report
			// recipient the workflow must address — the single source of truth, instead of recomputing it.
			$directManagerId = (int)(array_values($managerIds)[0] ?? 0);
			if ($directManagerId > 0)
			{
				$fields[FullReportSentTrigger::FIELD_MANAGER_ID] = $directManagerId;
			}

			Starter::getByScenario(Scenario::onEvent)
				->setContext(new ContextDto('timeman'))
				->addEvent('FullReportSentTrigger', [], $fields)
				->start()
			;
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	private function buildEventFields(FullReport $report, int $senderId): array
	{
		return [
			'initiatorUserId' => $senderId,
			FullReportSentTrigger::FIELD_USER_ID => $senderId,
			FullReportSentTrigger::FIELD_REPORT => $this->normalizeForChat((string)$report->report),
			FullReportSentTrigger::FIELD_REPORT_EXTENDED => $this->normalizeForChat((string)$report->reportExtended),
			FullReportSentTrigger::FIELD_REPORT_ID => $report->id,
			FullReportSentTrigger::FIELD_PERIOD_PHRASE => $this->reportPeriodPhraseFormatter->formatForReport($report),
			FullReportSentTrigger::FIELD_SOURCE_TYPE => $this->resolveSourceType($report),
			FullReportSentTrigger::FIELD_PERIOD_FROM => $report->dateFrom
				? date(DateTime::getFormat(), $report->dateFrom)
				: null,
			FullReportSentTrigger::FIELD_PERIOD_TO => $report->dateTo
				? date(DateTime::getFormat(), $report->dateTo)
				: null,
			FullReportSentTrigger::FIELD_EVENT_DATE_TIME => (new DateTime())->format(DateTime::getFormat()),
		];
	}

	private function resolveSourceType(FullReport $report): string
	{
		return $report->type === RecordReportType::AI_REPORT
			? self::SOURCE_TYPE_AI
			: self::SOURCE_TYPE_ROBO
		;
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
