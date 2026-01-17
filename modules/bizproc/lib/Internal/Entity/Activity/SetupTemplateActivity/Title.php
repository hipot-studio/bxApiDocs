<?php
declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Entity\Activity\SetupTemplateActivity;

class Title extends Item
{
	public function __construct(
		public readonly string $text = '',
	) {}

	public function toArray(): array
	{
		return parent::toArray() + [
			'text' => $this->text,
		];
	}

	public function getType(): ItemType
	{
		return ItemType::Title;
	}
}