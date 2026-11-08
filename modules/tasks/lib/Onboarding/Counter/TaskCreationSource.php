<?php

declare(strict_types=1);

namespace Bitrix\Tasks\Onboarding\Counter;

use Bitrix\Main\Context;

/**
 * Single source of truth for "where did a task creation come from".
 *
 * Onboarding counters (see OnboardingCounterService) must grow only for
 * interactive creations: the web interface (internal ajax / forms) and the
 * mobile application (iOS/Android). Public REST API creations and
 * system/agent creations (CLI, no HTTP context) must not be counted
 * (PRD AC-019, ERR-006).
 *
 * The decision is a positive gate: it returns true only when the creation can
 * be affirmatively attributed to the web or mobile clients.
 */
final class TaskCreationSource
{
	/**
	 * True only for web and mobile creations; false for public REST and
	 * system/agent creations (CLI, no HTTP context).
	 */
	public static function isInteractive(): bool
	{
		// System/agent creations have no HTTP request context.
		if (!self::hasHttpContext())
		{
			return false;
		}

		// Public REST API (classic CRestServer: webhooks, apps, scope=rest).
		if (self::isPublicRest())
		{
			return false;
		}

		// Remaining HTTP creations are interactive: web (internal ajax / create
		// forms) and the mobile application (iOS/Android), which both run under
		// an HTTP request and are not the public REST server.
		return true;
	}

	private static function hasHttpContext(): bool
	{
		if (PHP_SAPI === 'cli')
		{
			return false;
		}

		$context = Context::getCurrent();
		if ($context === null)
		{
			return false;
		}

		return $context->getRequest() !== null;
	}

	private static function isPublicRest(): bool
	{
		// Do not autoload CRestServer: if it is not already loaded there cannot be
		// a public REST context, so skip the autoload on every web task creation.
		return class_exists(\CRestServer::class, false) && \CRestServer::instance() !== null;
	}
}
