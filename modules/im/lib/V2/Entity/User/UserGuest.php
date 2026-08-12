<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Entity\User;

use Bitrix\Im\Model\RelationTable;
use Bitrix\Im\V2\Chat\ChatError;
use Bitrix\Im\V2\Result;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;
use Bitrix\Main\ORM\Query\Query;

class UserGuest extends UserExternal
{
	public const AUTH_ID = 'im_guest';

	public function getType(): UserType
	{
		return UserType::GUEST;
	}

	protected function checkAccessInternal(User $otherUser): Result
	{
		$result = new Result();

		if ($otherUser->isBot())
		{
			return $result;
		}

		if (!$this->hasCommonChat($otherUser->getId()))
		{
			$result->addError(new ChatError(ChatError::ACCESS_DENIED));
		}

		return $result;
	}

	protected function hasCommonChat(int $otherUserId): bool
	{
		return $this->getCommonChatPartnersQuery()
			->where('OTHER.USER_ID', $otherUserId)
			->setLimit(1)
			->fetch() !== false
		;
	}

	/**
	 * Users sharing at least one chat with this guest, private 1-1 dialogs included
	 * (a direct dialog is itself a shared context, so its partner stays findable).
	 * Single source of truth for guest visibility — used both for access checks
	 * and to scope user search to people the guest already talks to.
	 */
	public function getCommonChatPartnersQuery(): Query
	{
		return RelationTable::query()
			->setSelect(['PARTNER_ID' => 'OTHER.USER_ID'])
			->registerRuntimeField(
				'OTHER',
				new Reference(
					'OTHER',
					RelationTable::class,
					Join::on('this.CHAT_ID', 'ref.CHAT_ID'),
					['join_type' => Join::TYPE_INNER]
				)
			)
			->where('USER_ID', $this->getId())
			->whereNot('OTHER.USER_ID', $this->getId())
		;
	}
}
