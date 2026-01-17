<?php

namespace Bitrix\Recyclebin\Internals\UserFieldManager;

class BaseField
{
	public function __construct(
		protected readonly array $userField
	)
	{
	}

	public function onEraseFromRecycleBin(&$value): void
	{
	}

	public function onMoveToRecycleBin(&$value): void
	{
	}

	public function onRestoreFromRecycleBin(&$value): void
	{
	}
}
