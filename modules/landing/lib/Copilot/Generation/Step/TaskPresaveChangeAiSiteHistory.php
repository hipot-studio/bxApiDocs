<?php
declare(strict_types=1);

namespace Bitrix\Landing\Copilot\Generation\Step;

use Bitrix\Landing\Block;
use Bitrix\Landing\Copilot\Generation\Scenario\ChangeAiSiteState;
use Bitrix\Landing\Copilot\Generation\Step\Base\TaskStep;
use Bitrix\Landing\History;
use Bitrix\Landing\Landing;
use Bitrix\Landing\Rights;

class TaskPresaveChangeAiSiteHistory extends TaskStep
{
	public const DATA_KEY = 'change_ai_site_history_snapshot';

	private const ACTION_UPDATE = 'update_block';
	private const ACTION_MOVE = 'move_block';

	public function execute(): bool
	{
		parent::execute();

		$this->generation->deleteData(self::DATA_KEY);
		$this->generation->deleteData(TaskPresaveBlocksHistory::DATA_KEY);

		$landingId = ChangeAiSiteState::resolveLandingId($this->generation);
		if ($landingId <= 0)
		{
			return true;
		}

		$historyState = History::isActive();
		$rightsState = Rights::isOn();
		$editModeBefore = Landing::getEditMode();

		Rights::setGlobalOff();
		History::deactivate();
		Landing::setEditMode(true);
		try
		{
			$landing = $this->resolveLanding($landingId);
			if (!$landing)
			{
				return true;
			}

			$snapshot = $this->buildSnapshot($landingId, $landing);
			if ($this->hasSnapshotItems($snapshot))
			{
				$this->generation->setData(self::DATA_KEY, $snapshot);
			}
		}
		finally
		{
			Landing::setEditMode($editModeBefore);
			if ($rightsState)
			{
				Rights::setGlobalOn();
			}
			if ($historyState)
			{
				History::activate();
			}
			else
			{
				History::deactivate();
			}
		}

		return true;
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

	protected function buildSnapshot(int $landingId, Landing $landing): array
	{
		$snapshot = [
			'landingId' => $landingId,
			'updates' => [],
			'structural' => false,
		];

		if ($this->hasMoveActions())
		{
			$snapshot['orderBefore'] = $this->resolveOrderedBlockIds($landing);
		}

		if ($this->hasStructuralActions())
		{
			$snapshot['structural'] = true;
		}

		foreach (ChangeAiSiteState::getHtmlBlocks($this->generation) as $item)
		{
			if (!is_array($item) || trim((string)($item['actionType'] ?? '')) !== self::ACTION_UPDATE)
			{
				continue;
			}

			$blockId = (int)($item['blockId'] ?? 0);
			if ($blockId <= 0 || isset($snapshot['updates'][$blockId]))
			{
				continue;
			}

			$block = $landing->getBlockById($blockId) ?: new Block($blockId);
			if (!$block->exist())
			{
				continue;
			}

			$snapshot['updates'][$blockId] = [
				'actionId' => trim((string)($item['actionId'] ?? '')),
				'contentBefore' => (string)$block->getContent(),
			];
		}

		return $snapshot;
	}

	private function hasSnapshotItems(array $snapshot): bool
	{
		return
			!empty($snapshot['updates'])
			|| !empty($snapshot['orderBefore'])
			|| !empty($snapshot['structural'])
		;
	}

	private function hasMoveActions(): bool
	{
		foreach (ChangeAiSiteState::getActions($this->generation) as $action)
		{
			if (!is_array($action))
			{
				continue;
			}

			if (trim((string)($action['type'] ?? '')) === self::ACTION_MOVE)
			{
				return true;
			}
		}

		return false;
	}

	private function hasStructuralActions(): bool
	{
		foreach (ChangeAiSiteState::getActions($this->generation) as $action)
		{
			if (!is_array($action))
			{
				continue;
			}

			$type = trim((string)($action['type'] ?? ''));
			if ($type === 'add_block' || $type === 'delete_block')
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * @return list<int>
	 */
	private function resolveOrderedBlockIds(Landing $landing): array
	{
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
