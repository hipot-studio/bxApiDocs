<?php

namespace Bitrix\BIConnector\Internal\Feature;

use Bitrix\Main\Config\Feature;
use Bitrix\Main\Config\Feature\AbstractFlag;
use Bitrix\Main\Config\Feature\Context;

class SqlLabRestGate
{
	public const PLACEMENT_CLOUD = 'cloud';
	public const PLACEMENT_SELF_HOSTED = 'self-hosted';
	public const PLACEMENTS = [self::PLACEMENT_CLOUD, self::PLACEMENT_SELF_HOSTED];

	// Feature flags live in the namespace declared by biconnector/.settings.php
	// (feature-flags.defaultNamespace); referenced by name to keep Internal free of Public.
	private const FLAG_CLASS = 'Bitrix\\BIConnector\\Public\\Features\\SqlLabRest';

	private readonly FeatureFlagChecker $flagChecker;
	private readonly SupersetPlacementResolver $placementResolver;

	public function __construct(
		?FeatureFlagChecker $flagChecker = null,
		?SupersetPlacementResolver $placementResolver = null,
		private readonly string $flagClass = self::FLAG_CLASS,
	)
	{
		$this->flagChecker = $flagChecker ?? new FeatureFlagChecker();
		$this->placementResolver = $placementResolver ?? new SupersetPlacementResolver();
	}

	public function isEnabled(int $userId): bool
	{
		if ($userId <= 0 || !$this->flagChecker->isClassAvailable(Feature::class))
		{
			return false;
		}

		if (
			!$this->flagChecker->isClassAvailable(AbstractFlag::class)
			|| !$this->flagChecker->isClassAvailable($this->flagClass)
		)
		{
			return false;
		}

		if (!is_subclass_of($this->flagClass, AbstractFlag::class))
		{
			return false;
		}

		$placement = $this->placementResolver->resolve();
		if (!in_array($placement, self::PLACEMENTS, true))
		{
			return false;
		}

		try
		{
			return $this->flagChecker->isEnabled(
				$this->flagClass,
				new Context($userId, ['placement' => $placement]),
			);
		}
		catch (\Throwable)
		{
			return false;
		}
	}
}
