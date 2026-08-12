<?php

declare(strict_types=1);

namespace Bitrix\Call\Controller\Exception;

use Bitrix\Rest\V3\Exception\RestException;

class InvalidDateRangeException extends RestException
{
	public function __construct(
		protected string $reason,
	) {
		parent::__construct();
	}

	/**
	 * Stable machine-readable code published as part of the public REST contract.
	 * Must not be derived from the class FQN — the default RestException::getRegistryCode()
	 * would emit "BITRIX_CALL_CONTROLLER_EXCEPTION_INVALIDDATERANGEEXCEPTION" which is
	 * not documented and would break client error-handling.
	 */
	public function getRegistryCode(): string
	{
		return 'invalid_date_range';
	}

	protected function getMessagePhraseCode(): string
	{
		return 'CALL_REST_V3_FOLLOWUP_INVALID_DATE_RANGE';
	}

	protected function getMessagePhraseReplacement(): ?array
	{
		return [
			'#REASON#' => $this->reason,
		];
	}
}
