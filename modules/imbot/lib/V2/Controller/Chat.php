<?php

declare(strict_types=1);

namespace Bitrix\Imbot\V2\Controller;

use Bitrix\Im\V2\Chat as ImChat;
use Bitrix\Im\V2\Chat\ChatFactory;
use Bitrix\Im\V2\Chat\GroupChat;
use Bitrix\Im\V2\Chat\Type\TypeRegistry;
use Bitrix\Im\V2\Controller\Filter\ChatTypeFilter;
use Bitrix\Im\V2\Controller\Filter\CheckActionAccess;
use Bitrix\Im\V2\Controller\Filter\CheckFileAccess;
use Bitrix\Im\V2\Entity\File\ChatAvatar;
use Bitrix\Im\V2\Permission\Action;
use Bitrix\Im\V2\Permission\GlobalAction;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\ActionFilter\Base;

class Chat extends BotController
{
	public function configureActions(): array
	{
		return [
			'add' => [
				'+prefilters' => [
					new CheckActionAccess(
						GlobalAction::CreateChat,
						fn (Base $filter) => [
							'TYPE' => $this->getValidatedType(
								$filter->getAction()->getArguments()['fields']['type'] ?? ''
							),
							'ENTITY_TYPE' => $this->getValidatedEntityType(
								$filter->getAction()->getArguments()['fields']['entityType'] ?? null
							),
						]
					),
					new CheckFileAccess(['fields', 'avatar']),
				],
			],
			'update' => [
				'+prefilters' => [
					new CheckFileAccess(['fields', 'avatar']),
					new CheckActionAccess(Action::Update),
					new ChatTypeFilter([GroupChat::class]),
				],
			],
			'setOwner' => [
				'+prefilters' => [
					new CheckActionAccess(Action::ChangeOwner),
				],
			],
		];
	}

	/**
	 * @restMethod imbot.v2.Chat.add
	 */
	public function addAction(array $fields = []): ?array
	{
		if (!empty($this->getErrors()))
		{
			return null;
		}

		$type = $fields['type'] ?? $fields['TYPE'] ?? 'group';
		$chatType = $this->getValidatedType($type);

		$color = self::normalizeColorCode($fields['color'] ?? $fields['COLOR'] ?? '');

		$chatFields = [
			'TYPE' => $chatType,
			'TITLE' => $fields['title'] ?? $fields['TITLE'] ?? '',
			'DESCRIPTION' => $fields['description'] ?? $fields['DESCRIPTION'] ?? '',
			'COLOR' => $color,
			'AUTHOR_ID' => $fields['ownerId'] ?? $fields['OWNER_ID'] ?? $this->getBotUserId(),
			'ENTITY_TYPE' => $fields['entityType'] ?? $fields['ENTITY_TYPE'] ?? '',
			'ENTITY_ID' => $fields['entityId'] ?? $fields['ENTITY_ID'] ?? '',
			'MESSAGE' => $fields['message'] ?? $fields['MESSAGE'] ?? '',
		];

		$userIds = $fields['userIds'] ?? $fields['USER_IDS'] ?? [];
		if (!in_array($this->getBotUserId(), $userIds))
		{
			$userIds[] = $this->getBotUserId();
		}
		$chatFields['USERS'] = $userIds;

		if (!empty($fields['avatar'] ?? $fields['AVATAR'] ?? ''))
		{
			$chatFields['AVATAR'] = $fields['avatar'] ?? $fields['AVATAR'];
		}

		$chatResult = ChatFactory::getInstance()->addChat($chatFields);
		if (!$chatResult->isSuccess())
		{
			$this->addErrors($chatResult->getErrors());

			return null;
		}

		$chat = $chatResult->getChat();

		return $this->toRestFormat($chat);
	}

	/**
	 * @restMethod imbot.v2.Chat.get
	 */
	public function getAction(\Bitrix\Im\V2\Chat $chat): ?array
	{
		if (!empty($this->getErrors()))
		{
			return null;
		}

		return $this->toRestFormat($chat);
	}

	/**
	 * @restMethod imbot.v2.Chat.update
	 */
	public function updateAction(
		\Bitrix\Im\V2\Chat $chat,
		array $fields = [],
	): ?array
	{
		if (!empty($this->getErrors()))
		{
			return null;
		}

		if (isset($fields['title']))
		{
			$chat->setTitle($fields['title']);
		}

		if (isset($fields['description']))
		{
			$chat->setDescription($fields['description']);
		}

		if (isset($fields['color']))
		{
			$color = self::normalizeColorCode($fields['color']);
			if ($color !== '')
			{
				$chat->setColor($color);
			}
		}

		$result = $chat->save();
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		if (isset($fields['avatar']))
		{
			$avatarResult = (new ChatAvatar($chat))->update($fields['avatar']);
			if (!$avatarResult->isSuccess())
			{
				$this->addErrors($avatarResult->getErrors());

				return null;
			}
		}

		return ['result' => true];
	}

	/**
	 * @restMethod imbot.v2.Chat.setOwner
	 */
	public function setOwnerAction(
		\Bitrix\Im\V2\Chat $chat,
		int $userId,
	): ?array
	{
		if (!empty($this->getErrors()))
		{
			return null;
		}

		$chat->setAuthorId($userId);
		$result = $chat->save();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return ['result' => true];
	}

	/**
	 * @restMethod imbot.v2.Chat.leave
	 */
	public function leaveAction(\Bitrix\Im\V2\Chat $chat): ?array
	{
		if (!empty($this->getErrors()))
		{
			return null;
		}

		$result = $chat->deleteUser($this->getBotUserId());

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return ['result' => true];
	}

	private function getValidatedType(?string $type): string
	{
		return match ($type)
		{
			'open' => ImChat::IM_TYPE_OPEN,
			default => ImChat::IM_TYPE_CHAT,
		};
	}

	private function getValidatedEntityType(?string $entityType): ?string
	{
		return ServiceLocator::getInstance()->get(TypeRegistry::class)->getValidatedEntityType($entityType);
	}
}
