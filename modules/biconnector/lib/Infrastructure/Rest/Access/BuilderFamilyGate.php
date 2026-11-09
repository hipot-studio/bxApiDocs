<?php

namespace Bitrix\BIConnector\Infrastructure\Rest\Access;

use Bitrix\BIConnector\Configuration\Feature;
use Bitrix\BIConnector\Internal\Feature\FlagGate;

final class BuilderFamilyGate
{
	private readonly FlagGate $flagGate;

	public function __construct(?FlagGate $flagGate = null)
	{
		$this->flagGate = $flagGate ?? new FlagGate();
	}

	public function isEnabled(int $userId): bool
	{
		return $this->flagGate->isEnabled(FlagGate::BUILDER_REST, $userId)
			&& Feature::isBitrixGptBiConstructorEnabled();
	}
}
