<?php

namespace Bitrix\Call;

use Bitrix\Main\Config\Configuration;
use Bitrix\Main\Security\Cipher;
use Bitrix\Main\Security\SecurityException;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Web\JWT;
use Bitrix\Im\Call\Call;
use Bitrix\Main\Service\MicroService\Client;

class JwtCall
{
	protected static function getCurrentUserId(): int
	{
		global $USER;

		return $USER->getId();
	}

	public static function getPrivateKey(): string
	{
		$decryptedKey = '';
		$cryptoOptions = Configuration::getValue('crypto');

		if (!empty($cryptoOptions['crypto_key']))
		{
			try
			{
				$cipher = new Cipher();
				$encryptedKey = base64_decode(Option::get("call", "call_portal_key"));

				$decryptedKey = $cipher->decrypt($encryptedKey, $cryptoOptions['crypto_key']);
			}
			catch (SecurityException $exception)
			{
			}
		}

		return $decryptedKey;
	}

	protected static function getUserData(): array
	{
		$user = \Bitrix\Im\User::getInstance(self::getCurrentUserId());

		return [
			'userId' => $user->getId(),
			'userName' => $user->getFullName(false),
			'avatar' => $user->getAvatar(),
		];
	}

	/**
	 * Generates a user JWT for a call
	 *
	 * @return string
	 */
	public static function getUserJwt(): string
	{
		return JWT::encode(
			self::getUserData(),
			self::getPrivateKey()
		);
	}

	/**
	 * Generates call JWT
	 *
	 * @param array $chatData Chat info for token
	 * @param array|null $additionalData Additional info for call token
	 *
	 * @return string
	 */
	protected static function getCallJwt(array $chatData, array|null $additionalData = null): string
	{
		if (empty($chatData))
		{
			return '';
		}

		$portalId = Option::get("call", "call_portal_id", 0);

		return JWT::encode(
			[
				'portalId' => (int)$portalId,
				'chatId' => $chatData['CHAT_ID'],
				'tokenVersion' => $chatData['TOKEN_VERSION'],
				'usersLimit' => Call::getMaxCallServerParticipants(),
				'portalType' => Client::getPortalType(),
				'portalUrl' => Client::getServerName(),
				'controllerUrl' => (new ControllerClient())->getServiceUrl(),
				'roomType' => 1,
				'additionalData' => $additionalData,
			],
			self::getPrivateKey()
		);
	}

	/**
	 * Return call JWT
	 *
	 * @param int $chatId Chat info for token
	 * @param array|null $additionalData Additional info for call token
	 *
	 * @return string
	 */
	public static function getCallToken(int $chatId, array|null $additionalData = null): string
	{
		if (empty($chatId))
		{
			return '';
		}

		return self::getCallJwt([
			'CHAT_ID' => $chatId,
			'TOKEN_VERSION' => self::getTokenVersion($chatId),
		], $additionalData);
	}

	/**
	 * Return call JWT list
	 *
	 * @param array<int> $chatIdList List of chat ids for get token
	 * @param array|null $additionalData Additional info for call token
	 *
	 * @return array<string>
	 */
	public static function getCallTokenList(array $chatIdList, array|null $additionalData = null): array
	{
		$result = [];

		if (empty($chatIdList))
		{
			return $result;
		}

		$chatEntityList = CallChatEntity::findAll($chatIdList);
		foreach ($chatIdList as $chatId)
		{
			$tokenVersion = 1;
			if (isset($chatEntityList[$chatId]))
			{
				$tokenVersion = $chatEntityList[$chatId];
			}

			$additionalFields = [];
			if (isset($additionalData[$chatId]))
			{
				$additionalFields = $additionalData[$chatId];
			}

			$result[$chatId] =  self::getCallJwt([
				'CHAT_ID' => $chatId,
				'TOKEN_VERSION' => $tokenVersion,
			], $additionalFields);
		}

		return $result;
	}

	/**
	 * Update call JWT version
	 *
	 * @param int $chatId Chat id for token update
	 *
	 * @return string
	 */
	public static function updateCallToken(int $chatId): string
	{
		if (empty($chatId))
		{
			return '';
		}

		$chatEntity = CallChatEntity::updateVersion($chatId);

		$newTokenVersion = $chatEntity->getCallTokenVersion();
		(new BalancerClient())->updateTokenVersion($newTokenVersion, $chatId);

		return self::getCallJwt([
			'CHAT_ID' => $chatId,
			'TOKEN_VERSION' => $newTokenVersion,
		]);
	}

	/**
	 * Get version for call token
	 *
	 * @param int $chatId Chat id for token update
	 *
	 * @return int|null
	 */
	public static function getTokenVersion(int $chatId): ?int
	{
		if (empty($chatId))
		{
			return null;
		}

		$tokenVersion = 1;
		$chatEntity = CallChatEntity::find($chatId);
		if ($chatEntity)
		{
			$tokenVersion = $chatEntity->getCallTokenVersion();
		}

		return $tokenVersion;
	}
}
