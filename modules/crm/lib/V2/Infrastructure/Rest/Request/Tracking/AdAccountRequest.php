<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\Request\Tracking;

use Bitrix\Main\Error;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\SelectStructure;

/**
 * Address of an advertising account action. Connect reads the optional account fields after access checks.
 */
final class AdAccountRequest extends Request
{
	public int $sourceId;

	public ?string $clientId = null;

	public ?string $accountId = null;

	public ?SelectStructure $select = null;

	public function getSourceId(): int
	{
		if ($this->sourceId < 1 || $this->sourceId > 2147483647)
		{
			throw new RequestValidationException([
				new Error('Source ID must be a positive integer.', 'sourceId'),
			]);
		}

		return $this->sourceId;
	}

	/** @return array{clientId: string, accountId: ?string} */
	public function getAccount(): array
	{
		if ($this->clientId === null || $this->clientId === '' || strlen($this->clientId) > 255)
		{
			throw new RequestValidationException([
				new Error('Client ID must be a non-empty string of at most 255 characters.', 'clientId'),
			]);
		}
		if ($this->accountId !== null && ($this->accountId === '' || strlen($this->accountId) > 255))
		{
			throw new RequestValidationException([
				new Error('Account ID must be a non-empty string of at most 255 characters.', 'accountId'),
			]);
		}

		return ['clientId' => $this->clientId, 'accountId' => $this->accountId];
	}
}
