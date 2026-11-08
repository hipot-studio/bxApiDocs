<?php

namespace Bitrix\BIConnector\Infrastructure\Rest\Exception\Builder;

use Bitrix\BIConnector\Internal\Operation\Builder\FailureCode;

/**
 * The request quota of builder methods is spent. The data of the quota refusal goes out with the answer,
 * so the client learns from retryAfter when a repeat may pass.
 */
class RateLimitExceededException extends BuilderRestException
{
	protected const STATUS = \CRestServer::STATUS_TO_MANY_REQUESTS;

	public function getFailureCode(): FailureCode
	{
		return FailureCode::RateLimit;
	}

	protected function getMessagePhraseCode(): string
	{
		return 'BICONNECTOR_REST_BUILDER_EXCEPTION_RATE_LIMIT';
	}
}
