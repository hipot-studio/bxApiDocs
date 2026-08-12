<?php
declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Generation\Step;

use Bitrix\Landing\Copilot\Generation\Step\Base\TaskStep;
use Bitrix\Landing\AI\SiteBuilder\Html\AiBlockHtmlPayloadProcessor;
use Bitrix\Landing\Copilot\Generation\GenerationException;
use Bitrix\Landing\Copilot\Generation\Scenario\ChangeAiSiteState;
use Bitrix\Landing\Copilot\Generation\Step\Helper\ChangeAiSiteActionPolicy;
use Bitrix\Landing\Copilot\Generation\Type\GenerationErrors;
use Bitrix\Landing\Internals\BlockTable;
use Bitrix\Landing\Landing;
use Bitrix\Landing\Rights;
use Bitrix\Landing\Site;

class TaskInitChangeAiSiteTargetBlocks extends TaskStep
{
	private const SKIP_THEME_HEADER_FONT_VALUE = 'Y';
	private const MESSAGE_NO_APPLICABLE_ACTIONS = 'ChangeAiSite action plan returned no applicable actions.';
	private const EVENT_TAILWIND_PRELOAD = 'onChangeAiSiteTailwindPreload';
	private const EVENT_TARGET_BLOCKS_RESOLVED = 'onChangeAiSiteTargetBlocksResolved';
	private const SCENARIO_CHANGE_AI_SITE = 'change_ai_site';
	private const EVENT_TYPE_TARGET_BLOCKS_RESOLVED = 'target_blocks_resolved';
	private const STEP_CODE_INIT_TARGET_BLOCKS = 'init_target_blocks';
	private const ACTION_ADD = 'add_block';
	private const ACTION_UPDATE = 'update_block';
	private const REASON_MOVE_SOURCE_NOT_FOUND = 'MOVE_BLOCK_SOURCE_NOT_FOUND_IN_EDIT_MODE';
	private const REASON_MOVE_TARGET_NOT_FOUND = 'MOVE_BLOCK_TARGET_NOT_FOUND_IN_EDIT_MODE';
	private const REASON_MOVE_SAME_SOURCE_AND_TARGET = 'MOVE_BLOCK_SAME_SOURCE_AND_TARGET';
	private const ACTION_DELETE = 'delete_block';
	private const ACTION_MOVE = 'move_block';

	public function execute(): bool
	{
		parent::execute();

		$actions = ChangeAiSiteState::getActions($this->generation);
		$this->assertHasActions($actions);
		$landingId = $this->resolveRequiredLandingId();

		$editModeBefore = Landing::getEditMode();
		Landing::setEditMode(true);
		try
		{
			$landing = $this->createLandingForEditMode($landingId);
			$this->setupSiteData($landingId, $landing);

			[$actions, $policySkippedIds, $policySkippedReasons] = $this->filterActionsByPolicy($actions);
			[$actions, $runtimeSkippedIds, $runtimeSkippedReasons] = $this->filterActionsByLandingState($landing, $actions);
			ChangeAiSiteState::setActions($this->generation, $actions);
			$this->sendTailwindPreloadEventIfRequired($landingId, $actions);

			[$htmlBlocks, $skippedIds, $skippedReasons] = $this->collectHtmlBlocksPayloadFromActions(
				$landing,
				$landingId,
				$actions,
			);
			$skippedIds = array_merge($policySkippedIds, $runtimeSkippedIds, $skippedIds);
			$skippedReasons = array_replace($policySkippedReasons, $runtimeSkippedReasons, $skippedReasons);
			$this->assertHasApplicableActions($actions, $htmlBlocks);
			if ($actions !== [])
			{
				$this->assertHasActionPayload($actions, $htmlBlocks);
			}

			ChangeAiSiteState::setHtmlBlocks($this->generation, $htmlBlocks);
			ChangeAiSiteState::mergeResult($this->generation, [
				'skippedIds' => $skippedIds,
				'skippedReasons' => $skippedReasons,
			]);
			$this->sendTargetBlocksResolvedEvent($landingId, $actions, $htmlBlocks);
		}
		finally
		{
			Landing::setEditMode($editModeBefore);
		}
		$this->changed = true;

		return true;
	}

	private function assertHasActions(array $actions): void
	{
		if ($actions !== [])
		{
			return;
		}

		throw new GenerationException(
			GenerationErrors::dataValidation,
			'ChangeAiSite action plan is empty.',
		);
	}

	private function assertHasApplicableActions(array $actions, array $htmlBlocks): void
	{
		if ($actions !== [] || $htmlBlocks !== [])
		{
			return;
		}

		throw new GenerationException(
			GenerationErrors::dataValidation,
			self::MESSAGE_NO_APPLICABLE_ACTIONS,
		);
	}

	private function resolveRequiredLandingId(): int
	{
		$landingId = ChangeAiSiteState::resolveLandingId($this->generation);
		if ($landingId > 0)
		{
			return $landingId;
		}

		throw new GenerationException(
			GenerationErrors::dataValidation,
			'ChangeAiSite landingId is empty.',
		);
	}

	protected function createLandingForEditMode(int $landingId): Landing
	{
		$landing = Landing::createInstance($landingId, [
			'check_permissions' => false,
		]);
		if ($landing->exist())
		{
			return $landing;
		}

		throw new GenerationException(
			GenerationErrors::dataValidation,
			'Landing does not exist for ChangeAiSite target blocks initialization.',
		);
	}

	protected function setupSiteData(int $landingId, Landing $landing): void
	{
		$this->siteData->setLandingId($landingId);
		$siteId = (int)$landing->getSiteId();
		$this->siteData->setSiteId($siteId);
		$this->ensureAiSiteThemeFontsPolicy($siteId);

		$title = (string)$landing->getTitle();
		$this->siteData->setSiteTitle($title);
		$this->siteData->setPageTitle($title);
	}

	protected function ensureAiSiteThemeFontsPolicy(int $siteId): void
	{
		if ($siteId <= 0)
		{
			return;
		}

		$rightsBefore = Rights::isOn();
		Rights::setGlobalOff();
		try
		{
			Site::saveAdditionalFields($siteId, [
				'THEMEFONTS_SKIP_H_FONT' => self::SKIP_THEME_HEADER_FONT_VALUE,
			]);
		}
		finally
		{
			if ($rightsBefore)
			{
				Rights::setGlobalOn();
			}
			else
			{
				Rights::setGlobalOff();
			}
		}
	}

	protected function collectHtmlBlocksPayloadFromActions(
		Landing $landing,
		int $landingId,
		array $actions,
	): array
	{
		$htmlBlocks = [];
		$skippedIds = [];
		$skippedReasons = [];
		$updateBlockIds = [];
		foreach ($actions as $action)
		{
			if (is_array($action) && ($action['type'] ?? '') === 'update_block')
			{
				$updateBlockIds[] = (int)($action['blockId'] ?? 0);
			}
		}

		$editableBlockMap = $this->resolveEditableBlockMap($landingId, $updateBlockIds);
		foreach ($actions as $action)
		{
			if (!is_array($action))
			{
				continue;
			}

			$type = trim((string)($action['type'] ?? ''));
			if ($type === 'add_block')
			{
				$htmlBlocks[] = $this->buildHtmlBlockPayload(
					0,
					0,
					'',
					$action,
				);
				continue;
			}

			if ($type !== 'update_block')
			{
				continue;
			}

			$sourceBlockId = (int)($action['blockId'] ?? 0);
			if ($sourceBlockId <= 0)
			{
				continue;
			}

			$editableBlockId = (int)($editableBlockMap[$sourceBlockId] ?? 0);
			$block = $editableBlockId > 0 ? $landing->getBlockById($editableBlockId) : null;
			if (!$block)
			{
				$this->markSkippedBlock($sourceBlockId, $skippedIds, $skippedReasons);
				continue;
			}

			$htmlBlocks[] = $this->buildHtmlBlockPayload(
				$editableBlockId,
				$sourceBlockId,
				(string)$block->getContent(),
				$action,
			);
		}

		return [$htmlBlocks, $skippedIds, $skippedReasons];
	}

	protected function sendTailwindPreloadEventIfRequired(int $landingId, array $actions): void
	{
		if (!$this->hasTailwindPreloadAction($actions))
		{
			return;
		}

		$this->getEvent()
			->setLandingId($landingId)
			->send(self::EVENT_TAILWIND_PRELOAD)
		;
	}

	protected function sendTargetBlocksResolvedEvent(int $landingId, array $actions, array $htmlBlocks): void
	{
		$result = ChangeAiSiteState::getResult($this->generation);
		$this->getEvent()
			->setLandingId($landingId)
			->send(self::EVENT_TARGET_BLOCKS_RESOLVED, [
				'scenario' => self::SCENARIO_CHANGE_AI_SITE,
				'eventType' => self::EVENT_TYPE_TARGET_BLOCKS_RESOLVED,
				'stepCode' => self::STEP_CODE_INIT_TARGET_BLOCKS,
				'actions' => $this->buildEditorActionsPayload($actions),
				'htmlBlocks' => $this->buildEditorHtmlBlocksPayload($htmlBlocks),
				'skippedIds' => $this->normalizeIds($result['skippedIds'] ?? []),
				'skippedReasons' => is_array($result['skippedReasons'] ?? null) ? $result['skippedReasons'] : [],
			])
		;
	}

	protected function buildEditorActionsPayload(array $actions): array
	{
		$payload = [];
		foreach ($actions as $action)
		{
			if (!is_array($action))
			{
				continue;
			}

			$payload[] = [
				'actionId' => trim((string)($action['actionId'] ?? '')),
				'type' => trim((string)($action['type'] ?? '')),
				'blockId' => (int)($action['blockId'] ?? 0),
				'sourceBlockId' => (int)($action['sourceBlockId'] ?? $action['blockId'] ?? 0),
				'placement' => $this->normalizeEditorPlacement($action['placement'] ?? null),
				'editMode' => ChangeAiSiteState::normalizeEditMode($action['editMode'] ?? ''),
			];
		}

		return $payload;
	}

	protected function buildEditorHtmlBlocksPayload(array $htmlBlocks): array
	{
		$payload = [];
		foreach ($htmlBlocks as $htmlBlock)
		{
			if (!is_array($htmlBlock))
			{
				continue;
			}

			$payload[] = [
				'actionId' => trim((string)($htmlBlock['actionId'] ?? '')),
				'actionType' => trim((string)($htmlBlock['actionType'] ?? '')),
				'blockId' => (int)($htmlBlock['blockId'] ?? 0),
				'sourceBlockId' => (int)($htmlBlock['sourceBlockId'] ?? 0),
				'placement' => $this->normalizeEditorPlacement($htmlBlock['placement'] ?? null),
				'editMode' => ChangeAiSiteState::normalizeEditMode($htmlBlock['editMode'] ?? ''),
			];
		}

		return $payload;
	}

	protected function normalizeEditorPlacement(mixed $placement): ?array
	{
		if (!is_array($placement))
		{
			return null;
		}

		$mode = trim((string)($placement['mode'] ?? ''));
		$blockId = (int)($placement['blockId'] ?? 0);
		if ($mode === '' && $blockId <= 0)
		{
			return null;
		}

		return [
			'mode' => $mode,
			'blockId' => $blockId,
		];
	}

	protected function hasTailwindPreloadAction(array $actions): bool
	{
		foreach ($actions as $action)
		{
			if (!is_array($action))
			{
				continue;
			}

			$type = trim((string)($action['type'] ?? ''));
			if ($type === self::ACTION_ADD || $type === self::ACTION_UPDATE)
			{
				return true;
			}
		}

		return false;
	}

	protected function filterActionsByPolicy(array $actions): array
	{
		$candidateMap = $this->getCandidatesMap();
		if ($candidateMap === [])
		{
			return [$actions, [], []];
		}

		$filtered = [];
		$skippedIds = [];
		$skippedReasons = [];
		foreach ($actions as $action)
		{
			if (!is_array($action))
			{
				continue;
			}

			$type = trim((string)($action['type'] ?? ''));
			if ($type === 'add_block')
			{
				$filtered[] = $action;
				continue;
			}

			$blockId = (int)($action['blockId'] ?? 0);
			$policyResult = $this->resolveActionPolicy($type, $blockId, $candidateMap);
			if ($policyResult['allowed'])
			{
				$filtered[] = $action;
				continue;
			}

			if ($blockId > 0)
			{
				$skippedIds[] = $blockId;
				$skippedReasons[(string)$blockId] = $policyResult['reason'];
			}
		}

		return [$filtered, $this->normalizeIds($skippedIds), $skippedReasons];
	}

	protected function filterActionsByLandingState(Landing $landing, array $actions): array
	{
		$availableBlockIds = $this->getLandingBlockIds($landing);
		if ($availableBlockIds === [])
		{
			return [$actions, [], []];
		}

		$availableBlockMap = array_fill_keys($availableBlockIds, true);
		$filtered = [];
		$skippedIds = [];
		$skippedReasons = [];
		foreach ($actions as $action)
		{
			if (!is_array($action) || ($action['type'] ?? '') !== self::ACTION_MOVE)
			{
				$filtered[] = $action;
				continue;
			}

			$sourceBlockId = (int)($action['blockId'] ?? 0);
			if ($sourceBlockId <= 0 || !isset($availableBlockMap[$sourceBlockId]))
			{
				$this->markActionSkipped(
					$sourceBlockId,
					$skippedIds,
					$skippedReasons,
					self::REASON_MOVE_SOURCE_NOT_FOUND,
				);
				continue;
			}

			$placement = is_array($action['placement'] ?? null) ? $action['placement'] : [];
			$mode = trim((string)($placement['mode'] ?? ''));
			$targetBlockId = (int)($placement['blockId'] ?? 0);
			if (in_array($mode, ['before', 'after'], true))
			{
				if ($targetBlockId === $sourceBlockId)
				{
					$this->markActionSkipped(
						$sourceBlockId,
						$skippedIds,
						$skippedReasons,
						self::REASON_MOVE_SAME_SOURCE_AND_TARGET,
					);
					continue;
				}

				if ($targetBlockId <= 0 || !isset($availableBlockMap[$targetBlockId]))
				{
					$this->markActionSkipped(
						$sourceBlockId,
						$skippedIds,
						$skippedReasons,
						self::REASON_MOVE_TARGET_NOT_FOUND,
					);
					continue;
				}
			}

			$filtered[] = $action;
		}

		return [$filtered, $this->normalizeIds($skippedIds), $skippedReasons];
	}

	protected function resolveActionPolicy(string $actionType, int $blockId, array $candidateMap): array
	{
		if ($blockId <= 0 || !isset($candidateMap[$blockId]))
		{
			return [
				'allowed' => false,
				'reason' => ChangeAiSiteActionPolicy::REASON_NOT_AVAILABLE,
			];
		}

		return (new ChangeAiSiteActionPolicy())->validateAction($actionType, $candidateMap[$blockId]);
	}

	private function getCandidatesMap(): array
	{
		$map = [];
		foreach (ChangeAiSiteState::getCandidates($this->generation) as $candidate)
		{
			if (!is_array($candidate))
			{
				continue;
			}

			$id = (int)($candidate['id'] ?? 0);
			if ($id > 0)
			{
				$map[$id] = $candidate;
			}
		}

		return $map;
	}

	/**
	 * @return list<int>
	 */
	private function getLandingBlockIds(Landing $landing): array
	{
		$ids = [];
		$blocks = $landing->getBlocks();
		if (!is_iterable($blocks))
		{
			return [];
		}

		foreach ($blocks as $block)
		{
			if (!is_object($block) || !method_exists($block, 'getId'))
			{
				continue;
			}

			$blockId = (int)$block->getId();
			if ($blockId > 0)
			{
				$ids[] = $blockId;
			}
		}

		return array_values(array_unique($ids));
	}

	protected function resolveEditableBlockMap(int $landingId, array $sourceBlockIds): array
	{
		$sourceBlockIds = $this->normalizeIds($sourceBlockIds);
		if ($landingId <= 0 || $sourceBlockIds === [])
		{
			return [];
		}

		$editableRows = $this->loadResolutionRows($landingId, $sourceBlockIds, true, true);
		$fallbackRows = $this->loadResolutionRows($landingId, $sourceBlockIds, false, false);

		return $this->buildEditableBlockMap($sourceBlockIds, $editableRows, $fallbackRows);
	}

	protected function loadResolutionRows(
		int $landingId,
		array $sourceBlockIds,
		bool $includeParentId,
		bool $onlyEditMode,
	): array
	{
		if ($landingId <= 0 || $sourceBlockIds === [])
		{
			return [];
		}

		$filter = [
			'=LID' => $landingId,
			'=DELETED' => 'N',
			[
				'LOGIC' => 'OR',
				['=ID' => $sourceBlockIds],
			],
		];
		if ($includeParentId)
		{
			$filter[2][] = ['=PARENT_ID' => $sourceBlockIds];
		}
		if ($onlyEditMode)
		{
			$filter['=PUBLIC'] = 'N';
		}

		$rows = [];
		$res = BlockTable::getList([
			'select' => ['ID', 'PARENT_ID'],
			'filter' => $filter,
			'order' => ['ID' => 'ASC'],
		]);
		while ($row = $res->fetch())
		{
			$rows[] = $row;
		}

		return $rows;
	}

	private function buildEditableBlockMap(array $sourceBlockIds, array $editableRows, array $fallbackRows): array
	{
		$editableById = [];
		$editableByParentId = [];
		$fallbackById = [];

		foreach ($editableRows as $row)
		{
			$id = (int)($row['ID'] ?? 0);
			$parentId = (int)($row['PARENT_ID'] ?? 0);
			if ($id > 0 && !isset($editableById[$id]))
			{
				$editableById[$id] = $id;
			}
			if ($parentId > 0 && !isset($editableByParentId[$parentId]))
			{
				$editableByParentId[$parentId] = $id;
			}
		}

		foreach ($fallbackRows as $row)
		{
			$id = (int)($row['ID'] ?? 0);
			if ($id > 0 && !isset($fallbackById[$id]))
			{
				$fallbackById[$id] = $id;
			}
		}

		$map = [];
		foreach ($sourceBlockIds as $sourceBlockId)
		{
			$sourceBlockId = (int)$sourceBlockId;
			$editableBlockId =
				$editableById[$sourceBlockId]
				?? $editableByParentId[$sourceBlockId]
				?? $fallbackById[$sourceBlockId]
				?? 0
			;
			if ($editableBlockId > 0)
			{
				$map[$sourceBlockId] = $editableBlockId;
			}
		}

		return $map;
	}

	private function normalizeIds(array $ids): array
	{
		$normalized = array_filter(
			array_map('intval', $ids),
			static fn(int $id): bool => $id > 0,
		);

		return array_values(array_unique($normalized));
	}

	private function markSkippedBlock(int $sourceBlockId, array &$skippedIds, array &$skippedReasons): void
	{
		$skippedIds[] = $sourceBlockId;
		$skippedReasons[(string)$sourceBlockId] = 'BLOCK_NOT_FOUND_IN_EDIT_MODE';
	}

	private function markActionSkipped(
		int $blockId,
		array &$skippedIds,
		array &$skippedReasons,
		string $reason,
	): void
	{
		if ($blockId > 0)
		{
			$skippedIds[] = $blockId;
			$skippedReasons[(string)$blockId] = $reason;
		}
	}

	private function buildHtmlBlockPayload(
		int $blockId,
		int $sourceBlockId,
		string $originalHtml,
		array $action = [],
	): array
	{
		return [
			'actionId' => trim((string)($action['actionId'] ?? '')),
			'actionType' => trim((string)($action['type'] ?? ($blockId > 0 ? 'update_block' : ''))),
			'blockId' => $blockId,
			'sourceBlockId' => $sourceBlockId,
			'originalHtml' => $originalHtml,
			'generatedHtml' => '',
			'aiMeta' => AiBlockHtmlPayloadProcessor::getEmptyAiMeta(),
			'applyStatus' => 'pending',
			'instruction' => trim((string)($action['instruction'] ?? '')),
			'editMode' => $this->resolveEditMode($action),
			'placement' => is_array($action['placement'] ?? null) ? $action['placement'] : [],
		];
	}

	private function resolveEditMode(array $action): string
	{
		$editMode = trim((string)($action['editMode'] ?? ''));
		if (in_array($editMode, [
			ChangeAiSiteState::EDIT_MODE_TARGETED,
			ChangeAiSiteState::EDIT_MODE_BALANCED,
			ChangeAiSiteState::EDIT_MODE_GLOBAL,
		], true))
		{
			return $editMode;
		}

		return ChangeAiSiteState::resolveActionEditMode(
			$this->generation,
			(string)($action['actionId'] ?? ''),
			(int)($action['blockId'] ?? 0),
		);
	}

	private function assertHasActionPayload(array $actions, array $htmlBlocks): void
	{
		if ($htmlBlocks !== [])
		{
			return;
		}

		foreach ($actions as $action)
		{
			if (
				is_array($action)
				&& in_array(($action['type'] ?? ''), [self::ACTION_DELETE, self::ACTION_MOVE], true)
			)
			{
				continue;
			}

			throw new GenerationException(
				GenerationErrors::dataValidation,
				'ChangeAiSite target actions are not available in edit mode.',
			);
		}
	}
}
