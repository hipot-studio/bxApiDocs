<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Entity\Template;

use Bitrix\Tasks\V2\Internal\Entity\Trait\MapTypeTrait;
use Bitrix\Tasks\V2\Internal\Entity\ValueObjectInterface;

class TemplateReplicateParams implements ValueObjectInterface
{
	use MapTypeTrait;

	public function __construct(
		public readonly ?int $templateId = null,
		public readonly ?ReplicateParams $replicateParams = null,
		public readonly ?bool $replicate = null,
	)
	{
	}

	public static function mapFromArray(array $props): static
	{
		return new self(
			templateId: static::mapInteger($props, 'templateId'),
			replicateParams: static::mapValueObject($props, 'replicateParams', ReplicateParams::class),
			replicate: static::mapBool($props, 'replicate'),
		);
	}

	public function toArray(): array
	{
		return [
			'templateId' => $this->templateId,
			'replicateParams' => $this->replicateParams?->toArray(),
			'replicate' => $this->replicate,
		];
	}
}
