<?php

namespace Bitrix\BIConnector\ExternalSource\Source;

use Bitrix\Main\Error;
use Bitrix\Main\SystemException;

/**
 * A request of a REST connector failed for a known reason, in the diagnostics mode of the transport.
 *
 * The message is the localized description of the reason and names the address only, never its url
 * nor the text of the client or of the application, so it can be shown to the user as it is.
 */
final class ConnectionFailureException extends SystemException
{
	public function __construct(
		private readonly ConnectionFailure $failure,
		private readonly string $address,
		private readonly ?int $status = null,
	)
	{
		parent::__construct($failure->describe($address, $status));
	}

	/**
	 * Reads back an error of the diagnostics mode; null for an error that names no known reason.
	 */
	public static function fromError(?Error $error): ?self
	{
		$failure = $error === null ? null : ConnectionFailure::tryFrom((string)$error->getCode());
		if ($failure === null)
		{
			return null;
		}

		$customData = (array)$error->getCustomData();
		$status = $customData['status'] ?? null;

		return new self($failure, (string)($customData['address'] ?? ''), $status === null ? null : (int)$status);
	}

	public function getFailure(): ConnectionFailure
	{
		return $this->failure;
	}

	public function getAddress(): string
	{
		return $this->address;
	}

	public function getStatus(): ?int
	{
		return $this->status;
	}
}
