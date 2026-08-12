<?php
declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Generation\Step;

use Bitrix\Landing\Copilot\Generation\Step\Base\TaskStep;
use Bitrix\Landing\Block;
use Bitrix\Landing\Copilot\Services\Manifest\Builder;
use Bitrix\Landing\Copilot\Services\Manifest\RepoManifestMerger;
use Bitrix\Landing\Copilot\Generation\GenerationException;
use Bitrix\Landing\Copilot\Generation\Scenario\ChangeAiSiteState;
use Bitrix\Landing\Copilot\Generation\Step\Helper\ChangeAiSiteActionPolicy;
use Bitrix\Landing\Copilot\Generation\Type\GenerationErrors;
use Bitrix\Landing\Debug;
use Bitrix\Landing\History;
use Bitrix\Landing\Landing;
use Bitrix\Landing\Landing\BlockOrderService;
use Bitrix\Landing\PublicAction;
use Bitrix\Landing\PublicActionResult;
use Bitrix\Landing\Repo;
use Bitrix\Landing\Rights;
use Bitrix\Landing\Internals\BlockTable;
use Bitrix\Main\Result as MainResult;

class TaskReplaceChangeAiSiteBlocksHtml extends TaskStep
{
	private const MOVE_LOG_TYPE = 'change_ai_site.move';
	private const APPLY_STATUS_SKIPPED = 'skipped';
	private const APPLY_STATUS_CHANGED = 'changed';
	private const SKIP_REASON_HTML_EMPTY = 'BLOCK_HTML_EMPTY';
	private const SKIP_REASON_CONTENT_NOT_CHANGED = 'BLOCK_CONTENT_NOT_CHANGED_AFTER_UPDATE';
	private const SKIP_REASON_REPLACE_FAILED = 'BLOCK_HTML_REPLACE_FAILED';
	private const SKIP_REASON_ADD_FAILED = 'BLOCK_ADD_FAILED';
	private const SKIP_REASON_DELETE_FAILED = 'BLOCK_DELETE_FAILED';
	private const ACTION_ADD = 'add_block';
	private const ACTION_UPDATE = 'update_block';
	private const ACTION_DELETE = 'delete_block';
	private const ACTION_MOVE = 'move_block';

	public function execute(): bool
	{
		parent::execute();

		$landingId = $this->resolveRequiredLandingId();
		$actions = ChangeAiSiteState::getActions($this->generation);
		if ($actions === [] && ChangeAiSiteState::getHtmlBlocks($this->generation) === [])
		{
			$this->changed = false;

			return true;
		}
		$htmlBlocks = $this->resolveHtmlBlocksForActions($actions);
		$executionPlan = ChangeAiSiteState::getExecutionPlan($this->generation);
		if ($executionPlan === [])
		{
			$executionPlan = $this->buildExecutionPlan($htmlBlocks, $actions);
		}
		ChangeAiSiteState::setExecutionPlan($this->generation, $executionPlan);

		$existingResultState = ChangeAiSiteState::getResult($this->generation);
		$processingResult = $this->applyGeneratedHtmlToBlocks(
			$landingId,
			$htmlBlocks,
			$actions,
			$existingResultState['changedIds'] ?? [],
			$existingResultState['updatedIds'] ?? [],
			$existingResultState['createdIds'] ?? [],
			$existingResultState['deletedIds'] ?? [],
			$existingResultState['skippedIds'] ?? [],
			$existingResultState['skippedReasons'] ?? [],
		);
		$htmlBlocks = $processingResult['htmlBlocks'];
		$executionPlan = is_array($processingResult['executionPlan'] ?? null)
			? $processingResult['executionPlan']
			: ChangeAiSiteState::getExecutionPlan($this->generation)
		;
		$appliedOperationsProvided = array_key_exists('appliedOperations', $processingResult)
			&& is_array($processingResult['appliedOperations'])
		;
		$appliedOperations = $appliedOperationsProvided
			? $processingResult['appliedOperations']
			: ChangeAiSiteState::getAppliedOperations($this->generation)
		;
		$changedInStep = $this->normalizeIds($processingResult['changedInStep']);
		if ($changedInStep !== [])
		{
			$this->markLandingAsDraftChanged($landingId);
		}

		ChangeAiSiteState::setExecutionPlan($this->generation, $executionPlan);
		ChangeAiSiteState::setHtmlBlocks($this->generation, $htmlBlocks);
		if ($appliedOperationsProvided || $appliedOperations !== [])
		{
			ChangeAiSiteState::setAppliedOperations($this->generation, $appliedOperations);
			$resultState = $this->deriveResultStateWithPreservedSkips(
				$existingResultState,
				ChangeAiSiteState::buildResultFromAppliedOperations($appliedOperations),
			);
		}
		else
		{
			$resultState = $this->normalizeResultState(
				$processingResult['changedIds'] ?? [],
				$processingResult['skippedIds'] ?? [],
				$processingResult['skippedReasons'] ?? [],
				$processingResult['updatedIds'] ?? [],
				$processingResult['createdIds'] ?? [],
				$processingResult['deletedIds'] ?? [],
				$processingResult['movedIds'] ?? [],
				$processingResult['moveResults'] ?? [],
			);
		}
		ChangeAiSiteState::setResult($this->generation, $resultState);

		$this->changed = ($changedInStep !== []);

		return true;
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
			'ChangeAiSite landingId is empty for HTML replacement.',
		);
	}

	private function resolveRequiredHtmlBlocks(): array
	{
		$htmlBlocks = ChangeAiSiteState::getHtmlBlocks($this->generation);
		if ($htmlBlocks !== [])
		{
			return $htmlBlocks;
		}

		throw new GenerationException(
			GenerationErrors::dataValidation,
			'ChangeAiSite html blocks are empty for HTML replacement.',
		);
	}

	private function resolveHtmlBlocksForActions(array $actions): array
	{
		$htmlBlocks = ChangeAiSiteState::getHtmlBlocks($this->generation);
		if ($htmlBlocks !== [] || $this->hasNoHtmlPayloadCompatibleActions($actions))
		{
			return $htmlBlocks;
		}

		return $this->resolveRequiredHtmlBlocks();
	}

	private function hasNoHtmlPayloadCompatibleActions(array $actions): bool
	{
		$hasCompatibleAction = false;
		foreach ($actions as $action)
		{
			if (!is_array($action))
			{
				continue;
			}

			$type = (string)($action['type'] ?? '');
			if (!in_array($type, [self::ACTION_DELETE, self::ACTION_MOVE], true))
			{
				return false;
			}

			$hasCompatibleAction = true;
		}

		return $hasCompatibleAction;
	}

	private function buildExecutionPlan(array $htmlBlocks, array $actions): array
	{
		$plan = [];
		$sequence = 1;
		$used = [];

		foreach ($htmlBlocks as $item)
		{
			if (!is_array($item))
			{
				continue;
			}

			$type = $this->normalizeExecutionActionType($item['actionType'] ?? $item['type'] ?? '');
			$actionId = trim((string)($item['actionId'] ?? ''));
			if (
				$actionId === ''
				|| !in_array($type, [self::ACTION_UPDATE, self::ACTION_ADD], true)
				|| isset($used[$actionId])
			)
			{
				continue;
			}

			$plan[] = [
				'seq' => $sequence++,
				'actionId' => $actionId,
				'type' => $type,
				'blockId' => max(0, (int)($item['blockId'] ?? 0)),
				'sourceBlockId' => max(0, (int)($item['sourceBlockId'] ?? 0)),
				'requestedPlacement' => $item['placement'] ?? [],
			];
			$used[$actionId] = true;
		}

		foreach ($actions as $action)
		{
			if (!is_array($action) || ($action['type'] ?? '') !== self::ACTION_DELETE)
			{
				continue;
			}

			$actionId = trim((string)($action['actionId'] ?? ''));
			if ($actionId === '' || isset($used[$actionId]))
			{
				continue;
			}

			$blockId = max(0, (int)($action['blockId'] ?? 0));
			$plan[] = [
				'seq' => $sequence++,
				'actionId' => $actionId,
				'type' => self::ACTION_DELETE,
				'blockId' => $blockId,
				'sourceBlockId' => $blockId,
			];
			$used[$actionId] = true;
		}

		foreach ($actions as $action)
		{
			if (!is_array($action) || ($action['type'] ?? '') !== self::ACTION_MOVE)
			{
				continue;
			}

			$actionId = trim((string)($action['actionId'] ?? ''));
			if ($actionId === '' || isset($used[$actionId]))
			{
				continue;
			}

			$blockId = max(0, (int)($action['blockId'] ?? 0));
			$plan[] = [
				'seq' => $sequence++,
				'actionId' => $actionId,
				'type' => self::ACTION_MOVE,
				'blockId' => $blockId,
				'sourceBlockId' => $blockId,
				'requestedPlacement' => $action['placement'] ?? [],
			];
			$used[$actionId] = true;
		}

		return $plan;
	}

	private function deriveResultStateWithPreservedSkips(array $existingResultState, array $appliedResultState): array
	{
		$changedIds = $this->normalizeIds($appliedResultState['changedIds'] ?? []);
		$preservedSkippedIds = array_merge(
			$existingResultState['skippedIds'] ?? [],
			$appliedResultState['skippedIds'] ?? [],
		);
		$preservedSkippedIds = array_values(array_diff($this->normalizeIds($preservedSkippedIds), $changedIds));
		$skippedReasons = is_array($existingResultState['skippedReasons'] ?? null)
			? $existingResultState['skippedReasons']
			: []
		;
		foreach ($changedIds as $blockId)
		{
			unset($skippedReasons[(string)$blockId]);
		}
		$skippedReasons = array_replace(
			$skippedReasons,
			is_array($appliedResultState['skippedReasons'] ?? null) ? $appliedResultState['skippedReasons'] : [],
		);

		return $this->normalizeResultState(
			$changedIds,
			$preservedSkippedIds,
			$skippedReasons,
			$appliedResultState['updatedIds'] ?? [],
			$appliedResultState['createdIds'] ?? [],
			$appliedResultState['deletedIds'] ?? [],
			$appliedResultState['movedIds'] ?? [],
			$appliedResultState['moveResults'] ?? [],
		);
	}

	private function normalizeResultState(
		array $changedIds,
		array $skippedIds,
		array $skippedReasons,
		array $updatedIds = [],
		array $createdIds = [],
		array $deletedIds = [],
		array $movedIds = [],
		array $moveResults = [],
	): array
	{
		$updatedIds = $this->normalizeIds($updatedIds);
		$createdIds = $this->normalizeIds($createdIds);
		$deletedIds = $this->normalizeIds($deletedIds);
		$movedIds = $this->normalizeIds($movedIds);
		$changedIds = $this->normalizeIds(array_merge($changedIds, $updatedIds, $createdIds, $deletedIds, $movedIds));
		$skippedIds = array_values(array_diff($this->normalizeIds($skippedIds), $changedIds));
		foreach ($changedIds as $id)
		{
			unset($skippedReasons[(string)$id]);
		}

		return [
			'changedIds' => $changedIds,
			'updatedIds' => $updatedIds,
			'createdIds' => $createdIds,
			'deletedIds' => $deletedIds,
			'movedIds' => $movedIds,
			'moveResults' => $moveResults,
			'skippedIds' => $skippedIds,
			'skippedReasons' => $skippedReasons,
		];
	}

	protected function applyGeneratedHtmlToBlocks(
		int $landingId,
		array $htmlBlocks,
		array $actions,
		array $changedIds,
		array $updatedIds,
		array $createdIds,
		array $deletedIds,
		array $skippedIds,
		array $skippedReasons,
	): array
	{
		$updated = false;
		$changedInStep = [];
		$executionPlan = ChangeAiSiteState::getExecutionPlan($this->generation);
		$appliedOperations = ChangeAiSiteState::getAppliedOperations($this->generation);
		$contentsBefore = $this->getPresavedBlockContents();

		$this->executeWithoutRights(function() use (
			$landingId,
			&$htmlBlocks,
			$executionPlan,
			&$appliedOperations,
			$contentsBefore,
			&$changedInStep,
			&$updated,
		): void
		{
			$moveLanding = null;
			$moveService = null;
			$editModeBefore = null;
			try
			{
				foreach ($executionPlan as $planItem)
				{
					$actionId = trim((string)($planItem['actionId'] ?? ''));
					if ($actionId === '' || $this->hasCompletedAppliedOperation($appliedOperations, $actionId))
					{
						continue;
					}

					$type = (string)($planItem['type'] ?? '');
					if ($type === self::ACTION_MOVE && $moveLanding === null)
					{
						$editModeBefore = Landing::getEditMode();
						Landing::setEditMode(true);
						$moveLanding = $this->createLandingForMoveApply($landingId);
						$moveService = $this->createBlockOrderService();
					}

					$operation = match ($type)
					{
						self::ACTION_UPDATE => $this->applyUpdatePlanItem(
							$landingId,
							$htmlBlocks,
							$planItem,
							$contentsBefore,
						),
						self::ACTION_ADD => $this->applyAddPlanItem($landingId, $htmlBlocks, $planItem),
						self::ACTION_DELETE => $this->applyDeletePlanItem($landingId, $planItem),
						self::ACTION_MOVE => $this->applyMovePlanItem(
							$landingId,
							$planItem,
							$moveLanding,
							$moveService,
						),
						default => null,
					};

					if (!is_array($operation))
					{
						continue;
					}

					$appliedOperations = $this->upsertAppliedOperation($appliedOperations, $operation);
					ChangeAiSiteState::setAppliedOperations($this->generation, $appliedOperations);
					$updated = true;
					if (($operation['status'] ?? '') === ChangeAiSiteState::APPLIED_STATUS_APPLIED)
					{
						$blockId = max(0, (int)($operation['blockId'] ?? 0));
						if ($blockId > 0)
						{
							$changedInStep[] = $blockId;
						}
					}
				}
			}
			finally
			{
				if ($editModeBefore !== null)
				{
					Landing::setEditMode($editModeBefore);
				}
			}
		});

		$resultState = ChangeAiSiteState::buildResultFromAppliedOperations($appliedOperations);

		return [
			'htmlBlocks' => $htmlBlocks,
			'executionPlan' => $executionPlan,
			'appliedOperations' => $appliedOperations,
			'changedIds' => $resultState['changedIds'],
			'updatedIds' => $resultState['updatedIds'],
			'createdIds' => $resultState['createdIds'],
			'deletedIds' => $resultState['deletedIds'],
			'movedIds' => $resultState['movedIds'],
			'moveResults' => $resultState['moveResults'],
			'skippedIds' => $resultState['skippedIds'],
			'skippedReasons' => $resultState['skippedReasons'],
			'changedInStep' => $this->normalizeIds($changedInStep),
			'updated' => $updated,
		];
	}

	private function applyUpdatePlanItem(
		int $landingId,
		array &$htmlBlocks,
		array $planItem,
		array $contentsBefore,
	): ?array
	{
		$htmlContext = $this->resolveHtmlBlockContextByActionId($htmlBlocks, (string)($planItem['actionId'] ?? ''));
		if ($htmlContext === null)
		{
			return $this->buildSkippedOperation(
				$planItem,
				max(0, (int)($planItem['blockId'] ?? 0)),
				ChangeAiSiteActionPolicy::REASON_NOT_AVAILABLE,
			);
		}

		$index = (int)$htmlContext['index'];
		$item = $htmlContext['item'];
		$blockId = (int)($item['blockId'] ?? 0);
		$sourceBlockId = (int)($item['sourceBlockId'] ?? 0);
		$policyBlockId = $sourceBlockId > 0 ? $sourceBlockId : $blockId;
		$policyResult = $this->resolveActionPolicy(self::ACTION_UPDATE, $policyBlockId);
		if (!$policyResult['allowed'])
		{
			$htmlBlocks[$index]['applyStatus'] = self::APPLY_STATUS_SKIPPED;

			return $this->buildSkippedOperation($planItem, $blockId, (string)$policyResult['reason'], $policyBlockId);
		}

		$generatedHtml = trim((string)($item['generatedHtml'] ?? ''));
		if ($generatedHtml === '')
		{
			$htmlBlocks[$index]['applyStatus'] = self::APPLY_STATUS_SKIPPED;

			return $this->buildSkippedOperation(
				$planItem,
				$blockId,
				$this->resolveMissingGeneratedHtmlReason($item),
				$policyBlockId,
			);
		}

		$beforeContent = $this->resolveBeforeContent($item, $contentsBefore);
		$replaceResult = $this->executeWithHistoryDisabled(
			fn(): PublicActionResult => $this->updateBlockContent($landingId, $blockId, $generatedHtml),
		);
		if (!$replaceResult->isSuccess())
		{
			$htmlBlocks[$index]['applyStatus'] = self::APPLY_STATUS_SKIPPED;

			return $this->buildSkippedOperation(
				$planItem,
				$blockId,
				$this->extractActionError($replaceResult),
				$policyBlockId,
			);
		}

		$afterContent = $this->getCurrentBlockContent($blockId);
		if (!$this->isAppliedContent($beforeContent, $afterContent, $generatedHtml))
		{
			$htmlBlocks[$index]['applyStatus'] = self::APPLY_STATUS_SKIPPED;

			return $this->buildSkippedOperation(
				$planItem,
				$blockId,
				self::SKIP_REASON_CONTENT_NOT_CHANGED,
				$policyBlockId,
			);
		}

		$changedInStep = [];
		$changedIds = [];
		$this->markChangedBlock($htmlBlocks, $index, $blockId, $generatedHtml, $changedInStep, $changedIds);

		return $this->buildAppliedOperation($planItem, [
			'blockId' => $blockId,
			'sourceBlockId' => $policyBlockId,
			'artifacts' => [
				'contentBefore' => $beforeContent,
				'contentAfter' => $afterContent,
			],
		]);
	}

	private function applyAddPlanItem(
		int $landingId,
		array &$htmlBlocks,
		array $planItem,
	): ?array
	{
		$htmlContext = $this->resolveHtmlBlockContextByActionId($htmlBlocks, (string)($planItem['actionId'] ?? ''));
		if ($htmlContext === null)
		{
			return $this->buildSkippedOperation($planItem, 0, self::SKIP_REASON_ADD_FAILED);
		}

		$index = (int)$htmlContext['index'];
		$item = $htmlContext['item'];
		$generatedHtml = trim((string)($item['generatedHtml'] ?? ''));
		if ($generatedHtml === '')
		{
			$htmlBlocks[$index]['applyStatus'] = self::APPLY_STATUS_SKIPPED;

			return $this->buildSkippedOperation(
				$planItem,
				0,
				$this->resolveMissingGeneratedHtmlReason($item),
			);
		}

		$templateResult = $this->createAiBlockTemplate($generatedHtml, [
			'name' => $this->buildNewBlockName($item),
			'sections' => ['ai'],
		]);
		if (!$templateResult->isSuccess())
		{
			$htmlBlocks[$index]['applyStatus'] = self::APPLY_STATUS_SKIPPED;

			return $this->buildSkippedOperation(
				$planItem,
				0,
				$this->extractActionError($templateResult, self::SKIP_REASON_ADD_FAILED),
			);
		}

		$templateData = $templateResult->getResult();
		$repoId = (int)($templateData['repoId'] ?? 0);
		$addResult = $this->addAiBlockToLanding($landingId, $repoId, $item);
		if (!$addResult['success'])
		{
			$htmlBlocks[$index]['applyStatus'] = self::APPLY_STATUS_SKIPPED;

			return $this->buildSkippedOperation(
				$planItem,
				0,
				(string)($addResult['error'] ?? self::SKIP_REASON_ADD_FAILED),
			);
		}

		$blockId = (int)($addResult['blockId'] ?? 0);
		if ($blockId <= 0)
		{
			$htmlBlocks[$index]['applyStatus'] = self::APPLY_STATUS_SKIPPED;

			return $this->buildSkippedOperation($planItem, 0, self::SKIP_REASON_ADD_FAILED);
		}

		$htmlBlocks[$index]['blockId'] = $blockId;
		$htmlBlocks[$index]['sourceBlockId'] = 0;
		$htmlBlocks[$index]['applyStatus'] = self::APPLY_STATUS_CHANGED;
		$htmlBlocks[$index]['manifestSync'] = [
			'status' => 'created',
			'repoId' => $repoId,
		];

		return $this->buildAppliedOperation($planItem, [
			'blockId' => $blockId,
			'sourceBlockId' => 0,
			'resolvedPlacement' => $planItem['requestedPlacement'] ?? ($item['placement'] ?? []),
		]);
	}

	private function applyDeletePlanItem(int $landingId, array $planItem): ?array
	{
		$blockId = max(0, (int)($planItem['blockId'] ?? 0));
		if ($blockId <= 0)
		{
			return null;
		}

		$policyResult = $this->resolveActionPolicy(self::ACTION_DELETE, $blockId);
		if (!$policyResult['allowed'])
		{
			return $this->buildSkippedOperation($planItem, $blockId, (string)$policyResult['reason']);
		}

		$deleteResult = $this->executeWithHistoryDisabled(
			fn(): PublicActionResult => $this->deleteLandingBlock($landingId, $blockId),
		);
		if (!$deleteResult->isSuccess())
		{
			return $this->buildSkippedOperation(
				$planItem,
				$blockId,
				$this->extractActionError($deleteResult, self::SKIP_REASON_DELETE_FAILED),
			);
		}

		return $this->buildAppliedOperation($planItem, [
			'blockId' => $blockId,
			'sourceBlockId' => $blockId,
		]);
	}

	private function applyMovePlanItem(
		int $landingId,
		array $planItem,
		Landing $landing,
		BlockOrderService $service,
	): ?array
	{
		$blockId = max(0, (int)($planItem['blockId'] ?? 0));
		if ($blockId <= 0)
		{
			return null;
		}

		$placement = $this->normalizeMovePlacement($planItem['requestedPlacement'] ?? []);
		$actionId = trim((string)($planItem['actionId'] ?? ''));
		$this->logMoveAction('requested', [
			'landingId' => $landingId,
			'blockId' => $blockId,
			'actionId' => $actionId,
			'placement' => $placement,
		]);

		$policyResult = $this->resolveActionPolicy(self::ACTION_MOVE, $blockId);
		if (!$policyResult['allowed'])
		{
			$this->logMoveAction('skipped', [
				'landingId' => $landingId,
				'blockId' => $blockId,
				'actionId' => $actionId,
				'placement' => $placement,
				'reason' => $policyResult['reason'],
			]);

			return $this->buildSkippedOperation($planItem, $blockId, (string)$policyResult['reason']);
		}

		$moveResult = $this->executeWithHistoryDisabled(
			fn(): MainResult => $service->move($landing, $blockId, $placement),
		);
		if (!$moveResult->isSuccess())
		{
			$reason = $this->extractActionError($moveResult, BlockOrderService::ERROR_APPLY_FAILED);
			$this->logMoveAction('failed', [
				'landingId' => $landingId,
				'blockId' => $blockId,
				'actionId' => $actionId,
				'placement' => $placement,
				'reason' => $reason,
			]);

			return $this->buildSkippedOperation($planItem, $blockId, $reason);
		}

		$data = $moveResult->getData();
		if (empty($data['changed']))
		{
			$this->logMoveAction('skipped', [
				'landingId' => $landingId,
				'blockId' => $blockId,
				'actionId' => $actionId,
				'placement' => $placement,
				'reason' => BlockOrderService::ERROR_NO_POSITION_CHANGE,
			]);

			return $this->buildSkippedOperation($planItem, $blockId, BlockOrderService::ERROR_NO_POSITION_CHANGE);
		}

		$resolvedPlacement = $this->normalizeMovePlacement($data['placement'] ?? $placement);
		$this->logMoveAction('applied', [
			'landingId' => $landingId,
			'blockId' => $blockId,
			'actionId' => $actionId,
			'placement' => $resolvedPlacement,
			'fromIndex' => $data['fromIndex'] ?? 0,
			'toIndex' => $data['toIndex'] ?? 0,
		]);

		return $this->buildAppliedOperation($planItem, [
			'blockId' => $blockId,
			'sourceBlockId' => $blockId,
			'resolvedPlacement' => $resolvedPlacement,
			'fromIndex' => max(0, (int)($data['fromIndex'] ?? 0)),
			'toIndex' => max(0, (int)($data['toIndex'] ?? 0)),
			'artifacts' => [
				'orderBefore' => is_array($data['orderBefore'] ?? null) ? $data['orderBefore'] : [],
				'orderAfter' => is_array($data['orderAfter'] ?? null) ? $data['orderAfter'] : [],
			],
		]);
	}

	private function resolveHtmlBlockContextByActionId(array $htmlBlocks, string $actionId): ?array
	{
		$actionId = trim($actionId);
		if ($actionId === '')
		{
			return null;
		}

		foreach ($htmlBlocks as $index => $item)
		{
			if (is_array($item) && trim((string)($item['actionId'] ?? '')) === $actionId)
			{
				return [
					'index' => $index,
					'item' => $item,
				];
			}
		}

		return null;
	}

	private function hasCompletedAppliedOperation(array $operations, string $actionId): bool
	{
		$actionId = trim($actionId);
		if ($actionId === '')
		{
			return false;
		}

		foreach ($operations as $operation)
		{
			if (
				is_array($operation)
				&& trim((string)($operation['actionId'] ?? '')) === $actionId
				&& in_array(
					(string)($operation['status'] ?? ''),
					[
						ChangeAiSiteState::APPLIED_STATUS_APPLIED,
						ChangeAiSiteState::APPLIED_STATUS_SKIPPED,
					],
					true,
				)
			)
			{
				return true;
			}
		}

		return false;
	}

	private function buildAppliedOperation(array $planItem, array $overrides = []): array
	{
		$operation = [
			'seq' => max(1, (int)($planItem['seq'] ?? 0)),
			'actionId' => trim((string)($planItem['actionId'] ?? '')),
			'type' => trim((string)($planItem['type'] ?? '')),
			'status' => ChangeAiSiteState::APPLIED_STATUS_APPLIED,
			'blockId' => max(0, (int)($planItem['blockId'] ?? 0)),
			'sourceBlockId' => max(0, (int)($planItem['sourceBlockId'] ?? 0)),
		];
		if (array_key_exists('requestedPlacement', $planItem))
		{
			$operation['requestedPlacement'] = $planItem['requestedPlacement'];
		}

		return array_replace($operation, $overrides);
	}

	private function buildSkippedOperation(
		array $planItem,
		int $blockId,
		string $skipReason,
		?int $sourceBlockId = null,
	): array
	{
		return $this->buildAppliedOperation($planItem, [
			'status' => ChangeAiSiteState::APPLIED_STATUS_SKIPPED,
			'blockId' => max(0, $blockId),
			'sourceBlockId' => max(0, (int)($sourceBlockId ?? ($planItem['sourceBlockId'] ?? 0))),
			'skipReason' => trim($skipReason),
		]);
	}

	private function upsertAppliedOperation(array $operations, array $operation): array
	{
		$actionId = trim((string)($operation['actionId'] ?? ''));
		if ($actionId === '')
		{
			return $operations;
		}

		foreach ($operations as $index => $existingOperation)
		{
			if (is_array($existingOperation) && trim((string)($existingOperation['actionId'] ?? '')) === $actionId)
			{
				$operations[$index] = $operation;

				return $operations;
			}
		}

		$operations[] = $operation;

		return $operations;
	}

	private function normalizeExecutionActionType(mixed $type): string
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

	protected function resolveActionPolicy(string $actionType, int $blockId): array
	{
		$candidateMap = $this->getCandidatesMap();
		if ($candidateMap === [])
		{
			return [
				'allowed' => true,
				'reason' => '',
			];
		}

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

	private function resolveMissingGeneratedHtmlReason(array $item): string
	{
		$generationError = trim((string)($item['generationError'] ?? ''));
		if ($generationError !== '')
		{
			return $generationError;
		}

		return self::SKIP_REASON_HTML_EMPTY;
	}

	private function markChangedBlock(
		array &$htmlBlocks,
		int $index,
		int $blockId,
		string $generatedHtml,
		array &$changedInStep,
		array &$changedIds,
	): void
	{
		$htmlBlocks[$index]['applyStatus'] = self::APPLY_STATUS_CHANGED;
		$changedInStep[] = $blockId;
		$changedIds[] = $blockId;
		$htmlBlocks[$index]['manifestSync'] = $this->syncBlockManifestByGeneratedHtml($blockId, $generatedHtml);
	}

	private function buildNewBlockName(array $item): string
	{
		$instruction = trim((string)($item['instruction'] ?? ''));
		if ($instruction === '')
		{
			return 'AI generated block';
		}

		$name = mb_substr($instruction, 0, 80);

		return trim($name) !== '' ? $name : 'AI generated block';
	}

	private function addAiBlockToLanding(int $landingId, int $repoId, array $item): array
	{
		if ($landingId <= 0 || $repoId <= 0)
		{
			return [
				'success' => false,
				'error' => self::SKIP_REASON_ADD_FAILED,
			];
		}

		$placement = $this->normalizePlacement($item['placement'] ?? []);
		$afterId = $placement['mode'] === 'after' ? $placement['blockId'] : 0;
		$beforeId = $placement['mode'] === 'before' ? $placement['blockId'] : 0;
		if ($placement['mode'] === 'prepend')
		{
			$beforeId = $this->resolveFirstLandingBlockId($landingId);
		}
		$addResult = $this->executeWithHistoryDisabled(
			fn(): PublicActionResult => $this->addAiBlockTemplateToLanding($landingId, $repoId, $afterId, $beforeId),
		);
		if (!$addResult->isSuccess())
		{
			return [
				'success' => false,
				'error' => $this->extractActionError($addResult, self::SKIP_REASON_ADD_FAILED),
			];
		}

		$result = $addResult->getResult();

		return [
			'success' => true,
			'blockId' => (int)($result['blockId'] ?? 0),
		];
	}

	protected function createLandingForMoveApply(int $landingId): Landing
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
			'Landing does not exist for ChangeAiSite move apply.',
		);
	}

	protected function createBlockOrderService(): BlockOrderService
	{
		return new BlockOrderService();
	}

	protected function updateBlockContent(int $landingId, int $blockId, string $generatedHtml): PublicActionResult
	{
		return PublicAction\Block::updateContent(
			$landingId,
			$blockId,
			$generatedHtml,
			false,
			true,
		);
	}

	protected function createAiBlockTemplate(string $generatedHtml, array $meta): PublicActionResult
	{
		return PublicAction\AiBlock::createTemplate($generatedHtml, $meta);
	}

	protected function addAiBlockTemplateToLanding(
		int $landingId,
		int $repoId,
		int $afterId,
		int $beforeId,
	): PublicActionResult
	{
		return PublicAction\AiBlock::addToLanding($landingId, $repoId, $afterId, $beforeId);
	}

	protected function resolveFirstLandingBlockId(int $landingId): int
	{
		if ($landingId <= 0)
		{
			return 0;
		}

		$row = BlockTable::getList([
			'select' => ['ID'],
			'filter' => [
				'=LID' => $landingId,
				'=DELETED' => 'N',
				'=PUBLIC' => 'N',
			],
			'order' => ['SORT' => 'ASC', 'ID' => 'ASC'],
			'limit' => 1,
		])->fetch();

		return max(0, (int)($row['ID'] ?? 0));
	}

	protected function deleteLandingBlock(int $landingId, int $blockId): PublicActionResult
	{
		return PublicAction\Landing::markDeletedBlock($landingId, $blockId, true, true);
	}

	private function executeWithHistoryDisabled(callable $callback): mixed
	{
		$historyState = History::isActive();
		History::deactivate();
		try
		{
			return $callback();
		}
		finally
		{
			if ($historyState)
			{
				History::activate();
			}
			else
			{
				History::deactivate();
			}
		}
	}

	private function normalizePlacement(mixed $placement): array
	{
		$placement = is_array($placement) ? $placement : [];
		$mode = trim((string)($placement['mode'] ?? 'append'));
		if (!in_array($mode, ['append', 'prepend', 'after', 'before'], true))
		{
			$mode = 'append';
		}

		$blockId = max(0, (int)($placement['blockId'] ?? 0));
		if (in_array($mode, ['append', 'prepend'], true))
		{
			$blockId = 0;
		}

		return [
			'mode' => $mode,
			'blockId' => $blockId,
		];
	}

	private function normalizeMovePlacement(mixed $placement): array
	{
		$placement = is_array($placement) ? $placement : [];
		$mode = trim((string)($placement['mode'] ?? 'append'));
		if (!in_array($mode, ['append', 'prepend', 'after', 'before'], true))
		{
			$mode = 'append';
		}

		$blockId = max(0, (int)($placement['blockId'] ?? 0));
		if (in_array($mode, ['append', 'prepend'], true))
		{
			$blockId = 0;
		}

		return [
			'mode' => $mode,
			'blockId' => $blockId,
		];
	}

	private function logMoveAction(string $status, array $context): void
	{
		$payload = [
			'component' => self::MOVE_LOG_TYPE,
			'status' => trim($status),
			'landingId' => max(0, (int)($context['landingId'] ?? 0)),
			'blockId' => max(0, (int)($context['blockId'] ?? 0)),
			'actionId' => trim((string)($context['actionId'] ?? '')),
			'placement' => $this->normalizeMovePlacement($context['placement'] ?? []),
		];
		$reason = trim((string)($context['reason'] ?? ''));
		if ($reason !== '')
		{
			$payload['reason'] = $reason;
		}

		if (isset($context['fromIndex']))
		{
			$payload['fromIndex'] = max(0, (int)$context['fromIndex']);
		}
		if (isset($context['toIndex']))
		{
			$payload['toIndex'] = max(0, (int)$context['toIndex']);
		}

		$encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		try
		{
			Debug::logToFile(is_string($encoded) ? $encoded : serialize($payload));
		}
		catch (\Throwable)
		{
			// Logging is best-effort and must not break apply flow in reduced test/runtime contexts.
		}
	}

	private function isAppliedContent(string $beforeContent, string $afterContent, string $generatedHtml): bool
	{
		$beforeComparable = $this->normalizeComparableHtml($beforeContent);
		$afterComparable = $this->normalizeComparableHtml($afterContent);
		$generatedComparable = $this->normalizeComparableHtml($generatedHtml);

		return
			$afterComparable !== ''
			&& (
				$afterComparable !== $beforeComparable
				|| ($generatedComparable !== '' && $afterComparable === $generatedComparable)
			)
		;
	}

	private function normalizeIds(array $ids): array
	{
		return array_values(array_unique(array_filter(array_map('intval', $ids))));
	}

	/**
	 * @return array{
	 * 	status: string,
	 * 	reason?: string,
	 * 	repoId?: int
	 * }
	 */
	private function syncBlockManifestByGeneratedHtml(int $blockId, string $generatedHtml): array
	{
		$generatedHtml = trim($generatedHtml);
		if ($blockId <= 0 || $generatedHtml === '')
		{
			return [
				'status' => 'skipped',
				'reason' => 'EMPTY_BLOCK_OR_HTML',
			];
		}

		$block = $this->createBlockInstance($blockId);
		if (!$block->exist() || !$block->isDesigned())
		{
			return [
				'status' => 'skipped',
				'reason' => 'BLOCK_NOT_DESIGNED',
			];
		}

		$repoId = $this->resolveRepoIdByBlockCode((string)$block->getCode());
		if ($repoId <= 0)
		{
			return [
				'status' => 'skipped',
				'reason' => 'BLOCK_CODE_NOT_REPO',
			];
		}

		$repoRow = $this->repoGetById($repoId)->fetch();
		if (!$repoRow)
		{
			return [
				'status' => 'skipped',
				'reason' => 'REPO_NOT_FOUND',
				'repoId' => $repoId,
			];
		}

		$currentManifest = unserialize((string)($repoRow['MANIFEST'] ?? ''), ['allowed_classes' => false]);
		$currentManifest = is_array($currentManifest) ? $currentManifest : [];

		$builder = $this->createManifestBuilder();
		$rebuiltManifest = $builder->build($generatedHtml, [
			'name' => (string)($repoRow['NAME'] ?? $block->getCode()),
			'sections' => $this->resolveRepoSections((string)($repoRow['SECTIONS'] ?? '')),
		]);
		$nextManifest = $this->mergeManifestForRepo($currentManifest, $rebuiltManifest, $generatedHtml);

		if ($this->normalizeForCompare($nextManifest) === $this->normalizeForCompare($currentManifest))
		{
			return [
				'status' => 'unchanged',
				'repoId' => $repoId,
			];
		}

		$updateResult = $this->repoUpdate($repoId, [
			'MANIFEST' => serialize($nextManifest),
		]);
		if (!$updateResult->isSuccess())
		{
			$messages = $updateResult->getErrorMessages();

			return [
				'status' => 'failed',
				'reason' => !empty($messages) ? implode('; ', $messages) : 'REPO_MANIFEST_UPDATE_FAILED',
				'repoId' => $repoId,
			];
		}

		return [
			'status' => 'updated',
			'repoId' => $repoId,
		];
	}

	protected function createBlockInstance(int $blockId): Block
	{
		return new Block($blockId);
	}

	protected function repoGetById(int $repoId)
	{
		return Repo::getById($repoId);
	}

	protected function createManifestBuilder(): Builder
	{
		return new Builder();
	}

	protected function repoUpdate(int $repoId, array $fields)
	{
		return Repo::update($repoId, $fields);
	}

	private function resolveRepoIdByBlockCode(string $code): int
	{
		if (!preg_match(Block::REPO_MASK, trim($code), $matches))
		{
			return 0;
		}

		return (int)($matches[1] ?? 0);
	}

	private function resolveRepoSections(string $rawSections): array
	{
		$sections = array_values(array_filter(array_map(
			static fn($value) => trim((string)$value),
			explode(',', $rawSections),
		)));

		return $sections !== [] ? $sections : ['ai'];
	}

	private function mergeManifestForRepo(array $currentManifest, array $rebuiltManifest, string $generatedHtml): array
	{
		return (new RepoManifestMerger())->merge($currentManifest, $rebuiltManifest, $generatedHtml);
	}

	private function normalizeForCompare(mixed $value): mixed
	{
		if (!is_array($value))
		{
			return $value;
		}
		if ($value === [])
		{
			return [];
		}

		$normalized = [];
		$isAssoc = array_keys($value) !== range(0, count($value) - 1);
		if ($isAssoc)
		{
			ksort($value);
		}

		foreach ($value as $key => $item)
		{
			$normalized[$key] = $this->normalizeForCompare($item);
		}

		return $normalized;
	}

	private function extractActionError(object $result, string $fallback = self::SKIP_REASON_REPLACE_FAILED): string
	{
		$error = null;
		if (method_exists($result, 'getError'))
		{
			$errorPayload = $result->getError();
			if (is_object($errorPayload) && method_exists($errorPayload, 'getFirstError'))
			{
				$error = $errorPayload->getFirstError();
			}
			elseif ($errorPayload instanceof \Bitrix\Main\Error)
			{
				$error = $errorPayload;
			}
		}
		if ($error === null && method_exists($result, 'getErrors'))
		{
			$errors = $result->getErrors();
			if (is_array($errors) && $errors !== [])
			{
				$error = $errors[0];
			}
		}
		if ($error)
		{
			$message = trim($error->getMessage());
			if ($message !== '')
			{
				return $message;
			}

			$code = trim((string)$error->getCode());
			if ($code !== '')
			{
				return $code;
			}
		}

		return $fallback;
	}

	private function getPresavedBlockContents(): array
	{
		$contentsBefore = $this->generation->getData(TaskPresaveBlocksHistory::DATA_KEY);

		return is_array($contentsBefore) ? $contentsBefore : [];
	}

	private function resolveBeforeContent(array $item, array $contentsBefore): string
	{
		$originalHtml = trim((string)($item['originalHtml'] ?? ''));
		if ($originalHtml !== '')
		{
			return $originalHtml;
		}

		$blockId = (int)($item['blockId'] ?? 0);
		if ($blockId <= 0)
		{
			return '';
		}

		return trim((string)($contentsBefore[$blockId] ?? ''));
	}

	private function getCurrentBlockContent(int $blockId): string
	{
		if ($blockId <= 0)
		{
			return '';
		}

		$block = $this->createBlockInstance($blockId);
		if (!$block->exist())
		{
			return '';
		}

		return (string)$block->getContent();
	}

	private function normalizeComparableHtml(string $html): string
	{
		$html = trim($html);
		if ($html === '')
		{
			return '';
		}

		$html = preg_replace('/\s+/u', ' ', $html) ?: $html;

		return trim($html);
	}

	protected function markLandingAsDraftChanged(int $landingId): void
	{
		if ($landingId <= 0)
		{
			return;
		}

		$this->executeWithoutRights(function() use ($landingId): void
		{
			$landing = Landing::createInstance($landingId, [
				'check_permissions' => false,
				'skip_blocks' => true,
			]);
			if ($landing->exist())
			{
				$landing->touch();
			}
		});
	}

	private function executeWithoutRights(callable $callback): mixed
	{
		$rightsState = Rights::isOn();
		Rights::setGlobalOff();
		try
		{
			return $callback();
		}
		finally
		{
			if ($rightsState)
			{
				Rights::setGlobalOn();
			}
		}
	}
}
