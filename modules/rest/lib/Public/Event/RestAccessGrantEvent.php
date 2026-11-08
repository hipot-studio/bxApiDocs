<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Event;

use Bitrix\Main\Event;
use Bitrix\Main\EventResult;

final class RestAccessGrantEvent extends Event
{
	public const MODULE_ID = 'rest';
	public const TYPE = 'onRestAccessGrant';

	public const AUTH_TYPE_OAUTH = 'oauth';
	public const AUTH_TYPE_APAUTH = 'apauth';

	public const REASON_TARIFF = 'tariff';
	public const REASON_MARKET_SUBSCRIPTION = 'market_subscription';

	public function __construct(
		string $authType,
		string $reason,
		?string $clientId = null,
		?int $passwordId = null,
		?string $method = null,
	)
	{
		parent::__construct(
			self::MODULE_ID,
			self::TYPE,
			[
				'authType' => $authType,
				'clientId' => $clientId,
				'passwordId' => $passwordId,
				'method' => $method,
				'reason' => $reason,
			],
		);
	}

	public function getAuthType(): string
	{
		return $this->getParameter('authType');
	}

	public function getClientId(): ?string
	{
		return $this->getParameter('clientId');
	}

	public function getPasswordId(): ?int
	{
		return $this->getParameter('passwordId');
	}

	public function getMethod(): ?string
	{
		return $this->getParameter('method');
	}

	public function getReason(): string
	{
		return $this->getParameter('reason');
	}

	public function isGranted(): bool
	{
		foreach ($this->getResults() as $eventResult)
		{
			if ($eventResult->getType() === EventResult::SUCCESS)
			{
				return true;
			}
		}

		return false;
	}
}
