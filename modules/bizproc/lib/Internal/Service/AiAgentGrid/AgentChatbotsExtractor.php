<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\AiAgentGrid;

use Bitrix\Bizproc\Integration\ImBot\BizprocBot;
use Bitrix\Bizproc\Public\Entity\Document\Workflow as WorkflowDocument;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable;
use Bitrix\Im\Model\BotTable;
use Bitrix\Main\Loader;
use CBPActivity;
use CBPDocument;
use CBPRuntime;
use CBPWorkflow;
use CBPWorkflowTemplateLoader;
use Bitrix\ImBot\Bot\OpenLinesBizprocBot;

class AgentChatbotsExtractor
{
	public const KIND_BIZPROC = 'bizproc';
	public const KIND_OPENLINES = 'openlines';

	public const OPENLINES_BOT_CLASS = OpenLinesBizprocBot::class;

	private const ACTIVITY_TYPE_BIZPROC_BOT = 'ImBotCreateBotActivity';
	private const ACTIVITY_TYPE_OPENLINES_BOT = 'ImOpenLinesBotSettingsActivity';

	/**
	 * @var array<string, true>
	 */
	private const BOT_ACTIVITY_TYPES = [
		self::ACTIVITY_TYPE_BIZPROC_BOT => true,
		self::ACTIVITY_TYPE_OPENLINES_BOT => true,
	];

	private const BATCH_SIZE = 50;
	private const BOT_LOOKUP_CHUNK_SIZE = 500;

	/**
	 * @param list<int> $templateIds
	 * @return array{bizproc: list<string>, openlines: list<string>}
	 */
	public function extractBotCodes(array $templateIds): array
	{
		$bizproc = [];
		$openlines = [];

		foreach ($this->streamBotCodes($templateIds) as $codes)
		{
			foreach ($codes[self::KIND_BIZPROC] as $code)
			{
				$bizproc[$code] = $code;
			}
			foreach ($codes[self::KIND_OPENLINES] as $code)
			{
				$openlines[$code] = $code;
			}
		}

		return [
			self::KIND_BIZPROC => array_values($bizproc),
			self::KIND_OPENLINES => array_values($openlines),
		];
	}

	/**
	 * @param list<int> $templateIds
	 * @return array{bizproc: list<int>, openlines: list<int>}
	 */
	public function getCreatedBotIds(array $templateIds): array
	{
		$codes = $this->extractBotCodes($templateIds);

		return [
			self::KIND_BIZPROC => $this->resolveBotIdsByClass(BizprocBot::class, $codes[self::KIND_BIZPROC]),
			self::KIND_OPENLINES => $this->resolveBotIdsByClass(self::OPENLINES_BOT_CLASS, $codes[self::KIND_OPENLINES]),
		];
	}

	/**
	 * Streams per-template resolved bot codes. Iterator yields one batch per chunk,
	 * so memory stays bounded even when the caller deletes hundreds of agents at once.
	 *
	 * @param list<int> $templateIds
	 * @return \Generator<array{bizproc: list<string>, openlines: list<string>}>
	 */
	private function streamBotCodes(array $templateIds): \Generator
	{
		$validIds = $this->sanitizeIds($templateIds);
		if (empty($validIds))
		{
			return;
		}

		foreach (array_chunk($validIds, self::BATCH_SIZE) as $chunk)
		{
			$rows = $this->loadTemplateRows($chunk);
			foreach ($rows as $templateId => $row)
			{
				$rawTemplate = is_array($row['TEMPLATE'] ?? null) ? $row['TEMPLATE'] : null;
				if ($rawTemplate === null || !$this->containsBotActivity($rawTemplate))
				{
					// Cheap pre-filter: skip templates that don't have create-bot activities at all
					// so we don't build a full in-memory workflow for them.
					continue;
				}

				yield $this->extractCodesFromRow($templateId, $row);
			}
		}
	}

	/**
	 * @param list<int> $ids
	 * @return list<int>
	 */
	private function sanitizeIds(array $ids): array
	{
		$seen = [];
		foreach ($ids as $id)
		{
			$id = (int)$id;
			if ($id > 0)
			{
				$seen[$id] = $id;
			}
		}

		return array_values($seen);
	}

	/**
	 * @param list<int> $templateIds
	 * @return array<int, array{ID: int, TEMPLATE: array, VARIABLES: array, PARAMETERS: array}>
	 */
	private function loadTemplateRows(array $templateIds): array
	{
		$res = WorkflowTemplateTable::query()
			->setSelect(['ID', 'TEMPLATE', 'VARIABLES', 'PARAMETERS'])
			->whereIn('ID', $templateIds)
			->exec()
		;

		$result = [];
		while ($row = $res->fetch())
		{
			$id = (int)($row['ID'] ?? 0);
			if ($id <= 0)
			{
				continue;
			}

			$result[$id] = [
				'ID' => $id,
				'TEMPLATE' => is_array($row['TEMPLATE'] ?? null) ? $row['TEMPLATE'] : [],
				'VARIABLES' => is_array($row['VARIABLES'] ?? null) ? $row['VARIABLES'] : [],
				'PARAMETERS' => is_array($row['PARAMETERS'] ?? null) ? $row['PARAMETERS'] : [],
			];
		}

		return $result;
	}

	/**
	 * Plain DFS over the raw TEMPLATE array with an early-exit on the first matching
	 * activity. Much cheaper than building a full CBPWorkflow tree.
	 */
	private function containsBotActivity(array $template): bool
	{
		$stack = $template;
		while ($stack)
		{
			$node = array_pop($stack);
			if (!is_array($node))
			{
				continue;
			}

			$type = $node['Type'] ?? null;
			if (is_string($type) && isset(self::BOT_ACTIVITY_TYPES[$type]))
			{
				return true;
			}

			$children = $node['Children'] ?? null;
			if (is_array($children))
			{
				foreach ($children as $child)
				{
					$stack[] = $child;
				}
			}
		}

		return false;
	}

	/**
	 * @return array{bizproc: list<string>, openlines: list<string>}
	 */
	private function extractCodesFromRow(int $templateId, array $row): array
	{
		$empty = [self::KIND_BIZPROC => [], self::KIND_OPENLINES => []];

		try
		{
			$rootActivity = $this->buildResolvedRootActivity($templateId, $row);
		}
		catch (\Throwable)
		{
			return $empty;
		}

		if ($rootActivity === null)
		{
			return $empty;
		}

		$bizproc = [];
		$openlines = [];
		foreach ($rootActivity->walkRecursive() as $activity)
		{
			if (!$activity instanceof CBPActivity)
			{
				continue;
			}

			$kind = $this->detectBotKind($this->getActivityType($activity));
			if ($kind === null)
			{
				continue;
			}

			$code = $this->resolveBotCodeProperty($activity);
			if ($code === '')
			{
				continue;
			}

			if ($kind === self::KIND_BIZPROC)
			{
				$bizproc[$code] = $code;
			}
			else
			{
				$openlines[$code] = $code;
			}
		}

		return [
			self::KIND_BIZPROC => array_values($bizproc),
			self::KIND_OPENLINES => array_values($openlines),
		];
	}

	/**
	 * Builds an in-memory workflow tree using {@see CBPWorkflowTemplateLoader::loadWorkflowFromArray()}
	 * so we don't hit DB again — the row is already pre-fetched in {@see loadTemplateRows()}.
	 *
	 * Instantiates {@see CBPWorkflow} directly (NOT via {@see CBPRuntime::createWorkflow()})
	 * to skip {@see \CBPStateService::AddWorkflow()} — no persistence side effects.
	 */
	private function buildResolvedRootActivity(int $templateId, array $row): ?CBPActivity
	{
		$loader = CBPWorkflowTemplateLoader::getLoader();
		[$rootActivity, $variablesTypes, $parametersTypes] = $loader->loadWorkflowFromArray($row);
		if (!$rootActivity instanceof CBPActivity)
		{
			return null;
		}

		$runtime = CBPRuntime::getRuntime(true);
		$workflow = new CBPWorkflow(CBPRuntime::generateWorkflowId(), $runtime);

		$documentId = WorkflowDocument::getComplexId((string)$templateId);
		$documentType = WorkflowDocument::getComplexType();

		$workflow->initialize(
			$rootActivity,
			$documentId,
			[CBPDocument::PARAM_DOCUMENT_TYPE => $documentType],
			is_array($variablesTypes) ? $variablesTypes : [],
			is_array($parametersTypes) ? $parametersTypes : [],
			$templateId,
		);

		return $rootActivity;
	}

	private function getActivityType(CBPActivity $activity): string
	{
		$class = $activity::class;

		return str_starts_with($class, 'CBP') ? substr($class, 3) : $class;
	}

	private function detectBotKind(string $activityType): ?string
	{
		return match ($activityType)
		{
			self::ACTIVITY_TYPE_BIZPROC_BOT => self::KIND_BIZPROC,
			self::ACTIVITY_TYPE_OPENLINES_BOT => self::KIND_OPENLINES,
			default => null,
		};
	}

	private function resolveBotCodeProperty(CBPActivity $activity): string
	{
		try
		{
			$value = $activity->botCode ?? null;
		}
		catch (\Throwable)
		{
			return '';
		}

		if (is_array($value))
		{
			$value = reset($value);
		}

		if (!is_scalar($value))
		{
			return '';
		}

		return trim((string)$value);
	}

	/**
	 * @param list<string> $codes
	 * @return list<int>
	 */
	private function resolveBotIdsByClass(string $botClass, array $codes): array
	{
		if (empty($codes) || !Loader::includeModule('im') || !Loader::includeModule('imbot'))
		{
			return [];
		}

		$normalizedCodes = array_values(array_unique(array_map('strval', $codes)));
		$class = ltrim($botClass, '\\');

		$ids = [];
		foreach (array_chunk($normalizedCodes, self::BOT_LOOKUP_CHUNK_SIZE) as $chunk)
		{
			$res = BotTable::query()
				->setSelect(['BOT_ID'])
				->where('CLASS', $class)
				->whereIn('CODE', $chunk)
				->exec()
			;

			while ($row = $res->fetch())
			{
				$botId = (int)($row['BOT_ID'] ?? 0);
				if ($botId > 0)
				{
					$ids[$botId] = $botId;
				}
			}
		}

		return array_values($ids);
	}
}
