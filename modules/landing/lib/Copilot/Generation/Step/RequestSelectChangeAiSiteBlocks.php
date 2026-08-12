<?php
declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Generation\Step;

use Bitrix\Landing\Copilot\Generation\Step\Request\RequestSingle;
use Bitrix\Landing\AI\SiteBuilder\Prompt\PromptCodeCatalog;
use Bitrix\Landing\Copilot\Connector\AI;
use Bitrix\Landing\Copilot\Connector\AI\Prompt;
use Bitrix\Landing\Copilot\Connector\Schema;
use Bitrix\Landing\Copilot\Data\Block\Operator;
use Bitrix\Landing\Copilot\Data\Site;
use Bitrix\Landing\Copilot\Generation\GenerationException;
use Bitrix\Landing\Copilot\Generation\Log;
use Bitrix\Landing\Copilot\Generation\Scenario\ChangeAiSiteState;
use Bitrix\Landing\Copilot\Generation\Step\Helper\ChangeAiSiteActionPolicy;
use Bitrix\Landing\Copilot\Generation\Step\Helper\ChangeAiSiteStyleContextExtractor;
use Bitrix\Landing\Copilot\Generation\Type\GenerationErrors;
use Bitrix\Landing\Internals\BlockTable;
use Bitrix\Landing\Copilot\Generation\Type\RequestQuotaDto;
use Bitrix\Landing\Metrika;
use Bitrix\Landing\Landing;
use Bitrix\Main\Web\Json;

class RequestSelectChangeAiSiteBlocks extends RequestSingle
{
	private const DEBUG_MODE_AI = 'ai';
	private const DEBUG_MODE_ERROR = 'error';
	private const DEBUG_REASON_SELECTION_FAILED = 'Block selection request failed.';
	private const SKIPPED_REASON_NOT_AVAILABLE = 'BLOCK_NOT_AVAILABLE_FOR_CHANGE';
	private const SKIPPED_REASON_MOVE_TARGET_NOT_FOUND = 'MOVE_BLOCK_TARGET_NOT_FOUND';
	private const SKIPPED_REASON_MOVE_SAME_SOURCE_AND_TARGET = 'MOVE_BLOCK_SAME_SOURCE_AND_TARGET';
	private const SKIPPED_REASON_MOVE_SOURCE_DELETED_IN_PLAN = 'MOVE_BLOCK_SOURCE_DELETED_IN_PLAN';
	private const SKIPPED_REASON_MOVE_TARGET_DELETED_IN_PLAN = 'MOVE_BLOCK_TARGET_DELETED_IN_PLAN';
	private const EMPTY_JSON = '[]';
	private const ACTION_UPDATE = 'update_block';
	private const ACTION_ADD = 'add_block';
	private const ACTION_DELETE = 'delete_block';
	private const ACTION_MOVE = 'move_block';

	public function __construct()
	{
		parent::__construct();
		if (class_exists(self::getConnectorClass()))
		{
			$this->connector = new (self::getConnectorClass())();
		}
	}

	public static function getConnectorClass(): string
	{
		return AI\CreateAiSiteText::class;
	}

	public static function getRequestQuota(Site $siteData): ?RequestQuotaDto
	{
		return new RequestQuotaDto(self::getConnectorClass(), 1);
	}

	public function getAnalyticEvent(): ?Metrika\Events
	{
		return Metrika\Events::dataGeneration;
	}

	public function execute(): bool
	{
		try
		{
			return parent::execute();
		}
		catch (GenerationException $exception)
		{
			$this->setSelectionDebug(self::DEBUG_MODE_ERROR, self::DEBUG_REASON_SELECTION_FAILED);

			throw $exception;
		}
	}

	protected function getPrompt(): Prompt
	{
		$candidates = $this->collectCandidates();
		ChangeAiSiteState::setCandidates($this->generation, $candidates);

		$candidatesJson = $this->encodeJson($candidates);
		$userInput = ChangeAiSiteState::resolveRawInput($this->generation);
		$markers = ChangeAiSiteState::buildPromptMarkers([
			'{{user_input}}' => $userInput,
			'{{candidates_json}}' => $candidatesJson,
		]);

		$prompt = new Prompt($this->resolvePromptCode());
		$prompt->setMarkers($markers);
		$prompt->setSchema(new Schema\ChangeAiSiteActionPlan());

		return $prompt;
	}

	private function resolvePromptCode(): string
	{
		return PromptCodeCatalog::CHANGE_AI_SITE_SELECT_BLOCKS;
	}

	protected function getRequestLogDataType(): string
	{
		return 'AI request: ChangeAiSite action plan';
	}

	protected function applyResponse(): bool
	{
		$actionPlan = $this->extractActionPlan($this->request->getResult());
		$candidateMap = $this->getCandidatesMapOrCollect();
		$validated = $this->validateActionPlan($actionPlan['actions'], $candidateMap);
		$actions = $validated['actions'];

		$this->assertHasValidActions($actions);

		if (Log::isEnabled())
		{
			(new Log($this->generation->getId()))->addResponse(
				(int)$this->stepId,
				'AI response: ChangeAiSite action plan',
				$this->request->getResult(),
			);
		}

		$this->saveActionPlanResult(
			$actions,
			$validated['invalid'],
			$validated['invalidReasons'] ?? [],
			(string)$actionPlan['globalConstraints'],
			(string)$actionPlan['imageStyleBrief'],
			(string)$actionPlan['designBrief'],
		);
		$this->setSelectionDebug(self::DEBUG_MODE_AI);
		$this->changed = true;

		return true;
	}

	public function verifyResponse(): void
	{
		$this->extractActionPlan($this->request->getResult());
	}

	/**
	 * @return array<int, array{id: int, code: string, anchor: string, summary: string}>
	 */
	private function collectCandidates(): array
	{
		$landingId = $this->resolveRequiredLandingId();

		$landing = Landing::createInstance($landingId, [
			'check_permissions' => false,
		]);
		if (!$landing->exist())
		{
			throw new GenerationException(
				GenerationErrors::dataValidation,
				'Landing does not exist for ChangeAiSite.',
			);
		}

		return $this->collectLandingCandidates($landingId) ?: $this->collectTableCandidates($landingId);
	}

	private function extractBlockSummary(string $content): string
	{
		$text = strip_tags($content);
		$text = preg_replace('/\s+/u', ' ', $text) ?: $text;
		$text = trim($text);
		if ($text === '')
		{
			return '';
		}

		if (mb_strlen($text) > 280)
		{
			$text = rtrim(mb_substr($text, 0, 279)) . '…';
		}

		return $text;
	}

	private function encodeJson(array $value): string
	{
		try
		{
			$json = Json::encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

			return is_string($json) ? $json : self::EMPTY_JSON;
		}
		catch (\Throwable)
		{
			return self::EMPTY_JSON;
		}
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

	private function extractActionPlan(mixed $result): array
	{
		if (!is_array($result))
		{
			return [
				'actions' => [],
				'globalConstraints' => '',
				'imageStyleBrief' => '',
				'designBrief' => '',
			];
		}

		$actions = [];
		foreach (is_array($result['actions'] ?? null) ? $result['actions'] : [] as $action)
		{
			if (!is_array($action))
			{
				continue;
			}

			$placement = is_array($action['placement'] ?? null) ? $action['placement'] : [];
			$actions[] = [
				'actionType' => (string)($action['actionType'] ?? $action['type'] ?? ''),
				'blockId' => (int)($action['blockId'] ?? 0),
				'placementMode' => (string)($action['placementMode'] ?? ($placement['mode'] ?? '')),
				'placementBlockId' => (int)($action['placementBlockId'] ?? ($placement['blockId'] ?? 0)),
				'instruction' => trim((string)($action['instruction'] ?? '')),
				'editMode' => $this->normalizeEditMode($action['editMode'] ?? $action['mode'] ?? ''),
			];
		}

		return [
			'actions' => $actions,
			'globalConstraints' => trim((string)($result['globalConstraints'] ?? '')),
			'imageStyleBrief' => trim((string)($result['imageStyleBrief'] ?? '')),
			'designBrief' => trim((string)($result['designBrief'] ?? '')),
		];
	}

	/**
	 * @return array<int, array{id: int, code: string, anchor: string, summary: string}>
	 */
	private function collectCandidatesFromLanding(Landing $landing): array
	{
		$candidates = [];
		foreach ($landing->getBlocks() as $blockId => $block)
		{
			$blockId = (int)$blockId;
			if ($blockId <= 0)
			{
				continue;
			}

			$candidates[] = $this->buildCandidate(
				$blockId,
				(string)$block->getCode(),
				(string)$block->getLocalAnchor(),
				(string)$block->getContent(),
			);
		}

		return array_values($candidates);
	}

	/**
	 * @return array<int, array{id: int, code: string, anchor: string, summary: string}>
	 */
	private function collectLandingCandidates(int $landingId): array
	{
		$editModeBefore = Landing::getEditMode();
		Landing::setEditMode(true);
		try
		{
			$landing = Landing::createInstance($landingId, [
				'check_permissions' => false,
			]);
			if (!$landing->exist())
			{
				return [];
			}

			return array_values($this->collectCandidatesFromLanding($landing));
		}
		finally
		{
			Landing::setEditMode($editModeBefore);
		}
	}

	/**
	 * @return array<int, array{id: int, code: string, anchor: string, summary: string}>
	 */
	private function collectTableCandidates(int $landingId): array
	{
		$rows = $this->loadBlockRowsFromTable($landingId);
		if ($rows === [])
		{
			return [];
		}

		return $this->collectCandidatesFromRows($rows);
	}

	private function loadBlockRowsFromTable(int $landingId): array
	{
		if ($landingId <= 0)
		{
			return [];
		}

		$rows = [];
		$res = BlockTable::getList([
			'select' => ['ID', 'CODE', 'ANCHOR', 'CONTENT'],
			'filter' => [
				'=LID' => $landingId,
				'=DELETED' => 'N',
				'=PUBLIC' => 'N',
			],
			'order' => ['SORT' => 'ASC', 'ID' => 'ASC'],
		]);
		while ($row = $res->fetch())
		{
			$rows[] = $row;
		}

		return $rows;
	}

	private function getCandidatesMapOrCollect(): array
	{
		$candidateMap = $this->getCandidatesMap();
		if ($candidateMap !== [])
		{
			return $candidateMap;
		}

		ChangeAiSiteState::setCandidates($this->generation, $this->collectCandidates());

		return $this->getCandidatesMap();
	}

	private function validateActionPlan(array $actions, array $candidateMap): array
	{
		$normalized = [];
		$invalidIds = [];
		$invalidReasons = [];
		$addIndex = 0;
		$moveOrdinals = [];
		$plannedDeleteIds = $this->collectPlannedDeleteIds($actions);

		foreach ($actions as $index => $action)
		{
			if (!is_array($action))
			{
				continue;
			}

			$type = $this->normalizeActionType($action['actionType'] ?? $action['type'] ?? '');
			if ($type === '')
			{
				continue;
			}

			$blockId = (int)($action['blockId'] ?? 0);
			$instruction = trim((string)($action['instruction'] ?? ''));
			if ($instruction === '')
			{
				$instruction = ChangeAiSiteState::resolveRawInput($this->generation);
			}

			if ($type === self::ACTION_ADD)
			{
				$addIndex++;
				$actionId = 'add_' . $addIndex;
				$placement = $this->normalizePlacement(
					$action['placementMode'] ?? '',
					(int)($action['placementBlockId'] ?? 0),
					$candidateMap,
					['append', 'after', 'before'],
				);
				$placement = $this->resolveAddPlacement($placement, $candidateMap, $plannedDeleteIds);
				$normalized[$actionId] = [
					'actionId' => $actionId,
					'type' => self::ACTION_ADD,
					'blockId' => 0,
					'instruction' => $instruction,
					'editMode' => $this->normalizeEditMode($action['editMode'] ?? ''),
					'placement' => $placement,
				];

				continue;
			}

			if ($blockId <= 0 || !isset($candidateMap[$blockId]))
			{
				$this->markInvalidAction($invalidIds, $invalidReasons, $blockId, self::SKIPPED_REASON_NOT_AVAILABLE);
				continue;
			}

			$policyResult = $this->createActionPolicy()->validateAction($type, $candidateMap[$blockId]);
			if (!$policyResult['allowed'])
			{
				$this->markInvalidAction($invalidIds, $invalidReasons, $blockId, (string)$policyResult['reason']);
				continue;
			}

			if ($type === self::ACTION_MOVE)
			{
				if (isset($plannedDeleteIds[$blockId]))
				{
					$this->markInvalidAction($invalidIds, $invalidReasons, $blockId, self::SKIPPED_REASON_MOVE_SOURCE_DELETED_IN_PLAN);
					continue;
				}

				$placement = $this->normalizePlacement(
					$action['placementMode'] ?? '',
					(int)($action['placementBlockId'] ?? 0),
					$candidateMap,
					['append', 'prepend', 'after', 'before'],
				);

				if (in_array($placement['mode'], ['before', 'after'], true) && $placement['blockId'] <= 0)
				{
					$this->markInvalidAction($invalidIds, $invalidReasons, $blockId, self::SKIPPED_REASON_MOVE_TARGET_NOT_FOUND);
					continue;
				}

				if ($placement['blockId'] > 0 && $placement['blockId'] === $blockId)
				{
					$this->markInvalidAction($invalidIds, $invalidReasons, $blockId, self::SKIPPED_REASON_MOVE_SAME_SOURCE_AND_TARGET);
					continue;
				}

				if ($placement['blockId'] > 0 && isset($plannedDeleteIds[$placement['blockId']]))
				{
					$this->markInvalidAction($invalidIds, $invalidReasons, $blockId, self::SKIPPED_REASON_MOVE_TARGET_DELETED_IN_PLAN);
					continue;
				}

				$moveOrdinals[$blockId] = ($moveOrdinals[$blockId] ?? 0) + 1;
				$actionId = 'move_' . $blockId . '_' . $moveOrdinals[$blockId];
				$normalized[$actionId] = [
					'actionId' => $actionId,
					'type' => self::ACTION_MOVE,
					'blockId' => $blockId,
					'instruction' => $instruction,
					'editMode' => $this->normalizeEditMode($action['editMode'] ?? ''),
					'placement' => $placement,
				];

				continue;
			}

			$actionId = ($type === self::ACTION_DELETE ? 'delete_' : 'update_') . $blockId;
			if ($type === self::ACTION_DELETE)
			{
				unset($normalized['update_' . $blockId]);
			}
			elseif (isset($normalized['delete_' . $blockId]))
			{
				continue;
			}

			$normalized[$actionId] = [
				'actionId' => $actionId,
				'type' => $type,
				'blockId' => $blockId,
				'instruction' => $instruction,
				'editMode' => $this->normalizeEditMode($action['editMode'] ?? ''),
				'placement' => [
					'mode' => 'append',
					'blockId' => 0,
				],
			];
		}

		return [
			'actions' => array_values($normalized),
			'invalid' => $this->normalizeIds($invalidIds),
			'invalidReasons' => $invalidReasons,
		];
	}

	private function normalizeActionType(mixed $type): string
	{
		$type = mb_strtolower(trim((string)$type));

		return match ($type)
		{
			'update', 'update_block', 'change', 'change_block' => self::ACTION_UPDATE,
			'add', 'add_block', 'create', 'create_block' => self::ACTION_ADD,
			'delete', 'delete_block', 'remove', 'remove_block' => self::ACTION_DELETE,
			'move', 'move_block', 'reorder', 'reorder_block', 'place_block', 'sort_block' => self::ACTION_MOVE,
			default => '',
		};
	}

	private function normalizeEditMode(mixed $mode): string
	{
		$mode = mb_strtolower(trim((string)$mode));

		return in_array($mode, [
			ChangeAiSiteState::EDIT_MODE_TARGETED,
			ChangeAiSiteState::EDIT_MODE_BALANCED,
			ChangeAiSiteState::EDIT_MODE_GLOBAL,
		], true)
			? $mode
			: ChangeAiSiteState::EDIT_MODE_BALANCED
		;
	}

	private function normalizePlacement(
		mixed $mode,
		int $blockId,
		array $candidateMap,
		array $allowedModes = ['append', 'after', 'before'],
	): array
	{
		$mode = mb_strtolower(trim((string)$mode));
		if (!in_array($mode, $allowedModes, true))
		{
			$mode = 'append';
		}

		if (
			in_array($mode, ['before', 'after'], true)
			&& ($blockId <= 0 || !isset($candidateMap[$blockId]))
		)
		{
			$blockId = 0;
		}

		if (in_array($mode, ['append', 'prepend'], true))
		{
			$blockId = 0;
		}

		return [
			'mode' => $mode,
			'blockId' => $blockId,
		];
	}

	private function resolveAddPlacement(array $placement, array $candidateMap, array $plannedDeleteIds): array
	{
		$mode = (string)($placement['mode'] ?? 'append');
		$blockId = max(0, (int)($placement['blockId'] ?? 0));
		if (!in_array($mode, ['before', 'after'], true) || $blockId <= 0 || !isset($plannedDeleteIds[$blockId]))
		{
			return $placement;
		}

		$orderedIds = $this->normalizeIds(array_keys($candidateMap));
		$targetIndex = array_search($blockId, $orderedIds, true);
		if ($targetIndex === false)
		{
			return $placement;
		}

		if ($mode === 'before')
		{
			for ($index = $targetIndex + 1, $count = count($orderedIds); $index < $count; $index++)
			{
				$candidateId = $orderedIds[$index];
				if (!isset($plannedDeleteIds[$candidateId]))
				{
					return [
						'mode' => 'before',
						'blockId' => $candidateId,
					];
				}
			}

			return [
				'mode' => 'append',
				'blockId' => 0,
			];
		}

		for ($index = $targetIndex - 1; $index >= 0; $index--)
		{
			$candidateId = $orderedIds[$index];
			if (!isset($plannedDeleteIds[$candidateId]))
			{
				return [
					'mode' => 'after',
					'blockId' => $candidateId,
				];
			}
		}

		return [
			'mode' => 'prepend',
			'blockId' => 0,
		];
	}

	private function saveActionPlanResult(
		array $actions,
		array $invalidIds,
		array $invalidReasons,
		string $globalConstraints,
		string $imageStyleBrief,
		string $designBrief,
	): void
	{
		ChangeAiSiteState::setActions($this->generation, $actions);
		ChangeAiSiteState::setBlockInstructions($this->generation, [
			'items' => $this->buildInstructionItems($actions),
			'globalConstraints' => $globalConstraints,
			'imageStyleBrief' => $imageStyleBrief,
			'designBrief' => $designBrief,
		]);

		$resultState = ChangeAiSiteState::getResult($this->generation);
		$skippedReasons = $resultState['skippedReasons'] ?? [];
		foreach ($invalidIds as $invalidId)
		{
			$skippedReasons[(string)$invalidId] = $invalidReasons[(string)$invalidId] ?? self::SKIPPED_REASON_NOT_AVAILABLE;
		}

		ChangeAiSiteState::setResult($this->generation, [
			'changedIds' => $resultState['changedIds'] ?? [],
			'updatedIds' => $resultState['updatedIds'] ?? [],
			'createdIds' => $resultState['createdIds'] ?? [],
			'deletedIds' => $resultState['deletedIds'] ?? [],
			'movedIds' => $resultState['movedIds'] ?? [],
			'moveResults' => $resultState['moveResults'] ?? [],
			'skippedIds' => array_merge($resultState['skippedIds'] ?? [], $invalidIds),
			'skippedReasons' => $skippedReasons,
		]);
	}

	private function buildInstructionItems(array $actions): array
	{
		$items = [];
		foreach ($actions as $action)
		{
			if (!is_array($action))
			{
				continue;
			}

			$items[] = [
				'actionId' => trim((string)($action['actionId'] ?? '')),
				'blockId' => (int)($action['blockId'] ?? 0),
				'instruction' => trim((string)($action['instruction'] ?? '')),
				'editMode' => $this->normalizeEditMode($action['editMode'] ?? ''),
			];
		}

		return $items;
	}

	private function assertHasValidActions(array $actions): void
	{
		if ($actions !== [])
		{
			return;
		}

		throw new GenerationException(
			GenerationErrors::dataValidation,
			'ChangeAiSite action plan returned no valid actions.',
		);
	}

	private function normalizeIds(mixed $ids): array
	{
		if (!is_array($ids))
		{
			return [];
		}

		$normalized = array_filter(
			array_map(static fn(mixed $id): int => (int)$id, $ids),
			static fn(int $id): bool => $id > 0,
		);

		return array_values(array_unique($normalized));
	}

	private function collectCandidatesFromRows(array $rows): array
	{
		$candidates = [];
		foreach ($rows as $row)
		{
			$blockId = (int)($row['ID'] ?? 0);
			if ($blockId <= 0)
			{
				continue;
			}

			$candidates[] = $this->buildCandidate(
				$blockId,
				(string)($row['CODE'] ?? ''),
				(string)($row['ANCHOR'] ?? ''),
				(string)($row['CONTENT'] ?? ''),
			);
		}

		return array_values($candidates);
	}

	private function buildCandidate(int $id, string $code, string $anchor, string $content): array
	{
		$canUpdate = Operator::isBlockAvailableForScenarioChangeBlock($id);
		$policy = $this->createActionPolicy()->buildCandidatePolicy($id, $canUpdate);
		$summary = $this->extractBlockSummary($content);
		$styleSnapshot = (new ChangeAiSiteStyleContextExtractor())->extractBlockSnapshot($content, $code, $summary);

		$candidate = [
			'id' => $id,
			'code' => trim($code),
			'anchor' => trim($anchor),
			'summary' => $summary,
			...$styleSnapshot,
			'canUpdate' => $policy['canUpdate'],
			'canDelete' => $policy['canDelete'],
			'canMove' => $policy['canMove'],
		];
		if ($policy['hardDeny'])
		{
			$candidate['hardDeny'] = true;
		}
		if ($policy['policyReason'] !== '')
		{
			$candidate['policyReason'] = $policy['policyReason'];
		}

		return $candidate;
		}

	protected function createActionPolicy(): ChangeAiSiteActionPolicy
	{
		return new ChangeAiSiteActionPolicy();
	}

	private function collectPlannedDeleteIds(array $actions): array
	{
		$deleteIds = [];
		foreach ($actions as $action)
		{
			if (!is_array($action))
			{
				continue;
			}

			$type = $this->normalizeActionType($action['actionType'] ?? $action['type'] ?? '');
			if ($type !== self::ACTION_DELETE)
			{
				continue;
			}

			$blockId = (int)($action['blockId'] ?? 0);
			if ($blockId > 0)
			{
				$deleteIds[$blockId] = true;
			}
		}

		return $deleteIds;
	}

	private function markInvalidAction(array &$invalidIds, array &$invalidReasons, int $blockId, string $reason): void
	{
		if ($blockId > 0)
		{
			$invalidIds[] = $blockId;
			$invalidReasons[(string)$blockId] = trim($reason) !== '' ? $reason : self::SKIPPED_REASON_NOT_AVAILABLE;
		}
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

	private function setSelectionDebug(string $mode, string $reason = ''): void
	{
		ChangeAiSiteState::setSelectionDebug($this->generation, [
			'mode' => $mode,
			'reason' => trim($reason),
		]);
	}
}
