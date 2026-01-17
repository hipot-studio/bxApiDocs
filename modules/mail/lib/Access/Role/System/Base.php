<?php

namespace Bitrix\Mail\Access\Role\System;

abstract class Base
{
	abstract public function getPermissions(): array;

	public function getMap(): array
	{
		$result = [];
		$permissions = $this->getPermissions();
		foreach ($permissions as $permissionId => $value)
		{
			$result[] = [
				'id' => (string)$permissionId,
				'value' => (int)$value,
			];
		}

		return $result;
	}
}