<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Entity\User;

use Bitrix\Im\Model\RelationTable;
use Bitrix\Im\V2\Chat\ChatError;
use Bitrix\Im\V2\Result;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;

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
		$row = RelationTable::query()
			->setSelect(['CHAT_ID'])
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
			->where('OTHER.USER_ID', $otherUserId)
			->whereNot('MESSAGE_TYPE', \IM_MESSAGE_PRIVATE)
			->setLimit(1)
			->fetch()
		;

		return $row !== false;
	}
}
