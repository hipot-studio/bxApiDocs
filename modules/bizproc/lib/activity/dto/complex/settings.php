<?php

namespace Bitrix\Bizproc\Activity\Dto\Complex;

use Bitrix\Main\Type\Contract\Arrayable;

final class Settings implements Arrayable, \JsonSerializable
{
	public function __construct(
		public readonly NodeActionDictionary $actionDictionary,
	) {}

	public function toArray(): array
	{
		return [
			'actionDictionary' => $this->actionDictionary->toArray(),
		];
	}

	public function jsonSerialize(): array
	{
		return $this->toArray();
	}
}
