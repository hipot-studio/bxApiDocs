<?php
declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Entity\Activity\SetupTemplateActivity;

use Bitrix\Main\Type\Contract\Arrayable;

class Block implements Arrayable
{
	public function __construct(
		public readonly ItemCollection $items,
	) {}

	public function toArray(): array
	{
		return [
			'items' => $this->items->toArray(),
		];
	}
}