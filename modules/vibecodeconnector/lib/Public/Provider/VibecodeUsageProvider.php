<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Public\Provider;

use Bitrix\Main\Loader;
use Bitrix\Rest\Public\Provider\ApplicationProvider;
use Bitrix\Rest\Public\Provider\Params\ApplicationFilter;
use Bitrix\Vibecodeconnector\Internal\Integration\Rest\VibecodeArtifactAttributes;

final class VibecodeUsageProvider
{
	private readonly ApplicationProvider $applicationProvider;

	public function __construct(?ApplicationProvider $applicationProvider = null)
	{
		Loader::includeModule('rest');
		$this->applicationProvider = $applicationProvider ?? new ApplicationProvider();
	}

	public function hasUsage(): bool
	{
		return $this->applicationProvider->hasAny(new ApplicationFilter(
			installed: true,
			attributes: VibecodeArtifactAttributes::VIBECODE_OWNED,
		));
	}

	public function getExcludedAttributes(): array
	{
		return VibecodeArtifactAttributes::VIBECODE_OWNED;
	}
}
