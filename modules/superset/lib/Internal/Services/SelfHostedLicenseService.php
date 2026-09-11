<?php

namespace Bitrix\Superset\Internal\Services;

use Bitrix\Main;
use Bitrix\Superset\Internal\Api;
use Bitrix\Superset\Internal\Connector\SupersetInstance;
use Bitrix\Superset\Internal\HttpStatus;
use Bitrix\Superset\Internal\RequestResult;
use Bitrix\Superset\Internal\Support\AbstractSupersetContext;

/**
 * Sends the self-hosted license extension expiration to the instance.
 *
 * The license expiration is an instance property of its own: the Market subscription keeps its own key and
 * is never written from here.
 */
final class SelfHostedLicenseService extends AbstractSupersetContext
{
	/**
	 * Word the instance answers with when both terms are gone. Part of the contract of the reset endpoint, so it
	 * belongs next to the request and not to the caller.
	 */
	private const RESET_MARKER = 'ok';

	/**
	 * A missing timestamp is refused instead of being sent as zero: absence of the value means "no license"
	 * for the instance, and the only way to express it is not to write anything.
	 */
	public function setExpiration(?int $timestamp, ?int $socketTimeout = null, ?int $streamTimeout = null): Main\Result
	{
		$api = new Api\SelfHostedLicense($this->resolveConnector($socketTimeout, $streamTimeout));

		return $this->sendExpiration(
			$timestamp,
			static fn(int $value) => $api->setExpirationDate($value),
			'Setting self-hosted license expiration',
		);
	}

	/**
	 * Term of the boxed portal the extension is sold for. A separate value on the instance and a separate
	 * request: the two terms change at different moments, and the portal sends the one that has changed.
	 */
	public function setBoxExpiration(
		?int $timestamp,
		?int $socketTimeout = null,
		?int $streamTimeout = null,
	): Main\Result
	{
		$api = new Api\SelfHostedLicense($this->resolveConnector($socketTimeout, $streamTimeout));

		return $this->sendExpiration(
			$timestamp,
			static fn(int $value) => $api->setBoxExpirationDate($value),
			'Setting box license expiration',
		);
	}

	/**
	 * Verdict of the portal on the edition of its box. Sent whether it allows or refuses: the value that closes
	 * an instance is the same one that opens it when the edition comes back.
	 */
	public function setEditionVerdict(
		bool $isAllowed,
		?int $socketTimeout = null,
		?int $streamTimeout = null,
	): Main\Result
	{
		$api = new Api\SelfHostedLicense($this->resolveConnector($socketTimeout, $streamTimeout));

		$requestResult = $api->setEditionVerdict($isAllowed);
		if (!$requestResult->isSuccess())
		{
			return $this->createRequestErrorResult($requestResult, 'Setting the edition verdict of the instance');
		}

		if ($requestResult->getHttpStatus() !== HttpStatus::NO_CONTENT)
		{
			return $this->createErrorResult(
				self::getUnexpectedStatusMessage($requestResult),
				$requestResult,
				$requestResult->getHttpStatus(),
			);
		}

		$result = new Main\Result();
		$result->setData([
			'status' => 'ok',
		]);

		return $result;
	}

	/**
	 * Wipes both terms on the instance, so that an instance left behind cannot keep working on a term the portal
	 * has already paid for elsewhere.
	 *
	 * Success is the answer `200` **and** the marker of the reset in its body: the writes of a term answer `204`, so
	 * their status would read a completed reset as a failure. The marker is what tells the instance from anything else
	 * that answers `200` - a login page of a mistyped address does too, and the client follows redirects.
	 */
	public function resetLicense(?int $socketTimeout = null, ?int $streamTimeout = null): Main\Result
	{
		$api = new Api\SelfHostedLicense($this->resolveConnector($socketTimeout, $streamTimeout));

		$requestResult = $api->reset();
		if (!$requestResult->isSuccess())
		{
			return $this->createRequestErrorResult($requestResult, 'Resetting the license state of the instance');
		}

		if ($requestResult->getHttpStatus() !== HttpStatus::OK)
		{
			return $this->createErrorResult(
				self::getUnexpectedStatusMessage($requestResult),
				$requestResult,
				$requestResult->getHttpStatus(),
			);
		}

		if (!self::carriesResetMarker($requestResult))
		{
			return $this->createErrorResult(
				'Resetting the license state of the instance: the answer carries no confirmation of the reset',
				$requestResult,
				$requestResult->getHttpStatus(),
			);
		}

		$result = new Main\Result();
		$result->setData([
			'status' => 'ok',
		]);

		return $result;
	}

	/**
	 * The marker of a completed reset in the answer of the instance: `{"result": {"message": "ok"}}`. A body that is
	 * not JSON, or JSON without the marker, is not a confirmation.
	 */
	private static function carriesResetMarker(RequestResult $requestResult): bool
	{
		try
		{
			$answer = Main\Web\Json::decode($requestResult->getAnswer());
		}
		catch (Main\ArgumentException)
		{
			return false;
		}

		return is_array($answer) && ($answer['result']['message'] ?? null) === self::RESET_MARKER;
	}

	/**
	 * Timeouts asked for by the caller mean a connector of its own: the defaults live in the connector and are shared
	 * by every consumer of the module. Asking for none keeps the connector of the context - the one a test replaces.
	 *
	 * Built the same way as an extended timeout of a dashboard request (`DashboardService::getDashboardApi()`).
	 */
	private function resolveConnector(?int $socketTimeout, ?int $streamTimeout): SupersetInstance
	{
		$options = [];
		if ($socketTimeout !== null)
		{
			$options['socketTimeout'] = $socketTimeout;
		}

		if ($streamTimeout !== null)
		{
			$options['streamTimeout'] = $streamTimeout;
		}

		return $options === [] ? $this->connector : new SupersetInstance($this->server, $options);
	}

	private function sendExpiration(?int $timestamp, callable $send, string $errorContext): Main\Result
	{
		if (($timestamp ?? 0) <= 0)
		{
			return $this->createErrorResult('Parameter `timestamp` is required', null, HttpStatus::BAD_REQUEST);
		}

		$requestResult = $send($timestamp);
		if (!$requestResult->isSuccess())
		{
			return $this->createRequestErrorResult($requestResult, $errorContext);
		}

		if ($requestResult->getHttpStatus() !== HttpStatus::NO_CONTENT)
		{
			return $this->createErrorResult(
				self::getUnexpectedStatusMessage($requestResult),
				$requestResult,
				$requestResult->getHttpStatus(),
			);
		}

		$result = new Main\Result();
		$result->setData([
			'status' => 'ok',
		]);

		return $result;
	}

	private static function getUnexpectedStatusMessage(RequestResult $requestResult): string
	{
		$message = trim($requestResult->getAnswer());

		return $message === '' ? 'Unexpected Superset response status' : $message;
	}
}
