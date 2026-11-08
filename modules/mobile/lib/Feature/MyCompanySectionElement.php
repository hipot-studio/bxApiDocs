<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Feature;

use Bitrix\Mobile\Config\Feature;

/**
 * Flag of an element of the "My company" section. The element does not exist without the section, so
 * the rollout of the section is part of the flag itself instead of a condition repeated at every
 * place the flag is read.
 *
 * The rollout flag of the section is the whole of the check here: isEnabled() is handed no user, so
 * the employee half of MyCompanyProvider::isAvailable() stays with the callers that do have one.
 */
trait MyCompanySectionElement
{
	public function isEnabled(): bool
	{
		return Feature::isEnabled(MyCompanyFeature::class) && $this->isOptionEnabled();
	}

	/**
	 * The own option of the element, read through FeatureFlag::isOptionOn().
	 */
	abstract protected function isOptionEnabled(): bool;
}
