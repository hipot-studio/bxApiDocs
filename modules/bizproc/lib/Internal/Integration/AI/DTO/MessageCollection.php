<?php

namespace Bitrix\Bizproc\Internal\Integration\AI\DTO;

use Bitrix\Bizproc\Internal\Entity\AbstractCollection;
use Bitrix\Main\Type\Contract\Arrayable;

/**
 * @extends AbstractCollection<Message>
 */
class MessageCollection extends AbstractCollection implements Arrayable
{

	protected function isValidItem(mixed $item): bool
	{
		return $item instanceof Message;
	}

	public function toArray(): array
	{
		return [
			'messages' => array_map(static fn(Message $message): array => $message->toArray(), $this->items),
		];
	}
}