<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Task\Action\Update;

use Bitrix\Tasks\V2\Internal\DI\Container;
use Bitrix\Tasks\V2\Internal\Entity\Task;
use Bitrix\Tasks\V2\Internal\Entity\User;
use Bitrix\Tasks\V2\Internal\Entity\UserCollection;
use Bitrix\Tasks\V2\Internal\Repository\TaskMemberRepositoryInterface;
use Bitrix\Tasks\V2\Internal\Service\Task\Action\Update\Trait\ConfigTrait;
use Bitrix\Tasks\V2\Internal\Service\Task\Trait\MemberTrait;
use Bitrix\Tasks\V2\Internal\Service\Task\UnsubscribeService;

class UpdateMembers
{
	use ConfigTrait;
	use MemberTrait;

	protected TaskMemberRepositoryInterface $taskMemberRepository;
	private UnsubscribeService $unsubscribeService;

	private function init(): void
	{
		if (isset($this->taskMemberRepository))
		{
			return;
		}

		$container = Container::getInstance();
		$this->taskMemberRepository = $container->get(TaskMemberRepositoryInterface::class);
		$this->unsubscribeService = $container->get(UnsubscribeService::class);
	}

	public function __invoke(array $innerFields, Task $task, array $changes): array
	{
		$this->init();
		$taskId = $task->id;

		$currentMembers = $this->taskMemberRepository->get($task->id);
		$toSaveUserCollection = $this->makeMemberCollectionForSave($innerFields, $currentMembers, $task);

		if (!$toSaveUserCollection)
		{
			return $this->makeLogArray($currentMembers);
		}

		$this->taskMemberRepository->deleteAllInTask($taskId);
		$this->taskMemberRepository->saveMulti($taskId, $toSaveUserCollection);
		$this->unsubscribeExcludedUsers($taskId, $changes, $toSaveUserCollection);

		return $this->makeLogArray($toSaveUserCollection);
	}

	private function unsubscribeExcludedUsers(int $taskId, array $changes, UserCollection $existUsers): void
	{
		if (empty($changes))
		{
			return;
		}

		$toUsers = [];
		$fromUsers = [];
		foreach ([self::FIELD_ACCOMPLICES, self::FIELD_AUDITORS, self::FIELD_RESPONSIBLE_ID] as $key)
		{
			if (!array_key_exists($key, $changes))
			{
				continue;
			}

			$value = $changes[$key];
			$this->fillUserMap($fromUsers, (string)($value['FROM_VALUE'] ?? ''));
			$this->fillUserMap($toUsers, (string)($value['TO_VALUE'] ?? ''));
		}

		$excludedUsers = $this->getUniqueExcludedUsers($fromUsers, $toUsers, $existUsers);

		if ($excludedUsers)
		{
			$this->unsubscribeService->unsubscribe($taskId, $excludedUsers);
		}
	}

	private function getUniqueExcludedUsers(array $fromUserMap, array $toUserMap, UserCollection $existUsers): array
	{
		$existUserMap = [];
		$excludedUsers = [];
		foreach ($existUsers as $user)
		{
			$existUserMap[$user->id] = true;
		}

		foreach ($fromUserMap as $userId => $_)
		{
			if (isset($toUserMap[$userId]) || isset($existUserMap[$userId]))
			{
				continue;
			}

			$excludedUsers[] = (int)$userId;
		}

		return $excludedUsers;
	}

	private function fillUserMap(array &$userMap, string $userIds): void
	{
		if ($userIds === '')
		{
			return;
		}

		foreach (explode(',', $userIds) as $userId)
		{
			$userId = (int)$userId;
			if ($userId > 0)
			{
				$userMap[$userId] = true;
			}
		}
	}

	private function makeLogArray(UserCollection $members): array
	{
		$userLogInfo = [];
		/**@var User $user **/
		foreach ($members->getEntities() as $user)
		{
			$userLogInfo[] = [
				'USER_ID' => $user->id,
				'TYPE' => $user->role,
			];
		}

		return $userLogInfo;
	}
}
