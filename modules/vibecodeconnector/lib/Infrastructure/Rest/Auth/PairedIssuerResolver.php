<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Infrastructure\Rest\Auth;

use Bitrix\Rest\APAuth\Auth as WebhookAuth;
use Bitrix\Rest\OAuth\Auth as OAuthAuth;
use Bitrix\Rest\Public\Provider\ApplicationProvider;
use Bitrix\Rest\Public\Provider\IncomingWebhookProvider;

final class PairedIssuerResolver
{
	private const ATTRIBUTE = 'vibecode_server_iss';

	public function __construct(
		private readonly IncomingWebhookProvider $webhookProvider = new IncomingWebhookProvider(),
		private readonly ApplicationProvider $applicationProvider = new ApplicationProvider(),
	)
	{
	}

	public function resolve(?\CRestServer $server, int $userId): ?string
	{
		if ($server === null)
		{
			return null;
		}

		$value = match ($server->getAuthType())
		{
			WebhookAuth::AUTH_TYPE => $this->resolveWebhookAttribute($server->getPasswordId(), $userId),
			OAuthAuth::AUTH_TYPE => $this->resolveApplicationAttribute($server->getClientId(), $userId),
			default => null,
		};

		$value = trim((string)$value);

		return $value !== '' ? $value : null;
	}

	private function resolveWebhookAttribute(?string $passwordId, int $userId): ?string
	{
		$passwordId = (int)$passwordId;
		if ($passwordId <= 0 || $userId <= 0)
		{
			return null;
		}

		$offset = 0;
		do
		{
			$webhooks = $this->webhookProvider->getListByUser($userId, $offset, 50);
			foreach ($webhooks as $webhook)
			{
				if ((int)$webhook->getId() === $passwordId)
				{
					return $webhook->getExternalAttribute(self::ATTRIBUTE)?->getValue();
				}
			}
			$offset += count($webhooks);
		}
		while (count($webhooks) === 50);

		return null;
	}

	private function resolveApplicationAttribute(?string $clientId, int $userId): ?string
	{
		if ($clientId === null || $clientId === '' || $userId <= 0)
		{
			return null;
		}

		return $this->applicationProvider
			->getByClientId($userId, $clientId)
			?->getExternalAttribute(self::ATTRIBUTE)
			?->getValue()
		;
	}
}
