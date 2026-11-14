<?php

namespace Bitrix\ImOpenLines\V2\Controller;

use Bitrix\Im\V2\Controller\Chat\Pin;
use Bitrix\Im\V2\Controller\Filter\CheckActionAccess;
use Bitrix\Im\V2\Controller\Filter\CheckEntityAccess;
use Bitrix\Im\V2\Permission;
use Bitrix\Im\V2\Relation\AddUsersConfig;
use Bitrix\ImOpenLines\Integrations\HumanResources\StructureService;
use Bitrix\ImOpenLines\V2\Chat\OpenLineChatDecorator;
use Bitrix\ImOpenLines\V2\Department\DepartmentService;
use Bitrix\Main\Loader;

Loader::requireModule('im');

class Chat extends \Bitrix\Im\V2\Controller\Chat
{
	public function configureActions()
	{
		return array_merge(parent::configureActions(), [
			'addMembers' => [
				'+prefilters' => [
					new CheckEntityAccess(),
					new CheckActionAccess(Permission\Action::Extend),
				],
			],
		]);
	}

	/**
	 * @restMethod imopenlines.v2.Chat.load
	 */
	public function loadAction(
		\Bitrix\Im\V2\Chat $chat,
		int $messageLimit = \Bitrix\Im\V2\Controller\Chat\Message::DEFAULT_LIMIT,
		int $pinLimit = Pin::DEFAULT_LIMIT,
		string $ignoreMark = 'N'
	): ?array
	{
		$chatData = parent::loadAction($chat, $messageLimit, $pinLimit, $ignoreMark);
		if ($chat->getType() !== \Bitrix\Im\V2\Chat::IM_TYPE_OPEN_LINE)
		{
			return $chatData;
		}

		$userId = (int)($this->getCurrentUser()?->getId() ?? 0);
		if ($userId > 0)
		{
			\Bitrix\Im\Disk\NoRelationPermission::add($chat->getId(), $userId);
		}

		$openLineChat = new OpenLineChatDecorator($chat);

		return array_merge($chatData, $openLineChat->toRestFormat());
	}

	/**
	 * @restMethod imopenlines.v2.Chat.addMembers
	 */
	public function addMembersAction(\Bitrix\Im\V2\Chat $chat, array $userIds = [], array $departmentIds = []): ?array
	{
		$service = new DepartmentService(StructureService::getInstance());
		$resolvedUserIds = $service->resolveUserIds($userIds, $departmentIds);
		$chat->addUsers($resolvedUserIds, new AddUsersConfig(withMessage: true));

		return ['result' => true];
	}
}
