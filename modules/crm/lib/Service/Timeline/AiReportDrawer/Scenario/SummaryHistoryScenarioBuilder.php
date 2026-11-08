<?php

namespace Bitrix\Crm\Service\Timeline\AiReportDrawer\Scenario;

use Bitrix\Crm\Activity\Provider\Call;
use Bitrix\Crm\Activity\Provider\Email;
use Bitrix\Crm\Integration\AI\AiMessageProvider;
use Bitrix\Crm\Service\Timeline\AiReportDrawer\Dto\ActivityContext;
use Bitrix\Crm\Service\Timeline\AiReportDrawer\Presentation\PresentationResolver;
use Bitrix\Crm\Service\Timeline\AiReportDrawer\Support\ActivityBadgeCleaner;
use Bitrix\Crm\Service\Timeline\AiReportDrawer\Support\CallRecordDataProvider;
use Bitrix\Crm\Service\Timeline\AiReportDrawer\Support\MessageProvider;
use Bitrix\Crm\Service\Timeline\AiReportDrawer\Support\SettingsProvider;
use Bitrix\Crm\Service\Timeline\AiReportDrawer\Support\SummaryDataProvider;
use Bitrix\Main\Result;

final class SummaryHistoryScenarioBuilder implements ScenarioBuilderInterface
{
	private const SUMMARY_BLOCK_ID = 'summary';

	public function __construct(
		private readonly PresentationResolver $presentationResolver,
		private readonly SummaryDataProvider $summaryDataProvider,
		private readonly CallRecordDataProvider $callRecordDataProvider,
		private readonly ActivityBadgeCleaner $activityBadgeCleaner,
		private readonly SettingsProvider $settingsProvider,
	)
	{
	}

	public function getCode(): ScenarioCode
	{
		return ScenarioCode::SUMMARY_HISTORY;
	}

	public function build(ActivityContext $context): Result
	{
		$result = new Result();
		$isEmail = ($context->activity['PROVIDER_ID'] ?? null) === Email::getId();
		$summaryHistory = $this->summaryDataProvider->loadSummaryHistory(
			$context->request->activityId,
			$isEmail ? $context->request->ownerTypeId : null,
			$isEmail ? $context->request->ownerId : null,
		);
		$summaryHistory = $this->filterVisibleSummaryHistory($summaryHistory);
		$selectedJobId = $this->resolveSelectedJobId($summaryHistory, $context->request->jobId);
		if ($selectedJobId === null)
		{
			return $result->setData(['drawerData' => null]);
		}

		$blocks = $this->prepareSummaryHistoryBlocks($summaryHistory, $selectedJobId);
		if (empty($blocks))
		{
			return $result->setData(['drawerData' => null]);
		}

		$record = null;
		if (($context->activity['PROVIDER_ID'] ?? null) === Call::getId())
		{
			$record = $this->callRecordDataProvider->load($context->activity);
			if ($record === null)
			{
				return $result->setData(['drawerData' => null]);
			}
		}

		$drawerData = [
			'title' => $this->prepareSummaryHistoryTitle($summaryHistory),
			'subtitle' => $this->presentationResolver->buildSubtitle($context),
			'settings' => $this->prepareSettings(),
			'record' => $record,
			'infoPopup' => $this->presentationResolver->buildInfoPopup($context),
			'anchors' => $this->prepareSummaryHistoryAnchors($blocks),
			'assessmentBlocks' => [],
			'assessmentSetting' => null,
			'blocks' => $blocks,
			'aiDisclaimer' => AiMessageProvider::getAiDisclaimerData()['html'] ?? null,
		];

		$this->activityBadgeCleaner->removeByOwner($context);

		return $result->setData(['drawerData' => $drawerData]);
	}

	private function prepareSettings(): array
	{
		return array_values(array_filter([
			$this->settingsProvider->getButton(SettingsProvider::SHARE_SLIDER),
		]));
	}

	private function filterVisibleSummaryHistory(array $summaryHistory): array
	{
		return array_values(array_filter(
			$summaryHistory,
			static function (array $summary): bool {
				$jobId = (int)($summary['jobId'] ?? 0);
				$text = (string)($summary['summary'] ?? '');

				return $jobId > 0 && $text !== '';
			},
		));
	}

	private function resolveSelectedJobId(array $summaryHistory, ?int $requestedJobId): ?int
	{
		if (empty($summaryHistory))
		{
			return null;
		}

		if ($requestedJobId === null)
		{
			$jobId = (int)($summaryHistory[0]['jobId'] ?? 0);

			return $jobId > 0 ? $jobId : null;
		}

		foreach ($summaryHistory as $summary)
		{
			$jobId = (int)($summary['jobId'] ?? 0);
			if ($jobId === $requestedJobId)
			{
				return $jobId;
			}
		}

		return null;
	}

	private function prepareSummaryHistoryBlocks(array $summaryHistory, int $selectedJobId): array
	{
		$blocks = [];

		foreach ($summaryHistory as $summary)
		{
			$text = (string)($summary['summary'] ?? '');
			if ($text === '')
			{
				continue;
			}

			$jobId = (int)($summary['jobId'] ?? 0);
			if ($jobId <= 0)
			{
				continue;
			}

			$blocks[] = [
				'jobId' => $jobId,
				'blockId' => self::SUMMARY_BLOCK_ID . '-' . $jobId,
				'blockType' => self::SUMMARY_BLOCK_ID,
				'title' => MessageProvider::getSummaryBlockTitle(),
				'text' => $text,
				'createdAt' => is_numeric($summary['createdAt'] ?? null) ? (int)$summary['createdAt'] : null,
				'aiLanguage' => is_scalar($summary['aiLanguage'] ?? null) ? (string)$summary['aiLanguage'] : null,
				'minimized' => ($jobId !== $selectedJobId),
			];
		}

		return $blocks;
	}

	private function prepareSummaryHistoryTitle(array $summaryHistory): string
	{
		$latestTheme = (string)($summaryHistory[0]['theme'] ?? '');
		if ($latestTheme !== '')
		{
			return $latestTheme;
		}

		return MessageProvider::getSummaryBlockTitle();
	}

	private function prepareSummaryHistoryAnchors(array $blocks): array
	{
		if (empty($blocks))
		{
			return [];
		}

		$activeBlock = $blocks[0];
		foreach ($blocks as $block)
		{
			if (($block['minimized'] ?? true) === false)
			{
				$activeBlock = $block;
				break;
			}
		}

		return [[
			'text' => MessageProvider::getSummaryBlockTitle(),
			'isActive' => true,
			'blockId' => (string)($activeBlock['blockId'] ?? self::SUMMARY_BLOCK_ID),
		]];
	}
}
