<?php

namespace Bitrix\BIConnector\Internal\Operation\Builder;

use Bitrix\BIConnector\Internal\Integration\AiAssistant\RateLimit\BiToolsRateLimiter;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Per-user budget of irreversible operations, shared by every entry point: it counts distinct
 * objects rather than calls, so retrying the same object is free and a sweep across many is not.
 *
 * The window is the one of BiToolsRateLimiter::consumeObject() under the same storage key as
 * the MCP tools have always used, so a switch of the channel or of a delivery flag does not
 * reset what the user has already spent.
 *
 * The entry point passes the limiter it resolved for the call, so a limiter substituted for
 * a test reaches the budget as well.
 */
final class DestructiveBudget
{
	private const LIMITER_SERVICE_CODE = 'biconnector.service.biToolsRateLimiter';

	public function __construct(private readonly BiToolsRateLimiter $limiter)
	{
	}

	/**
	 * The budget over the limiter the service code holds at the moment of the call. Built anew on every
	 * call and never kept in the service locator: a budget cached there would keep the limiter of the
	 * moment it was built, and a limiter replaced later (tests do it per case) would not reach it.
	 */
	public static function forCurrentLimiter(): self
	{
		return new self(ServiceLocator::getInstance()->get(self::LIMITER_SERVICE_CODE));
	}

	/**
	 * @param string $objectType `dashboard`, `chart` or `dataset`.
	 */
	public function spend(Actor $actor, string $objectType, int $objectId): Result
	{
		$result = new Result();

		$limit = $this->limiter->consumeObject($actor->userId, $objectType . ':' . $objectId);
		if (!$limit['allowed'])
		{
			$result->addError(new Error(
				'Destructive limit exceeded: at most ' . BiToolsRateLimiter::DESTRUCTIVE_MAX_OBJECTS
				. ' irreversible operations on distinct objects per minute. Retry in '
				. $limit['retryAfter'] . ' seconds.',
				FailureCode::DestructiveBudget->value,
			));
			// Not in the error: toMcpException() glues non-empty custom data onto the text.
			$result->setData(['retryAfter' => $limit['retryAfter']]);
		}

		return $result;
	}
}
