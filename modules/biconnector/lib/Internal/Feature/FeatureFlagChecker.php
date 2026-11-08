<?php

namespace Bitrix\BIConnector\Internal\Feature;

use Bitrix\Main\Config\Feature;
use Bitrix\Main\Config\Feature\Context;

/**
 * The class feature flags of the kernel behind one seam: a caller asks whether the API is there
 * before it names a flag, so a delivery without the API answers no instead of failing.
 */
class FeatureFlagChecker
{
	public function isClassAvailable(string $class): bool
	{
		return class_exists($class);
	}

	public function isEnabled(string $flagClass, Context $context): bool
	{
		return Feature::isEnabled($flagClass, $context);
	}
}
