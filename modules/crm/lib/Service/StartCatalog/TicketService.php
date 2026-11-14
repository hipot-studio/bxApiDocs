<?php

declare(strict_types=1);

namespace Bitrix\Crm\Service\StartCatalog;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Security\Random;
use Bitrix\Main\Security\Sign\BadSignatureException;
use Bitrix\Main\Security\Sign\TimeSigner;
use Bitrix\Main\Web\Json;

/**
 * Issues and reads the signed ticket of an entry point; it passes through the browser and is trusted
 * only while the signature holds and the lifetime has not run out.
 */
class TicketService
{
	public const PROPERTY = 'crmStartCatalogTicket';

	private const SALT = 'crm.start-catalog.ticket';
	private const LIFETIME = '+2 hours';
	private const KEY_LENGTH = 32;

	public function issue(int $userId, int $entityTypeId, int $formId, array $context): string
	{
		$payload = Json::encode([
			'userId' => $userId,
			'entityTypeId' => $entityTypeId,
			'formId' => $formId,
			'key' => Random::getString(self::KEY_LENGTH, true),
			'context' => $context,
		]);

		return (new TimeSigner())->sign($payload, self::LIFETIME, self::SALT);
	}

	public function parse(string $signed): ?Ticket
	{
		if ($signed === '')
		{
			return null;
		}

		try
		{
			$payload = (new TimeSigner())->unsign($signed, self::SALT);
			$data = Json::decode($payload);
		}
		catch (BadSignatureException|ArgumentException)
		{
			return null;
		}

		if (
			!is_array($data)
			|| !is_int($data['userId'] ?? null)
			|| !is_int($data['entityTypeId'] ?? null)
			|| !is_int($data['formId'] ?? null)
			|| !is_string($data['key'] ?? null)
			|| !is_array($data['context'] ?? null)
		)
		{
			return null;
		}

		return new Ticket(
			$data['userId'],
			$data['entityTypeId'],
			$data['formId'],
			$data['key'],
			$data['context'],
		);
	}
}
