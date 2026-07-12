<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Integration\Im\Service;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Relation\AddUsersConfig;
use Bitrix\Im\V2\Relation\Reason;
use Bitrix\Main\Loader;
use Bitrix\Socialnetwork\V2\Internal\Repository\CollabOptionRepository;

class ChatMemberService
{
	public function __construct(
		private readonly ProjectChatResolver $projectChatResolver,
		private readonly CollabOptionRepository $collabOptionRepository,
	)
	{
	}

	/**
	 * @param int[] $userIds
	 */
	public function addUsersAsStructureMembers(int $projectId, array $userIds): void
	{
		if (empty($userIds) || !Loader::includeModule('im'))
		{
			return;
		}

		$chatId = $this->projectChatResolver->getByProjectId($projectId);
		if ($chatId === null)
		{
			return;
		}

		$config = new AddUsersConfig(
			hideHistory: !$this->collabOptionRepository->shouldShowHistory($projectId),
			withMessage: false,
			skipRecent: true,
			reason: Reason::STRUCTURE,
		);

		Chat::getInstance($chatId)
			->withContextUser(0)
			->addUsers($userIds, $config)
		;
	}
}
