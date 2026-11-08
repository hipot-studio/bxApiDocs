<?php

namespace Bitrix\Tasks\Rest\Controllers\Checklist;

use Bitrix\Main\Engine\AutoWire\BinderArgumentException;
use Bitrix\Main\Engine\AutoWire\ExactParameter;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Engine\Response\Converter;
use Bitrix\Tasks\Access\ActionDictionary;
use Bitrix\Tasks\Access\Model\TaskModel;
use Bitrix\Tasks\Access\TaskAccessController;
use Bitrix\Tasks\CheckList\Decorator\CheckListMemberDecorator;
use Bitrix\Tasks\CheckList\Exception\CheckListException;
use Bitrix\Tasks\CheckList\Node\Nodes;
use Bitrix\Tasks\CheckList\Task\TaskCheckListFacade;
use Bitrix\Tasks\Control\Exception\TaskNotFoundException;
use Bitrix\Tasks\Control\Exception\TaskUpdateException;
use Bitrix\Tasks\Internals\Registry\TaskRegistry;
use Bitrix\Tasks\Internals\TaskObject;
use Bitrix\Tasks\Rest\Controllers\Trait\ErrorResponseTrait;
use Throwable;

class CheckList extends Controller
{
	use ErrorResponseTrait;

	private int $userId;
	private Converter $converter;

	/**
	 * @throws BinderArgumentException
	 */
	public function getAutoWiredParameters(): array
	{
		return [
			new ExactParameter(
				Nodes::class,
				'nodes',
				function(string $className, int $taskId, array $nodes = []) {

					// the closure runs before the action body, so the addressed task is checked here,
					// ahead of the items checked against it
					if ($taskId <= 0)
					{
						return $this->buildErrorResponse('Wrong task');
					}

					// a task the caller may not read is refused the same way its foreign items are,
					// so the errors of the response tell the two apart no more
					if (!TaskAccessController::can($this->userId, ActionDictionary::ACTION_TASK_READ, $taskId))
					{
						return $this->buildErrorResponse('Access denied');
					}

					$ids = array_values(array_filter(array_map(static fn($item) => (int)($item['ID'] ?? 0), $nodes)));
					if ($ids)
					{
						$ownItems = TaskCheckListFacade::getList(['ID'], ['TASK_ID' => $taskId, 'ID' => $ids]);
						foreach ($ids as $itemId)
						{
							if (!isset($ownItems[$itemId]))
							{
								// the action declares non-nullable nodes, so null makes the binder reject the call
								return $this->buildErrorResponse('Access denied');
							}
						}
					}

					$items = [];
					foreach ($nodes as $id => $item)
					{
						$item['ID'] = ((int)($item['ID'] ?? null) === 0 ? null : (int)$item['ID']);

						$item['IS_COMPLETE'] = (
							($item['IS_COMPLETE'] === true)
							|| ((int)$item['IS_COMPLETE'] > 0)
						);
						$item['IS_IMPORTANT'] = (
							($item['IS_IMPORTANT'] === true)
							|| ((int)$item['IS_IMPORTANT'] > 0)
						);

						$items[$item['NODE_ID']] = $item;
						unset($items[$id]);
					}

					/** @var $className Nodes */
					$nodes = $className::createFromArray($items);
					$nodes->validate();

					return $nodes;
				}
			),
		];
	}

	/**
	 * @restMethod tasks.checklist.checklist.save
	 *
	 * @throws TaskNotFoundException
	 * @throws CheckListException
	 * @throws TaskUpdateException
	 */
	public function saveAction(int $taskId, Nodes $nodes): ?array
	{
		if (!TaskAccessController::can($this->userId, ActionDictionary::ACTION_CHECKLIST_SAVE, $taskId, $nodes->toArray()))
		{
			return $this->buildErrorResponse('Access denied');
		}

		$task = TaskRegistry::getInstance()->getObject($taskId);
		if (null === $task)
		{
			return $this->buildErrorResponse('Task not found');
		}

		if (!$this->canAssignAccomplices($nodes, $task))
		{
			return $this->buildErrorResponse('Access denied. Cannot assign accomplices.');
		}

		if (!$this->canAssignAuditors($nodes, $task))
		{
			return $this->buildErrorResponse('Access denied. Cannot assign auditors.');
		}

		$decorator = new CheckListMemberDecorator(new TaskCheckListFacade(), $this->userId);

		try
		{
			$nodes = $decorator->mergeNodes($taskId, $nodes);
		}
		catch (CheckListException|TaskUpdateException|TaskNotFoundException $e)
		{
			return $this->buildErrorResponse($e->getMessage());
		}
		catch (Throwable $e)
		{
			$this->log($e, 'CHECKLIST_AJAX_ERROR');
			return $this->buildErrorResponse();
		}

		return $this->converter->process($nodes->toArray());
	}

	protected function init(): void
	{
		parent::init();
		$this->userId = (int)CurrentUser::get()->getId();
		$this->converter = new Converter(Converter::OUTPUT_JSON_FORMAT);
	}

	protected function canAssignAccomplices(Nodes $nodes, TaskObject $task): bool
	{
		$checklistAccomplices = $nodes->getAccomplices();
		if (empty($checklistAccomplices))
		{
			return true;
		}

		$newAccomplices = array_diff($checklistAccomplices, $task->getAccompliceMembersIds());
		if (empty($newAccomplices))
		{
			return true;
		}

		$accompliceTask = TaskModel::createFromArray(['ID' => $task->getId(), 'ACCOMPLICES' => $newAccomplices]);
		if (!TaskAccessController::can($this->userId, ActionDictionary::ACTION_TASK_CHANGE_ACCOMPLICES, $task->getId(), $accompliceTask))
		{
			return false;
		}

		return true;
	}

	protected function canAssignAuditors(Nodes $nodes, TaskObject $task): bool
	{
		$checklistAuditors = $nodes->getAuditors();
		if (empty($checklistAuditors))
		{
			return true;
		}

		$newAuditors = array_diff($checklistAuditors, $task->getAuditorMembersIds());
		if (empty($newAuditors))
		{
			return true;
		}

		if (!TaskAccessController::can($this->userId, ActionDictionary::ACTION_TASK_ADD_AUDITORS, $task->getId(), $newAuditors))
		{
			return false;
		}

		return true;
	}
}