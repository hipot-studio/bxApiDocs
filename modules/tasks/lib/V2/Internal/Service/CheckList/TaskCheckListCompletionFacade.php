<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\CheckList;

use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use Bitrix\Tasks\Access\ActionDictionary;
use Bitrix\Tasks\CheckList\Internals\CheckList;
use Bitrix\Tasks\CheckList\Task\TaskCheckListFacade;
use Bitrix\Tasks\UI;
use Bitrix\Tasks\Util\User;
use Bitrix\Tasks\V2\Internal\Service\CheckList\Result\CheckListStatusResult;
use Throwable;

class TaskCheckListCompletionFacade extends TaskCheckListFacade
{
	private static bool $hasDeferredUpdates = false;

	public static function completeBatch(int $taskId, int $userId, array $itemsFields): Result
	{
		$result = new Result();
		if (empty($itemsFields))
		{
			return $result;
		}

		$items = [];
		foreach ($itemsFields as $itemFields)
		{
			$item = new CheckList(0, $userId, static::class, $itemFields);
			if (!static::checkAccess($taskId, $userId, ActionDictionary::ACTION_CHECKLIST_TOGGLE, $item))
			{
				return $result->addError(new Error('Access denied', 'ACCESS_DENIED'));
			}

			$items[] = $item;
		}

		$toggledDate = new DateTime(UI::formatDateTime(User::getTime($userId)));
		$checkListDataController = static::getCheckListDataController();
		try
		{
			$checkListDataController::updateByFilter(
				['@ID' => array_keys($itemsFields)],
				[
					'IS_COMPLETE' => 'Y',
					'TOGGLED_BY' => $userId,
					'TOGGLED_DATE' => $toggledDate,
				],
			);
		}
		catch (Throwable $throwable)
		{
			return $result->addError(Error::createFromThrowable($throwable));
		}

		foreach ($items as $oldItem)
		{
			$newItem = $oldItem->copy();
			$newItem->setSkipMembers();
			$newItem->setFields(['IS_COMPLETE' => true]);
			static::doUpdatePostActions($taskId, $userId, $oldItem, $newItem);
		}

		static::onAfterUpdate($taskId);

		return $result;
	}

	public static function runDeferred(int $taskId, int $userId, array $itemsFields, callable $callback): mixed
	{
		$previousItems = static::$oldItemsToMerge;
		self::$hasDeferredUpdates = false;
		static::doMergePreActions($taskId, $userId);
		static::$oldItemsToMerge = static::indexItemsById($itemsFields);
		static::enableDeferredActionsMode();

		$callbackResult = null;
		try
		{
			$callbackResult = $callback();

			return $callbackResult;
		}
		finally
		{
			try
			{
				if (
					self::$hasDeferredUpdates
					&& (
						!($callbackResult instanceof Result)
						|| $callbackResult->isSuccess()
						|| (
							$callbackResult instanceof CheckListStatusResult
							&& $callbackResult->hasChanges()
						)
					)
				)
				{
					static::doMergePostActions($taskId, $userId, ['PARAMETERS' => []]);
					static::flushTimeline($taskId);
				}
			}
			finally
			{
				static::disableDeferredActionsMode();
				static::$oldItemsToMerge = $previousItems;
				self::$hasDeferredUpdates = false;
			}
		}
	}

	private static function indexItemsById(array $itemsFields): array
	{
		$itemsById = [];
		foreach ($itemsFields as $id => $fields)
		{
			$itemId = (int)($fields['ID'] ?? $id);
			$itemsById[$itemId] = $fields;
		}

		return $itemsById;
	}

	protected static function onAfterUpdate(int $taskId): void
	{
		if (static::getDeferredActionsMode())
		{
			self::$hasDeferredUpdates = true;

			return;
		}

		parent::onAfterUpdate($taskId);
	}

	protected static function flushTimeline(int $taskId): void
	{
		parent::onAfterUpdate($taskId);
	}
}
