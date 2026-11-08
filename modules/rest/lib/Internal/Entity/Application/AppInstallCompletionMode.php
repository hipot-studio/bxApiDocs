<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Entity\Application;

enum AppInstallCompletionMode: string
{
	case Immediate = 'immediate';
	case Deferred = 'deferred';

	/**
	 * Single predicate for "the application has no page". A menu title made of spaces is not a page.
	 * REST controllers, the edit form and the preset provider must agree on it: a mode derived from a
	 * wrong answer travels down the chain as an explicit one and overrides the correct detection.
	 */
	public static function isApiOnly(array $menuTitles): bool
	{
		foreach ($menuTitles as $menuTitle)
		{
			if (trim((string)$menuTitle) !== '')
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * Single rule for both REST and internal install paths.
	 * An explicitly requested mode is absolute: neither an empty install URL nor an API-only
	 * integration overrides it, so an explicit 'deferred' keeps waiting for the application to
	 * confirm the install. Without an explicit mode the completion mode is derived: empty install URL
	 * or API-only integration completes immediately, otherwise deferred.
	 */
	public static function resolve(string $installUrl, bool $onlyApi, ?string $explicit): self
	{
		$explicitMode = self::tryFrom((string)$explicit);
		if ($explicitMode !== null)
		{
			return $explicitMode;
		}

		if ($installUrl === '' || $onlyApi)
		{
			return self::Immediate;
		}

		return self::Deferred;
	}
}
