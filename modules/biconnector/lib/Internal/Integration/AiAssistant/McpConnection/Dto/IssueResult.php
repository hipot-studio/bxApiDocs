<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant\McpConnection\Dto;

/**
 * Answer of the issue and of the reissue, in the words of the popup.
 *
 * The secret leaves the adapter only inside the command: it is neither a field of its own nor
 * stored anywhere, so the popup is its only reader.
 */
final class IssueResult implements \JsonSerializable
{
	public const OUTCOME_ISSUED = 'issued';
	public const OUTCOME_EXISTS = 'exists';

	/**
	 * @param string $outcome One of the outcome constants; a reissue answers with issued only.
	 * @param string $expiresAt Expiry date of the connection in ATOM format.
	 * @param string|null $command Ready command line; null when the connection already existed.
	 * @param string[] $scopeNames Readable tool set names of the granted scope.
	 */
	public function __construct(
		public readonly string $outcome,
		public readonly string $expiresAt,
		public readonly ?string $command,
		public readonly array $scopeNames,
	)
	{
	}

	public function jsonSerialize(): array
	{
		return [
			'outcome' => $this->outcome,
			'expiresAt' => $this->expiresAt,
			'command' => $this->command,
			'scopeNames' => $this->scopeNames,
		];
	}
}
