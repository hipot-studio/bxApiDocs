<?php

namespace Bitrix\BIConnector\ExternalSource\Source;

use Bitrix\Main\Localization\Loc;

/**
 * Why one address of a REST connector failed, as the error code the connection check returns.
 */
enum ConnectionFailure: string
{
	case UrlMissing = 'SOURCE_CONNECTION_URL_MISSING';
	case Unreachable = 'SOURCE_CONNECTION_UNREACHABLE';
	case Timeout = 'SOURCE_CONNECTION_TIMEOUT';
	case PrivateAddress = 'SOURCE_CONNECTION_PRIVATE_ADDRESS';
	case HttpError = 'SOURCE_CONNECTION_HTTP_ERROR';
	case ProtocolError = 'SOURCE_CONNECTION_PROTOCOL_ERROR';

	private const CLIENT_ERROR_PRIVATE_IP = 'PRIVATE_IP';
	private const TIMEOUT_PATTERN = '/timed?\s?out/i';

	/**
	 * Reads the reason of a request that got no answer from the errors of HttpClient::getError().
	 *
	 * @param array<string, string> $clientErrors
	 */
	public static function fromClientErrors(array $clientErrors): self
	{
		if (array_key_exists(self::CLIENT_ERROR_PRIVATE_IP, $clientErrors))
		{
			return self::PrivateAddress;
		}

		foreach ($clientErrors as $message)
		{
			if (preg_match(self::TIMEOUT_PATTERN, (string)$message))
			{
				return self::Timeout;
			}
		}

		return self::Unreachable;
	}

	/**
	 * The text of the reason for the user, naming the address that failed.
	 *
	 * Not named getMessage*: Loc takes the caller of such a method for the owner of the phrases.
	 *
	 * @param int|null $status HTTP status of the answer, when there was one.
	 */
	public function describe(string $address, ?int $status = null): string
	{
		$replace = [
			'#ADDRESS#' => $address,
			'#STATUS#' => (string)$status,
		];

		$message = match ($this)
		{
			self::UrlMissing => Loc::getMessage('BICONNECTOR_REST_SOURCE_CONNECTION_URL_MISSING', $replace),
			self::Unreachable => Loc::getMessage('BICONNECTOR_REST_SOURCE_CONNECTION_UNREACHABLE', $replace),
			self::Timeout => Loc::getMessage('BICONNECTOR_REST_SOURCE_CONNECTION_TIMEOUT', $replace),
			self::PrivateAddress => Loc::getMessage('BICONNECTOR_REST_SOURCE_CONNECTION_PRIVATE_ADDRESS', $replace),
			self::HttpError => Loc::getMessage('BICONNECTOR_REST_SOURCE_CONNECTION_HTTP_ERROR', $replace),
			self::ProtocolError => Loc::getMessage('BICONNECTOR_REST_SOURCE_CONNECTION_PROTOCOL_ERROR', $replace),
		};

		return $message ?? $this->value;
	}
}
