<?php
declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Entity\Activity\SetupTemplateActivity;

class TitleWithIcon extends Item
{
	public function __construct(
		public readonly string $text,
		public readonly string $icon,
	) {}

	public function toArray(): array
	{
		return parent::toArray() + [
			'text' => $this->text,
			'icon' => $this->icon,
		];
	}

	public function getType(): ItemType
	{
		return ItemType::TitleWithIcon;
	}

	/**
	 * @return list<string>
	 */
	public static function getAllowedIconValues(): array
	{
		return [
			'IMAGE',
			'ATTACH',
			'SETTINGS',
			'STARS',
		];
	}
}
