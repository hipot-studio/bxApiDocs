<?php

namespace Bitrix\BIConnector\Superset\Dashboard;

use Bitrix\Main\Type\DateTime;

class ShareExpireAgent
{
	public static function add(int $shareId, string $token, DateTime $dateExpire): void
	{
		self::remove($shareId);

		\CAgent::AddAgent(
			self::getAgentName($shareId, $token),
			'biconnector',
			'N',
			0,
			'',
			'Y',
			$dateExpire->toString(),
		);
	}

	public static function remove(int $shareId): void
	{
		$res = \CAgent::GetList(
			[],
			[
				'MODULE_ID' => 'biconnector',
				'NAME' => self::getAgentNamePrefix($shareId) . '%',
			]
		);
		while ($agent = $res->Fetch())
		{
			\CAgent::Delete($agent['ID']);
		}
	}

	public static function expire(int $shareId, string $token): string
	{
		SharePullService::sendRevokeEvent($token);

		return '';
	}

	private static function getAgentNamePrefix(int $shareId): string
	{
		return '\\Bitrix\\BIConnector\\Superset\\Dashboard\\ShareExpireAgent::expire(' . $shareId . ',';
	}

	private static function getAgentName(int $shareId, string $token): string
	{
		return self::getAgentNamePrefix($shareId) . '"' . $token . '");';
	}
}
