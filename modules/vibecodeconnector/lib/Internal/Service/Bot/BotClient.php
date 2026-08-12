<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Bot;

use Bitrix\Main\Service\MicroService\BaseSender;
use Bitrix\Vibecodeconnector\Internal\Exception\BotOperationFailedException;
use Bitrix\Vibecodeconnector\Internal\Service\Endpoint\BaseEndpointProvider;
use Bitrix\Vibecodeconnector\Internal\Service\Endpoint\EndpointResolver;

final class BotClient extends BaseSender
{
	private const ACTION_LEGACY_UNREGISTER = 'bot.legacy.unregister';

	public function __construct(private readonly BaseEndpointProvider $endpointProvider)
	{
		parent::__construct();
	}

	/**
	 * Tells the primary Vibe to forget about a legacy OAuth-style bot.
	 *
	 * Legacy bots were created before the multi-pairing redesign, when a portal
	 * was paired with a single Vibe — so the cache lives on that Vibe, which is
	 * the one currently configured as the base endpoint.
	 *
	 * The Vibe side returns success even if its cache is already empty
	 * ("already migrated"). Failures here are non-fatal: the caller still does
	 * the local cleanup so the portal is consistent regardless.
	 */
	public function unregisterLegacy(int $legacyBotId, string $legacyAppId): void
	{
		if ($legacyBotId <= 0)
		{
			throw new BotOperationFailedException(
				'legacyBotId must be a positive integer',
				'LEGACY_BOT_ID_REQUIRED',
			);
		}
		if ($legacyAppId === '')
		{
			throw new BotOperationFailedException(
				'legacyAppId must not be empty',
				'LEGACY_APP_ID_REQUIRED',
			);
		}

		$result = $this->performRequest(self::ACTION_LEGACY_UNREGISTER, [
			'legacyBotId' => $legacyBotId,
			'legacyAppId' => $legacyAppId,
		]);
		if (!$result->isSuccess())
		{
			$errors = $result->getErrors();
			$first = $errors[0] ?? null;
			throw new BotOperationFailedException(
				'Legacy bot unregister failed: ' . implode('; ', $result->getErrorMessages()),
				$first?->getCode() !== null ? (string)$first->getCode() : null,
			);
		}
	}

	protected function getServiceUrl(): string
	{
		return (new EndpointResolver($this->endpointProvider->getBaseUrl()))->microservice();
	}
}
