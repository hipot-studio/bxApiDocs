<?php

namespace Bitrix\Sign\Type;

final class EntityFileCode
{
	public const SIGNED = 0;

	/**
	 * @return array<self::*>
	 */
	public function getAll(): array
	{
		return [
			self::SIGNED,
		];
	}
}
