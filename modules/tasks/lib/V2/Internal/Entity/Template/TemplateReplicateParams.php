<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Entity\Template;

use Bitrix\Tasks\Replication\Template\Enum\RegularityType;
use Bitrix\Tasks\V2\Internal\Entity\Trait\MapTypeTrait;
use Bitrix\Tasks\V2\Internal\Entity\ValueObjectInterface;

class TemplateReplicateParams implements ValueObjectInterface
{
	use MapTypeTrait;

	public function __construct(
		public readonly ?int $templateId = null,
		public readonly ?ReplicateParams $replicateParams = null,
		public readonly ?bool $replicate = null,
		public readonly ?RegularityType $regularityType = null,
		public readonly ?bool $templateDeleted = null,
	)
	{
	}

	public static function mapFromArray(array $props): static
	{
		return new self(
			templateId: static::mapInteger($props, 'templateId'),
			replicateParams: static::mapValueObject($props, 'replicateParams', ReplicateParams::class),
			replicate: static::mapBool($props, 'replicate'),
			regularityType: static::mapBackedEnum($props, 'regularityType', RegularityType::class),
			templateDeleted: static::mapBool($props, 'templateDeleted'),
		);
	}

	public function toArray(): array
	{
		return [
			'templateId' => $this->templateId,
			'replicateParams' => $this->replicateParams?->toArray(),
			'replicate' => $this->replicate,
			'regularityType' => $this->regularityType?->value,
			'templateDeleted' => $this->templateDeleted,
		];
	}
}
