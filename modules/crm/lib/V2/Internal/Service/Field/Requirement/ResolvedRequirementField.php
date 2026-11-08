<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Field\Requirement;

final readonly class ResolvedRequirementField
{
	public function __construct(
		public string $fieldId,
		public ?string $legacyRequirementFieldName,
		public bool $globallyRequired,
	)
	{
	}

	public function hasLegacyRequirementKey(): bool
	{
		return $this->legacyRequirementFieldName !== null;
	}
}
