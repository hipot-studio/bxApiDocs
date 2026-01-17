<?php
declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Entity\Activity\SetupTemplateActivity;

use Bitrix\Main\Type\Contract\Arrayable;

abstract class Item implements Arrayable
{
	public function toArray(): array
	{
		return [
			'itemType' => $this->getType()->value,
		];
	}

	abstract public function getType(): ItemType;
}