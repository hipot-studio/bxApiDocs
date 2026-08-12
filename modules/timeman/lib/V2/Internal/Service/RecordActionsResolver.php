<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Internal\Service;

use Bitrix\Timeman\Model\Worktime\Record\WorktimeRecord;
use Bitrix\Timeman\Service\DependencyManager;
use Bitrix\Timeman\Service\Worktime\Action\WorktimeActionList;
use Bitrix\Timeman\V2\Internal\Entity\Record\RecordActions;
use Bitrix\Timeman\V2\Internal\Entity\Record\RecordState;
use Bitrix\Timeman\V2\Internal\Entity\Record\RecordStatus;

/**
 * Resolves status + available actions using legacy WorktimeActionList.
 * Caller must pass the user's latest record; no internal check. Use only from getLatestByUser.
 */
final class RecordActionsResolver
{
	private readonly WorktimeActionList $actionList;

	public function __construct(?WorktimeActionList $actionList = null)
	{
		$this->actionList = $actionList
			?? DependencyManager::getInstance()->getWorktimeActionList();
	}

	public function getStateForRecord(WorktimeRecord $record): RecordState
	{
		$status = RecordStatus::fromRecord($record);
		$recommendedCloseTime = null;

		try
		{
			$this->actionList->buildPossibleActionsListForUser((int)$record->getUserId());

			$actions = $this->buildActionsFromLegacyList($this->actionList);
			$recommendedCloseTime = $this->extractRecommendedCloseTime($record);
		}
		catch (\Throwable $e)
		{
			$actions = RecordActions::none();
		}

		return new RecordState($status, $actions, $recommendedCloseTime);
	}

	private function buildActionsFromLegacyList(WorktimeActionList $actionList): RecordActions
	{
		return new RecordActions(
			canStart: !empty($actionList->getStartActions()),
			canPause: !empty($actionList->getPauseActions()),
			canStop: !empty($actionList->getStopActions()),
			canContinue: !empty($actionList->getContinueActions()),
			canReopen: !empty($actionList->getReopenActions()),
			canEdit: !empty($actionList->getEditActions()),
		);
	}

	private function extractRecommendedCloseTime(WorktimeRecord $record): ?int
	{
		foreach ($this->actionList->getStopActions() as $stopAction)
		{
			if (
				$stopAction->getRecord()
				&& $stopAction->getRecord()->getId() === $record->getId()
				&& $stopAction->getRecordManager()
			)
			{
				return $stopAction->getRecordManager()->getRecommendedStopTimestamp();
			}
		}

		return null;
	}
}
