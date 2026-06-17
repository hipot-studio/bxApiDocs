<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Entity\Template;

use Bitrix\Tasks\V2\Internal\Entity\AbstractEntity;
use Bitrix\Tasks\V2\Internal\Entity\Trait\MapTypeTrait;

class SubTemplate extends AbstractEntity
{
	use MapTypeTrait;

	public function __construct(
		public readonly ?int $templateId = null,
		public readonly ?int $parentTemplateId = null,
		public readonly ?bool $direct = null,
	)
	{
	}

	public function getId(): string
	{
		return $this->templateId . '_' . $this->parentTemplateId;
	}

	public static function mapFromArray(array $props): static
	{
		return new static(
			templateId: static::mapInteger($props, 'templateId'),
			parentTemplateId: static::mapInteger($props, 'parentTemplateId'),
			direct: static::mapBool($props, 'direct'),
		);
	}

	public function toArray(): array
	{
		return [
			'templateId' => $this->templateId,
			'parentTemplateId' => $this->parentTemplateId,
			'direct' => $this->direct,
		];
	}
}
