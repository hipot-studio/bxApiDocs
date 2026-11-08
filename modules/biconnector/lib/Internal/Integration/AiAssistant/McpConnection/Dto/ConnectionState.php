<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant\McpConnection\Dto;

/**
 * Read-only state of the BI connection, in the words of the popup.
 *
 * The server block, the address, the transport and the secret stay inside the adapter: reading the
 * state never hands the token out again.
 */
final class ConnectionState implements \JsonSerializable
{
	/**
	 * @param string[] $scopeNames Readable tool set names of the granted scope.
	 * @param string|null $issuedAt Issue date in ATOM format; the contour may leave it empty.
	 * @param string|null $expiresAt Expiry date in ATOM format.
	 */
	public function __construct(
		public readonly bool $hasConnection,
		public readonly ?string $title,
		public readonly array $scopeNames,
		public readonly ?string $issuedAt,
		public readonly ?string $expiresAt,
	)
	{
	}

	public function jsonSerialize(): array
	{
		return [
			'hasConnection' => $this->hasConnection,
			'title' => $this->title,
			'scopeNames' => $this->scopeNames,
			'issuedAt' => $this->issuedAt,
			'expiresAt' => $this->expiresAt,
		];
	}
}
