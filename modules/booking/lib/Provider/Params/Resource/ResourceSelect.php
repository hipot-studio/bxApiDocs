<?php

declare(strict_types=1);

namespace Bitrix\Booking\Provider\Params\Resource;

use Bitrix\Booking\Provider\Params\SelectInterface;

class ResourceSelect implements SelectInterface
{
	protected array $select;

	public function __construct(array $select = [])
	{
		$this->select = $select;
	}

	public function prepareSelect(): array
	{
		if (empty($this->select))
		{
			return $this->getDefaultSelect();
		}

		$fields = ['*'];

		if (isset($select['TYPE']))
		{
			$fields[] = 'TYPE';
			$fields[] = 'TYPE.NOTIFICATION_SETTINGS';
		}

		if (isset($select['DATA']))
		{
			$fields[] = 'DATA';
		}

		if (isset($select['SETTINGS']))
		{
			$fields[] = 'SETTINGS';
		}

		if (isset($select['NOTIFICATION_SETTINGS']))
		{
			$fields[] = 'NOTIFICATION_SETTINGS';
		}

		if (isset($select['ENTITIES']))
		{
			$fields[] = 'ENTITIES';
		}

		return $fields;
	}

	private function getDefaultSelect(): array
	{
		return [
			'*',
			'TYPE',
			'TYPE.NOTIFICATION_SETTINGS',
			'DATA',
			'SETTINGS',
			'NOTIFICATION_SETTINGS',
			'ENTITIES',
		];
	}
}
