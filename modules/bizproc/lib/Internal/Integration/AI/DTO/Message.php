<?php

namespace Bitrix\Bizproc\Internal\Integration\AI\DTO;

use Bitrix\Bizproc\Internal\Integration\AI\Enum\HistoryRole;
use Bitrix\Main\Type\Contract\Arrayable;

class Message implements Arrayable
{
	public function __construct(
		public readonly string $content,
		public readonly HistoryRole $role,
	) {}

	public function toArray(): array
	{
		return [
			'content' => $this->content,
			'role' => $this->role->value,
		];
	}
}