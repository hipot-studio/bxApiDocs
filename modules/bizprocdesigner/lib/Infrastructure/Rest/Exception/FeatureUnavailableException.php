<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Rest\Exception;

use Bitrix\Rest\V3\Exception\RestException;
use Bitrix\Rest\V3\Exception\SkipWriteToLogException;

/**
 * The external agent contour is not available on this portal, or not to this user.
 *
 * A code of its own, and that is the whole reason this class exists. Answered as AccessDeniedException -
 * which is what the contour did - it is indistinguishable from "your rights are not enough", and the two
 * ask opposite things of the caller: a right can be granted by an administrator of the portal and acted
 * on today, while a feature that is off on the portal ends the conversation. An agent told the wrong one
 * either keeps asking a portal that will never answer, or gives up where a permission would have helped.
 *
 * The HTTP status stays 403: nothing about the transport changed, and a caller that only reads statuses
 * reads exactly what it read before. The difference travels where a caller can branch on it - the code.
 *
 * Out of the exception log for the same reason AccessDeniedException is: a portal without the feature is
 * a state of the portal, not an incident, and the callers of the entry probe for it on purpose.
 */
final class FeatureUnavailableException extends RestException implements SkipWriteToLogException
{
	use RussianMessageFallback;

	public const CODE = 'BIZPROCDESIGNER_FEATURE_UNAVAILABLE';

	protected const STATUS = \CRestServer::STATUS_FORBIDDEN;

	public function getRegistryCode(): string
	{
		return self::CODE;
	}

	protected function getMessagePhraseCode(): string
	{
		return 'BIZPROCDESIGNER_REST_EXCEPTION_FEATURE_UNAVAILABLE';
	}
}
