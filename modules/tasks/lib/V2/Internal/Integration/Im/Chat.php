<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im;

use Bitrix\Im;
use Bitrix\Im\V2\Chat\ExternalChat\Event\BeforeUsersAddEvent;
use Bitrix\Main\Error;
use Bitrix\Main\EventResult;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Im\V2\Chat\ExternalChat\Event\RegisterTypeEvent;
use Bitrix\Im\V2\Chat\ExternalChat\Config;
use Bitrix\Im\V2\Permission\Action;
use Bitrix\Im\V2\Chat\ExternalChat\Event\FilterUsersByAccessEvent;
use Bitrix\Im\V2\Relation\AddUsersConfig;
use Bitrix\Im\V2\Chat\ChatFactory;
use Bitrix\Tasks\V2\Internal\Integration\Socialnetwork\Service\ProjectChatResolver;
use Bitrix\Tasks\V2\Internal\Result\Result;

class Chat
{
	public const ENTITY_TYPE = 'TASKS_TASK';

	protected function getProjectChatResolver(): ProjectChatResolver
	{
		return Container::getInstance()->getProjectChatResolver();
	}

	protected function getImChat(int $chatId): Im\V2\Chat
	{
		return Im\V2\Chat::getInstance($chatId);
	}

	protected function getChatAvatar(): ChatAvatar
	{
		return new ChatAvatar();
	}

	public function muteChat(int $chatId, int $userId, bool $action): bool
	{
		if (!Loader::includeModule('im'))
		{
			return false;
		}

		return Im\Chat::mute($chatId, $action, $userId);
	}

	public static function onRegisterType(RegisterTypeEvent $event): EventResult
	{
		$parameters = [
			'type' => self::ENTITY_TYPE,
			'config' => new Config(
				hasOwnRecentSection: true,
				permissions: [
					Action::Extend->value => Im\V2\Chat::ROLE_MEMBER,
					Action::ChangeAvatar->value => Im\V2\Chat::ROLE_NONE,
					Action::ChangeDescription->value => Im\V2\Chat::ROLE_NONE,
					Action::ChangeColor->value => Im\V2\Chat::ROLE_NONE,
					Action::Rename->value => Im\V2\Chat::ROLE_NONE,
					Action::Leave->value => Im\V2\Chat::ROLE_NONE,
					Action::LeaveOwner->value => Im\V2\Chat::ROLE_NONE,
					Action::Kick->value => Im\V2\Chat::ROLE_NONE,
					Action::ChangeManagers->value => Im\V2\Chat::ROLE_NONE,
					Action::Mute->value => Im\V2\Chat::ROLE_MEMBER,
				],
				isAutoJoinEnabled: true,
				requiresParentMembership: false,
			),
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

		$chatAvatar = $this->getChatAvatar();
		$avatarType = $chatAvatar->getTypeByTask($task);
		$avatarId = $chatAvatar->ensureFileId($avatarType);
		$avatarEnabled = $chatAvatar->isEnabled();
		if ($avatarEnabled && $avatarId <= 0)
		{
			return $result->addError(new Error('Task chat avatar file is not available'));
		}

		$factory = ChatFactory::getInstance();
		$chatResult = $factory->addUniqueChat([
			'TITLE' => $task->title,
			'SKIP_ADD_MESSAGE' => 'Y',
			'TYPE' => Im\V2\Chat::IM_TYPE_EXTERNAL,
			'ENTITY_TYPE' => self::ENTITY_TYPE,
			'ENTITY_ID' => $task->getId(),
			'USERS' => $task->getMemberIds(),
			'AUTHOR_ID' => $task->creator->id,
			'AVATAR' => $avatarId > 0 ? $avatarId : null,
			'PARENT_ID' => $this->getProjectChatResolver()->getParentChatIdForGroup($task->group),
		]);

		if (!$chatResult->isSuccess())
		{
			return $result->addErrors($chatResult->getErrors());
		}

		$chatId = (int)$chatResult->getChatId();
		$alreadyExists = ($chatResult->getData()['RESULT']['ALREADY_EXISTS'] ?? false) === true;

		if ($alreadyExists && $chatId > 0)
		{
			$this->updateParentChat($task->cloneWith(['chatId' => $chatId]));
		}
		if ($chatId > 0 && $avatarEnabled && $alreadyExists)
		{
			$avatarResult = $this->updateChatAvatar($task->cloneWith(['chatId' => $chatId]), $avatarType);
			if (!$avatarResult->isSuccess())
			{
				return $result->addErrors($avatarResult->getErrors());
			}
		}

		$result->setData(['alreadyExists' => $alreadyExists]);
		$result->setId($chatId);

		return $result;
	}

	public function updateParentChat(Task $task): void
	{
		if (!Loader::includeModule('im'))
		{
			return;
		}

		if ($task->chatId <= 0)
		{
			return;
		}

		$chat = $this->getImChat($task->chatId);
		if (($chat->getChatId() ?? 0) <= 0)
		{
			return;
		}

		$parentChatId = $this->getProjectChatResolver()->getParentChatIdForGroup($task->group);
		if ((int)($chat->getParentChatId() ?? 0) === $parentChatId)
		{
			return;
		}

		$chat
			->setParentChatId($parentChatId)
			->save()
		;
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

		$chat = $this->getImChat($task->chatId);
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

		$this->getImChat($task->chatId)->addUsers($membersToAdd, new AddUsersConfig(hideHistory: false, withMessage: false));
	}

	public function renameChat(Task $task, Task $taskBeforeUpdate): void
	{
		if (trim($task->title) === trim($taskBeforeUpdate->title))
		{
			return;
		}

		$this->getImChat((int)$task->chatId)->setTitle($task->title)->save();
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

		$chat = $this->getImChat($task->chatId);

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

		$this->getImChat($chatId)->deleteChat();
		$repository->delete($taskId);
	}

	public function updateChatAvatar(Task $task, ChatAvatarType $avatarType): Result
	{
		$result = new Result();

		if (!Loader::includeModule('im'))
		{
			return $result->addError(new Error('IM module is not installed'));
		}

		if (!$task->chatId)
		{
			return $result;
		}

		$chat = $this->getImChat($task->chatId);
		if (($chat->getChatId() ?? 0) <= 0)
		{
			return $result;
		}

		$previousAvatarFileId = (int)$chat->getAvatarId();
		if ($previousAvatarFileId > 0 && !$this->isSystemAvatarFileId($previousAvatarFileId))
		{
			return $result;
		}

		$avatarFileId = $this->getTaskChatAvatarFileId($avatarType);
		if (empty($avatarFileId))
		{
			return $result->addError(new Error('Task chat avatar file is not available'));
		}

		if ($previousAvatarFileId === $avatarFileId)
		{
			return $result;
		}

		$avatarResult = $this->updateSystemAvatar($chat, $avatarFileId);
		if (!$avatarResult->isSuccess())
		{
			$chat->setAvatarId($previousAvatarFileId);
			Im\V2\Chat::cleanCache($task->chatId);

			return $result->addErrors($avatarResult->getErrors());
		}

		return $result;
	}

	protected function getTaskChatAvatarFileId(ChatAvatarType $avatarType): int
	{
		return (new ChatAvatar())->ensureFileId($avatarType);
	}

	protected function isSystemAvatarFileId(int $fileId): bool
	{
		return (new ChatAvatarPublisher())->isSystemFileId($fileId);
	}

	protected function updateSystemAvatar(Im\V2\Chat $chat, int $avatarFileId): Im\V2\Result
	{
		return (new Im\V2\Entity\File\ChatAvatar($chat))->updateSystemAvatar(
			avatar: $avatarFileId,
			withMessage: false,
			skipRecent: true,
		);
	}
}
