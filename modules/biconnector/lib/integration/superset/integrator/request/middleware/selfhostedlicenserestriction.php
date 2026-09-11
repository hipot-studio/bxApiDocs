<?php

namespace Bitrix\BIConnector\Integration\Superset\Integrator\Request\Middleware;

use Bitrix\BIConnector\Integration\Superset\Integrator\Request\IntegratorRequest;
use Bitrix\BIConnector\Integration\Superset\Integrator\Request\IntegratorResponse;
use Bitrix\BIConnector\Superset\Selfhost\License\SelfHostedAvailability;
use Bitrix\Main\Error;

/**
 * Blocks control calls to a self-hosted instance while the licensing of the local mode does not allow work.
 *
 * The error text is a service one and is never shown to the user: what to show is decided by the
 * availability chain, this middleware only answers whether the call may leave the portal.
 */
class SelfHostedLicenseRestriction extends Base
{
	private const ID = 'SELFHOSTED_LICENSE_RESTRICTION';

	public static function getMiddlewareId(): string
	{
		return self::ID;
	}

	public function beforeRequest(IntegratorRequest $request): ?IntegratorResponse
	{
		// The whole chain and not the term of the extension alone: an edition that lost the right to the mode and
		// an over license of the box close the local work just as a term that is over does, while the grace after
		// a term leaves it running. The requests of the licensing itself are excluded from this middleware by the
		// integrator, so a blocked instance can still be given a renewed term and be reset.
		$availability = SelfHostedAvailability::getInstance();
		if (!$availability->isBlocked())
		{
			return null;
		}

		$state = $availability->getState();

		return new IntegratorResponse(
			IntegratorResponse::STATUS_INNER_ERROR,
			null,
			[
				new Error(
					"Request blocked by self-hosted license restriction: availability state is {$state->value}",
					IntegratorResponse::STATUS_INNER_ERROR
				),
			]
		);
	}
}
