<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Integration\Rest;

use Bitrix\Main\Loader;
use Bitrix\Rest\APAuth\PasswordTable;
use Bitrix\Vibecodeconnector\Internal\Entity\BotInboundWebhook;
use Bitrix\Vibecodeconnector\Internal\Exception\BotOperationFailedException;

final class BotWebhookGateway
{
	public function __construct()
	{
		Loader::includeModule('rest');
	}

	/**
	 * @param string[] $scopes
	 */
	public function issue(int $userId, array $scopes, string $title): BotInboundWebhook
	{
		$data = PasswordTable::createPassword(
			$userId,
			$scopes,
			$title,
			true,
		);
		if (!is_array($data) || empty($data['ID']) || empty($data['PASSWORD']))
		{
			throw new BotOperationFailedException(
				'Bot inbound webhook issue failed: PasswordTable::createPassword returned no usable data',
				'BOT_WEBHOOK_ISSUE_FAILED',
			);
		}

		return new BotInboundWebhook(
			passwordId: (int)$data['ID'],
			userId: (int)($data['USER_ID'] ?? $userId),
			password: (string)$data['PASSWORD'],
			isActive: true,
		);
	}

	public function find(int $passwordId): ?BotInboundWebhook
	{
		if ($passwordId <= 0)
		{
			return null;
		}

		$row = PasswordTable::getById($passwordId)->fetch();
		if (!$row)
		{
			return null;
		}

		return new BotInboundWebhook(
			passwordId: (int)$row['ID'],
			userId: (int)$row['USER_ID'],
			password: (string)$row['PASSWORD'],
			isActive: (string)($row['ACTIVE'] ?? '') === PasswordTable::ACTIVE,
		);
	}

	public function revoke(int $passwordId): void
	{
		if ($passwordId <= 0)
		{
			return;
		}

		PasswordTable::delete($passwordId);
	}

	public function buildUrl(BotInboundWebhook $webhook): string
	{
		return \CRestUtil::getWebhookEndpoint($webhook->password, $webhook->userId);
	}
}
