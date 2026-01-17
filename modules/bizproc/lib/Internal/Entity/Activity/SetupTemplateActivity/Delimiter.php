<?php
declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Entity\Activity\SetupTemplateActivity;

class Delimiter extends Item
{
	public function __construct(
		public readonly DelimiterType $delimiterType,
	) {}

	public function toArray(): array
	{
		return parent::toArray() + [
			'delimiterType' => $this->delimiterType->value,
		];
	}

	public function getType(): ItemType
	{
		return ItemType::Delimiter;
	}
}