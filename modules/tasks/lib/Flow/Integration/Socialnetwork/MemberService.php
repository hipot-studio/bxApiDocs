<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Flow\Integration\Socialnetwork;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Loader;
use Bitrix\Main\LoaderException;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;
use Bitrix\Main\Type\Collection;
use Bitrix\Socialnetwork\Control\Member\Command\MembersCommand;
use Bitrix\Socialnetwork\Control\ServiceFactory;
use Bitrix\Socialnetwork\Internals\Registry\GroupRegistry;

class MemberService
{
	/**
	 * @throws ArgumentException
	 * @throws LoaderException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function addMembers(int $groupId, array $userIds, int $initiatorId = 0): void
	{
		if (!Loader::includeModule('socialnetwork'))
		{
			return;
		}

		$userIds = $this->normalizeUserIds($userIds);
		if (empty($userIds))
		{
			return;
		}

		$group = GroupRegistry::getInstance()->get($groupId);
		if ($group === null)
		{
			return;
		}

		$members = array_map(static fn(int $userId): string => 'U' . $userId, $userIds);

		$command = (new MembersCommand())
			->setGroupId($groupId)
			->setMembers($members)
			->setInitiatorId($initiatorId)
		;

		ServiceFactory::getInstance()
			->getMemberServiceByEntityType($group->getType())
			->add($command)
		;
	}

	private function normalizeUserIds(array $userIds): array
	{
		Collection::normalizeArrayValuesByInt($userIds, false);

		return $userIds;
	}
}
