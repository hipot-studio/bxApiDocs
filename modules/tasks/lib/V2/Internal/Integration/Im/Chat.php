<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im;

use Bitrix\Im;
use Bitrix\Im\V2\Chat\ExternalChat\Event\BeforeUsersAddEvent;
use Bitrix\Main\Error;
use Bitrix\Main\EventResult;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Tasks\V2\Internal\Entity;
use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Im\V2\Chat\ExternalChat\Event\RegisterTypeEvent;
use Bitrix\Im\V2\Chat\ExternalChat\Config;
use Bitrix\Im\V2\Permission\Action;
use Bitrix\Im\V2\Chat\ExternalChat\Event\FilterUsersByAccessEvent;
use Bitrix\Im\V2\Relation\AddUsersConfig;
use Bitrix\Im\V2\Chat\ChatFactory;
use Bitrix\Tasks\V2\Internal\Result\Result;

class Chat
{
	public const ENTITY_TYPE = 'TASKS_TASK';

	public static function onRegisterType(RegisterTypeEvent $event): EventResult
	{
		$parameters = [
			'type' => self::ENTITY_TYPE,
			'config' => new Config(
				hasOwnRecentSection: true,
				permissions: [
					Action::Extend->value => \Bitrix\Im\V2\Chat::ROLE_MEMBER,
					Action::ChangeAvatar->value => \Bitrix\Im\V2\Chat::ROLE_NONE,
					Action::ChangeDescription->value => \Bitrix\Im\V2\Chat::ROLE_NONE,
					Action::ChangeColor->value => \Bitrix\Im\V2\Chat::ROLE_NONE,
					Action::Rename->value => \Bitrix\Im\V2\Chat::ROLE_NONE,
					Action::Leave->value => \Bitrix\Im\V2\Chat::ROLE_NONE,
					Action::LeaveOwner->value => \Bitrix\Im\V2\Chat::ROLE_NONE,
					Action::Kick->value => \Bitrix\Im\V2\Chat::ROLE_NONE,
					Action::ChangeManagers->value => \Bitrix\Im\V2\Chat::ROLE_NONE,
					Action::Mute->value => \Bitrix\Im\V2\Chat::ROLE_NONE,
			],
				isAutoJoinEnabled: true),
		];

		return new EventResult(EventResult::SUCCESS, $parameters);
	}

	public static function onFilterUsersByAccess(FilterUsersByAccessEvent $event): EventResult
	{
		$userIds = $event->getUserIds();
		$taskId = (int)$event->getChat()->getEntityId();
		$usersWithAccess = Container::getInstance()
			->getTaskAccessService()
			->filterUsersWithAccess($taskId, $userIds)
		;

		return new EventResult(EventResult::SUCCESS, ['userIds' => $usersWithAccess]);
	}

	public static function onBeforeUsersAddExternalChatTasksTask(BeforeUsersAddEvent $event): EventResult
	{
		$userIds = $event->getUserIds();
		$taskId = (int)$event->getChat()->getEntityId();
		$config = $event->getAddUsersConfig();

		if ($config->byAutoJoin)
		{
			[, $hiddenUserIds] = Container::getInstance()
				->getTaskAccessService()
				// @todo Process TaskNotExistsException
				->filterMemberUsers($taskId, $userIds);

			$config = $config
				->addHiddenUserIds($hiddenUserIds)
			;
		}

		$config = $config
			->setWithMessage(false)
			->setHideHistory(false)
		;

		return new EventResult(EventResult::SUCCESS, ['config' => $config]);
	}

	public function addChatByTaskId(int $taskId): Result
	{
		$task = Container::getInstance()->getTaskRepository()->getById($taskId);
		if ($task === null)
		{
			return (new Result())->addError(new Error('Task not found'));
		}

		return $this->addChat($task);
	}

	public function addChat(Task $task): Result
	{
		$result = new Result();

		if (!Loader::includeModule('im'))
		{
			return $result->addError(new Error('IM module is not installed'));
		}

		$factory = ChatFactory::getInstance();
		$chatResult = $factory->addUniqueChat([
			'TITLE' => $task->title,
			'SKIP_ADD_MESSAGE' => 'Y',
			'TYPE' => \Bitrix\Im\V2\Chat::IM_TYPE_EXTERNAL,
			'ENTITY_TYPE' => self::ENTITY_TYPE,
			'ENTITY_ID' => $task->getId(),
			'USERS' => $task->getMemberIds(),
			'AUTHOR_ID' => $task->creator->id,
		]);

		if (!$chatResult->isSuccess())
		{
			return $result->addErrors($chatResult->getErrors());
		}

		$result->setData(['alreadyExists' => $chatResult->getData()['RESULT']['ALREADY_EXISTS'] ?? false]);
		$result->setId($chatResult->getChatId());

		return $result;
	}

	public function hideChat(Task $task): void
	{
		if ($task->chatId <= 0)
		{
			return;
		}

		if (!Loader::includeModule('im'))
		{
			return;
		}

		$chat = \Bitrix\Im\V2\Chat::getInstance($task->chatId);
		$dialogId = $chat->getDialogId();

		$relations = $chat->getRelations();
		foreach ($relations as $relation)
		{
			\Bitrix\Im\Recent::hide($dialogId, $relation->getUserId());
		}
	}

	/**
	 * @param int[] $membersToAdd
	 * @throws LoaderException
	 */
	public function addChatMembers(Task $task, array $membersToAdd = []): void
	{
		if (!Loader::includeModule('im'))
		{
			return;
		}

		if (!$task->chatId)
		{
			return;
		}

		if (empty($membersToAdd))
		{
			return;
		}

		\Bitrix\Im\V2\Chat::getInstance($task->chatId)?->addUsers($membersToAdd, new AddUsersConfig(hideHistory: false, withMessage: false));
	}

	public function renameChat(Task $task, Task $taskBeforeUpdate): void
	{
		if (trim($task->title) === trim($taskBeforeUpdate->title))
		{
			return;
		}

		\Bitrix\Im\V2\Chat::getInstance($task->chatId)?->setTitle($task->title)->save();
	}

	/**
	 * @param int[] $membersToHide
	 * @throws LoaderException
	 */
	public function hideChatMembers(Task $task, array $membersToHide = []): void
	{
		if (!Loader::includeModule('im'))
		{
			return;
		}

		if (!$task->chatId)
		{
			return;
		}

		if (empty($membersToHide))
		{
			return;
		}

		$chat = \Bitrix\Im\V2\Chat::getInstance($task->chatId);

		$usersWithAccess = Container::getInstance()
			->getTaskAccessService()
			->filterUsersWithAccess($task->getId(), $membersToHide)
		;
		// if users still have access to the task, just hide them
		foreach ($usersWithAccess as $userId)
		{
			$chat->hideUser($userId);
		}

		$usersWithoutAccess = array_diff($membersToHide, $usersWithAccess);
		foreach ($usersWithoutAccess as $userId)
		{
			$chat->deleteUser($userId);
		}
	}

	public function deleteChatByTaskId(int $taskId): void
	{
		$repository = Container::getInstance()->getChatRepository();

		$chat = $repository->getByTaskId($taskId);

		$chatId = $chat?->getId();

		if ($chatId <= 0)
		{
			return;
		}

		\Bitrix\Im\V2\Chat::getInstance($chatId)->deleteChat();
		$repository->delete($taskId);
	}
}
