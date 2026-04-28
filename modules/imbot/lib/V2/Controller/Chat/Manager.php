<?php

declare(strict_types=1);

namespace Bitrix\Imbot\V2\Controller\Chat;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\GroupChat;
use Bitrix\Im\V2\Controller\Filter\ChatTypeFilter;
use Bitrix\Im\V2\Controller\Filter\CheckActionAccess;
use Bitrix\Im\V2\Permission\Action;
use Bitrix\Imbot\V2\Controller\BotController;

class Manager extends BotController
{
	public function configureActions(): array
	{
		return [
			'add' => [
				'+prefilters' => [
					new CheckActionAccess(Action::ChangeManagers),
					new ChatTypeFilter([GroupChat::class]),
				],
			],
			'delete' => [
				'+prefilters' => [
					new CheckActionAccess(Action::ChangeManagers),
					new ChatTypeFilter([GroupChat::class]),
				],
			],
		];
	}

	/**
	 * @restMethod imbot.v2.Chat.Manager.add
	 */
	public function addAction(
		Chat $chat,
		array $userIds = [],
	): ?array
	{
		if (!empty($this->getErrors()))
		{
			return null;
		}

		if (empty($userIds))
		{
			$this->addError(new \Bitrix\Main\Error('userIds is required', 'EMPTY_USER_IDS'));

			return null;
		}

		if ($chat instanceof GroupChat)
		{
			$chat->addManagers(array_map('intval', $userIds));
		}

		return ['result' => true];
	}

	/**
	 * @restMethod imbot.v2.Chat.Manager.delete
	 */
	public function deleteAction(
		Chat $chat,
		array $userIds = [],
	): ?array
	{
		if (!empty($this->getErrors()))
		{
			return null;
		}

		if (empty($userIds))
		{
			$this->addError(new \Bitrix\Main\Error('userIds is required', 'EMPTY_USER_IDS'));

			return null;
		}

		if ($chat instanceof GroupChat)
		{
			$chat->deleteManagers(array_map('intval', $userIds));
		}

		return ['result' => true];
	}
}
