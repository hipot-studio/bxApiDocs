<?php

declare(strict_types=1);

namespace Bitrix\Imbot\V2\Controller\Chat;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Controller\Filter\CheckActionAccess;
use Bitrix\Im\V2\Permission\Action;
use Bitrix\Im\V2\RelationCollection;
use Bitrix\Im\V2\Rest\OutputFilter;
use Bitrix\Imbot\V2\Controller\BotController;
use Bitrix\Main\Engine\ActionFilter\Base;

class User extends BotController
{
	public function configureActions(): array
	{
		return [
			'add' => [
				'+prefilters' => [
					new CheckActionAccess(Action::Extend),
				],
			],
			'delete' => [
				'+prefilters' => [
					new CheckActionAccess(
						Action::Kick,
						fn (Base $filter) => (int)($filter->getAction()->getArguments()['userId'] ?? 0)
					),
				],
			],
		];
	}

	/**
	 * @restMethod imbot.v2.Chat.User.list
	 */
	public function listAction(Chat $chat, array $order = [], int $limit = self::DEFAULT_LIMIT): ?array
	{
		if (!empty($this->getErrors()))
		{
			return null;
		}

		$relationOrder = $this->prepareRelationOrder($order);
		$limit = $this->getLimit($limit);
		$relationFilter = ['ACTIVE' => true, 'CHAT_ID' => $chat->getId()];
		$relations = RelationCollection::find($relationFilter, $relationOrder, $limit);

		$result = $relations->getUsers()->toRestFormat();

		if ($this->clientId !== null)
		{
			return OutputFilter::filterUserCollection($result);
		}

		return $result;
	}

	/**
	 * @restMethod imbot.v2.Chat.User.add
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

		$chat->addUsers(array_map('intval', $userIds));

		return ['result' => true];
	}

	/**
	 * @restMethod imbot.v2.Chat.User.delete
	 */
	public function deleteAction(
		Chat $chat,
		int $userId,
	): ?array
	{
		if (!empty($this->getErrors()))
		{
			return null;
		}

		$result = $chat->deleteUser($userId);

		if (!$result->isSuccess())
		{
			$hasOnlyUserNotFound = true;
			foreach ($result->getErrors() as $error)
			{
				if ($error->getCode() !== 'USER_NOT_FOUND')
				{
					$hasOnlyUserNotFound = false;
					break;
				}
			}

			if (!$hasOnlyUserNotFound)
			{
				$this->addErrors($result->getErrors());

				return null;
			}
		}

		return ['result' => true];
	}

	private function prepareRelationOrder(array $order): array
	{
		if (isset($order['id']))
		{
			return ['ID' => strtoupper($order['id'])];
		}
		if (isset($order['lastSendMessageId']))
		{
			return ['LAST_SEND_MESSAGE_ID' => strtoupper($order['lastSendMessageId'])];
		}
		if (isset($order['userId']))
		{
			return ['USER_ID' => strtoupper($order['userId'])];
		}

		return [];
	}
}
