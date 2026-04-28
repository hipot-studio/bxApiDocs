<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Reading\Counter\Entity;

use Bitrix\Im\V2\Chat\Type;
use Bitrix\Im\V2\Common\WithableTrait;
use Bitrix\Im\V2\Rest\RestConvertible;

/**
 * @method self with(int $chatId = null, int $counter = null,int $parentChatId = null,bool $isMuted = null,bool $isMarkedAsUnread = null,array $recentSections = null,Type $type = null)
 */
final class ChatCounter implements RestConvertible
{
	use WithableTrait;

	public function __construct(
		public readonly int $chatId,
		public readonly int $counter,
		public readonly int $parentChatId,
		public readonly bool $isMuted,
		public readonly bool $isMarkedAsUnread,
		public readonly array $recentSections,
		public readonly Type $type,
	) {}

	public static function getRestEntityName(): string
	{
		return 'chatCounter';
	}

	public function toRestFormat(array $option = []): ?array
	{
		return [
			'chatId' => $this->chatId,
			'counter' => $this->counter,
			'parentChatId' => $this->parentChatId,
			'isMuted' => $this->isMuted,
			'isMarkedAsUnread' => $this->isMarkedAsUnread,
			'recentSections' => $this->recentSections,
		];
	}
}
