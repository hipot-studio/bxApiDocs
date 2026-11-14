<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Public\Dto\Member;

use Bitrix\Im\V2\Relation;
use Bitrix\Im\V2\Relation\Reason;

final class ChatMemberItem
{
	public function __construct(
		public readonly int $chatId,
		public readonly int $userId,
		public readonly Reason $reason = Reason::DEFAULT,
		public readonly bool $isHidden = false,
		public readonly bool $isMuted = false,
	)
	{}

	/**
	 * @internal
	 */
	public static function fromRelation(Relation $relation): self
	{
		return new self(
			chatId: (int)$relation->getChatId(),
			userId: (int)$relation->getUserId(),
			reason: $relation->getReason(),
			isHidden: (bool)$relation->isHidden(),
			isMuted: (bool)$relation->getNotifyBlock(),
		);
	}

	/**
	 * @internal
	 */
	public static function fromRow(array $row): self
	{
		return new self(
			chatId: (int)$row['CHAT_ID'],
			userId: (int)$row['USER_ID'],
			reason: Reason::tryFrom((string)$row['REASON']) ?? Reason::DEFAULT,
			isHidden: $row['IS_HIDDEN'] === 'Y' || $row['IS_HIDDEN'] === true,
			isMuted: $row['NOTIFY_BLOCK'] === 'Y' || $row['NOTIFY_BLOCK'] === true,
		);
	}
}
