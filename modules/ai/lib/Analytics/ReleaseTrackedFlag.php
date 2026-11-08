<?php

declare(strict_types=1);

namespace Bitrix\AI\Analytics;

use Bitrix\Main\Config\Feature\AbstractFlag;
use Bitrix\Main\Config\Feature\Factory;
use Bitrix\Main\Loader;
use Throwable;

/**
 * Base class for core package feature flags whose release date must reach analytics.
 *
 * Extending this class is itself the statement "this flag is a release", so there is no
 * selection here and the exclusion list of option flags takes no part. There is no `'Y'` rule
 * either: the package calls the enable hook only when the value really changed in the database.
 *
 * A heir declares only getReleaseId(). Do not override enabledByDefault() to true - such a flag
 * is on from birth and its hook never fires.
 */
abstract class ReleaseTrackedFlag extends AbstractFlag
{
	private const POLICY_MODULE_ID = 'ai';

	/**
	 * Returns the feature identifier in lowerCamelCase, without the `flag_` prefix.
	 *
	 * Declared explicitly and never derived from getCode(): the package code of a flag is its
	 * fully qualified class name, and a conversion of a class name is not injective - two
	 * differently named classes collapse into one identifier, and a class named after an
	 * existing option collapses into the identifier of that option.
	 */
	abstract protected function getReleaseId(): string;

	/**
	 * Closed for overriding on purpose: a heir that redefines the hook without calling the parent
	 * would switch the analytics off silently. Heirs extend through onReleaseTracked().
	 */
	final protected function onEnable(): void
	{
		// All counter commands of a portal are executed by one process, so an unhandled error
		// here would break the counters of other, unrelated rollouts.
		try
		{
			$tracker = $this->resolveTracker();
			if ($tracker === null)
			{
				return;
			}

			$tracker->handleFlagRelease($this->getReleaseId());

			$this->onReleaseTracked();
		}
		catch (Throwable)
		{
		}
	}

	/**
	 * The only point a heir extends: called after the release of the flag has been reported.
	 */
	protected function onReleaseTracked(): void
	{
	}

	/**
	 * Returns the release policy, or null when the module holding it is unreachable.
	 *
	 * Only the module of the flag itself is guaranteed to be loaded, so the policy module is
	 * loaded here explicitly.
	 *
	 * Private for the same reason onEnable() is final: a heir returning null from here would
	 * switch the analytics off just as silently.
	 */
	private function resolveTracker(): ?FeatureReleaseTracker
	{
		if (!Loader::includeModule(self::POLICY_MODULE_ID))
		{
			return null;
		}

		$moduleId = (new Factory())->resolveModule(static::class);
		if ($moduleId === null)
		{
			return null;
		}

		return new FeatureReleaseTracker($moduleId);
	}
}
