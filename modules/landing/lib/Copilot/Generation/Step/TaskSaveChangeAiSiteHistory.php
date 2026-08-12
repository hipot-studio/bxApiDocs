<?php
declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Generation\Step;

use Bitrix\Landing\Block;
use Bitrix\Landing\Copilot\Generation\GenerationException;
use Bitrix\Landing\Copilot\Generation\Scenario\ChangeAiSiteState;
use Bitrix\Landing\Copilot\Generation\Step\Base\TaskStep;
use Bitrix\Landing\Copilot\Generation\Type\GenerationErrors;
use Bitrix\Landing\History;
use Bitrix\Landing\History\ActionFactory;
use Bitrix\Landing\Landing;
use Bitrix\Landing\Rights;

class TaskSaveChangeAiSiteHistory extends TaskStep
{
	private const HISTORY_PUSH_DIAGNOSTICS_KEY = 'change_ai_site_history_push_diagnostics';
	private const ACTION_ADD = 'add_block';
	private const ACTION_UPDATE = 'update_block';
	private const ACTION_DELETE = 'delete_block';
	private const ACTION_MOVE = 'move_block';
	private const HISTORY_ACTION_UPDATE_CONTENT = 'UPDATE_CONTENT';
	private const HISTORY_ACTION_ADD_BLOCK = 'ADD_BLOCK';
	private const HISTORY_ACTION_REMOVE_BLOCK = 'REMOVE_BLOCK';
	private const HISTORY_ACTION_MOVE_BLOCK = 'MOVE_BLOCK';
	private const PUSH_STATUS_SUCCESS = 'success';
	private const PUSH_STATUS_DUPLICATE = 'duplicate';
	private const PUSH_STATUS_NOOP = 'noop';
	private const PUSH_STATUS_INVALID_ACTION = 'invalid_action';
	private const PUSH_STATUS_PUSH_FAILED = 'push_failed';
	private const ALLOWED_HISTORY_ACTIONS = [
		self::HISTORY_ACTION_UPDATE_CONTENT => true,
		self::HISTORY_ACTION_ADD_BLOCK => true,
		self::HISTORY_ACTION_REMOVE_BLOCK => true,
		self::HISTORY_ACTION_MOVE_BLOCK => true,
	];

	public function execute(): bool
	{
		parent::execute();

		$snapshot = $this->generation->getData(TaskPresaveChangeAiSiteHistory::DATA_KEY);
		if ($snapshot === null)
		{
			return true;
		}
		if (!is_array($snapshot))
		{
			$snapshot = [];
		}

		$this->generation->deleteData(self::HISTORY_PUSH_DIAGNOSTICS_KEY);

		$landingId = (int)($snapshot['landingId'] ?? ChangeAiSiteState::resolveLandingId($this->generation));
		if ($landingId <= 0)
		{
			return true;
		}

		$actions = $this->buildHistoryActions($snapshot);
		if ($actions === [])
		{
			$this->generation->deleteData(TaskPresaveChangeAiSiteHistory::DATA_KEY);

			return true;
		}

		$rightsState = Rights::isOn();
		$historyState = History::isActive();
		$editModeBefore = Landing::getEditMode();

		Rights::setGlobalOff();
		History::activate();
		Landing::setEditMode(true);
		try
		{
			$diagnostics = $this->pushHistoryActions($landingId, $actions);
			if ($diagnostics !== [])
			{
				$this->generation->setData(self::HISTORY_PUSH_DIAGNOSTICS_KEY, [
					'landingId' => $landingId,
					'items' => $diagnostics,
				]);
			}
		}
		finally
		{
			$this->generation->deleteData(TaskPresaveChangeAiSiteHistory::DATA_KEY);
			History::unsetMultiplyMode();
			Landing::setEditMode($editModeBefore);
			if ($rightsState)
			{
				Rights::setGlobalOn();
			}
			if (!$historyState)
			{
				History::deactivate();
			}
		}

		return true;
	}

	protected function buildHistoryActions(array $snapshot): array
	{
		$landingId = (int)($snapshot['landingId'] ?? ChangeAiSiteState::resolveLandingId($this->generation));
		$updateSnapshots = is_array($snapshot['updates'] ?? null) ? $snapshot['updates'] : [];
		$operations = ChangeAiSiteState::getAppliedOperations($this->generation);
		$historyActions = [];
		foreach ($operations as $operation)
		{
			if (($operation['status'] ?? '') !== ChangeAiSiteState::APPLIED_STATUS_APPLIED)
			{
				continue;
			}

			$type = (string)($operation['type'] ?? '');
			$blockId = (int)($operation['blockId'] ?? 0);
			$historyAction = null;
			if ($type === self::ACTION_UPDATE)
			{
				$historyAction = $this->buildUpdateHistoryActionFromOperation(
					$landingId,
					$blockId,
					(array)($operation['artifacts'] ?? []),
					is_array($updateSnapshots[$blockId] ?? null) ? $updateSnapshots[$blockId] : null,
				);
			}
			elseif ($type === self::ACTION_ADD)
			{
				$historyAction = $this->buildBlockHistoryAction(self::HISTORY_ACTION_ADD_BLOCK, $blockId);
			}
			elseif ($type === self::ACTION_DELETE)
			{
				$historyAction = $this->buildBlockHistoryAction(self::HISTORY_ACTION_REMOVE_BLOCK, $blockId);
			}
			elseif ($type === self::ACTION_MOVE)
			{
				$artifacts = (array)($operation['artifacts'] ?? []);
				$historyAction = $this->buildMoveHistoryActionFromOperation($landingId, $blockId, $artifacts);
			}

			if ($historyAction !== null)
			{
				$historyActions[] = $historyAction;
			}
		}

		return $historyActions;
	}

	protected function resolveLanding(int $landingId): ?Landing
	{
		$landing = $this->siteData->getLandingInstance();
		if ($landing && (int)$landing->getId() === $landingId)
		{
			return $landing;
		}

		$landing = Landing::createInstance($landingId, [
			'check_permissions' => false,
		]);

		return $landing->exist() ? $landing : null;
	}

	protected function buildUpdateHistoryActionFromOperation(
		int $landingId,
		int $blockId,
		array $artifacts,
		?array $snapshot = null,
	): ?array
	{
		$contentBefore = array_key_exists('contentBefore', $artifacts)
			? (string)$artifacts['contentBefore']
			: (is_array($snapshot) && array_key_exists('contentBefore', $snapshot) ? (string)$snapshot['contentBefore'] : null)
		;
		$contentAfter = $this->resolveCurrentHistoryBlockContent($landingId, $blockId);
		if ($contentAfter === null && array_key_exists('contentAfter', $artifacts))
		{
			$contentAfter = (string)$artifacts['contentAfter'];
		}
		if ($blockId <= 0 || $contentBefore === null || $contentAfter === null || $contentBefore === $contentAfter)
		{
			return null;
		}

		return [
			'action' => self::HISTORY_ACTION_UPDATE_CONTENT,
			'params' => [
				'block' => $blockId,
				'contentBefore' => $contentBefore,
				'contentAfter' => $contentAfter,
				'designed' => false,
			],
		];
	}

	protected function buildMoveHistoryActionFromOperation(int $landingId, int $blockId, array $artifacts): ?array
	{
		$orderBefore = $this->normalizeIds($artifacts['orderBefore'] ?? []);
		$orderAfter = $this->normalizeIds($artifacts['orderAfter'] ?? []);
		if ($landingId <= 0 || $blockId <= 0 || $orderBefore === [] || $orderAfter === [] || $orderBefore === $orderAfter)
		{
			return null;
		}

		return [
			'action' => self::HISTORY_ACTION_MOVE_BLOCK,
			'params' => [
				'lid' => $landingId,
				'landingId' => $landingId,
				'orderBefore' => $orderBefore,
				'orderAfter' => $orderAfter,
				'movedIds' => [$blockId],
			],
		];
	}

	protected function resolveCurrentHistoryBlockContent(int $landingId, int $blockId): ?string
	{
		if ($landingId <= 0 || $blockId <= 0)
		{
			return null;
		}

		$landing = $this->resolveLanding($landingId);
		if (!$landing)
		{
			return null;
		}

		$block = $landing->getBlockById($blockId) ?: new Block($blockId);
		if (!$block->exist())
		{
			return null;
		}

		return (string)$block->getContent();
	}

	protected function buildBlockHistoryAction(string $actionName, int $blockId): ?array
	{
		$block = new Block($blockId);
		if (!$block->exist())
		{
			return null;
		}

		return [
			'action' => $actionName,
			'params' => [
				'block' => $block,
			],
		];
	}

	protected function pushHistoryActions(int $landingId, array $actions): array
	{
		$actions = $this->normalizeHistoryActions($actions);
		if ($actions === [])
		{
			return [];
		}

		$history = new History($landingId, History::ENTITY_TYPE_LANDING);
		History::unsetMultiplyMode();
		if (count($actions) > 1)
		{
			History::setMultiplyMode();
		}

		$diagnostics = [];
		foreach ($actions as $action)
		{
			$status = $this->pushHistoryActionWithStatus($history, $action);
			if ($status !== self::PUSH_STATUS_SUCCESS)
			{
				$diagnostics[] = [
					'action' => $action['action'],
					'status' => $status,
				];
			}

			if (
				$status !== self::PUSH_STATUS_SUCCESS
				&& $status !== self::PUSH_STATUS_DUPLICATE
				&& $status !== self::PUSH_STATUS_NOOP
			)
			{
				break;
			}
		}

		return $diagnostics;
	}

	protected function pushHistoryActionWithStatus(History $history, array $action): string
	{
		$predictedStatus = $this->predictHistoryPushFailureStatus(
			$history,
			(string)$action['action'],
			(array)$action['params'],
		);
		if ($history->push($action['action'], $action['params']))
		{
			return self::PUSH_STATUS_SUCCESS;
		}

		return $predictedStatus ?? self::PUSH_STATUS_PUSH_FAILED;
	}

	private function predictHistoryPushFailureStatus(History $history, string $actionName, array $params): ?string
	{
		$historyAction = ActionFactory::getAction($actionName);
		if (!$historyAction)
		{
			return self::PUSH_STATUS_INVALID_ACTION;
		}

		$historyAction->setParams($params);
		$fields = [
			'ENTITY_TYPE' => $this->readHistoryProperty($history, 'entityType'),
			'ENTITY_ID' => $this->readHistoryProperty($history, 'entityId'),
			'ACTION' => $actionName,
			'ACTION_PARAMS' => $historyAction->getParams(),
		];
		$stack = $this->readHistoryProperty($history, 'stack');
		$step = $history->getStep();
		if (
			is_array($stack)
			&& !empty($stack[$step])
			&& ActionFactory::compareSteps($stack[$step], $fields)
		)
		{
			return self::PUSH_STATUS_DUPLICATE;
		}

		if (!$historyAction->isNeedPush())
		{
			return self::PUSH_STATUS_NOOP;
		}

		return null;
	}

	private function readHistoryProperty(History $history, string $propertyName): mixed
	{
		$reflection = new \ReflectionClass($history);
		$property = $reflection->getProperty($propertyName);
		$property->setAccessible(true);

		return $property->getValue($history);
	}

	private function normalizeHistoryActions(array $actions): array
	{
		$normalized = [];
		foreach ($actions as $action)
		{
			if (!is_array($action))
			{
				continue;
			}

			$actionName = mb_strtoupper(trim((string)($action['action'] ?? '')));
			if (!isset(self::ALLOWED_HISTORY_ACTIONS[$actionName]))
			{
				throw new GenerationException(
					GenerationErrors::dataValidation,
					"Unsupported ChangeAiSite history action {$actionName}",
				);
			}

			$params = $action['params'] ?? null;
			if (!is_array($params))
			{
				throw new GenerationException(
					GenerationErrors::dataValidation,
					"Invalid ChangeAiSite history params for action {$actionName}",
				);
			}

			$normalized[] = [
				'action' => $actionName,
				'params' => $params,
			];
		}

		return $normalized;
	}

	private function normalizeIds(array $ids): array
	{
		$ids = array_filter(
			array_map('intval', $ids),
			static fn(int $id): bool => $id > 0,
		);

		return array_values(array_unique($ids));
	}

	/**
	 * @return list<int>
	 */
	private function resolveLandingOrder(int $landingId): array
	{
		$landing = $this->resolveLanding($landingId);
		if (!$landing)
		{
			return [];
		}

		$order = [];
		foreach ($landing->getBlocks() as $block)
		{
			if (!$block instanceof Block)
			{
				continue;
			}

			$blockId = (int)$block->getId();
			if ($blockId > 0)
			{
				$order[] = $blockId;
			}
		}

		return $order;
	}
}
