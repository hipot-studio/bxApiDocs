<?php
namespace Bitrix\Pull;


class Channel
{
	const TYPE_PRIVATE = 'private';
	const USER_SELF = null;

	public static function getPublicId($params)
	{
		$config['USERS'] = $params['USER_ID'] ?? self::USER_SELF;
		$config['TYPE'] = $params['TYPE'] ?? self::TYPE_PRIVATE;
		$config['JSON'] = isset($params['JSON']) && $params['JSON'] == 'Y';

		$result = self::getPublicIds($config);
		if ($result)
		{
			$result = array_shift($result);
		}

		return $result;
	}

	public static function getPublicIds($params = [])
	{
		if (!\CPullOptions::GetQueueServerStatus() || \CPullOptions::GetQueueServerVersion() < 4)
		{
			return false;
		}

		$users = $params['USERS'] ?? self::USER_SELF;
		$type = $params['TYPE'] ?? self::TYPE_PRIVATE;
		$returnJson = $params['JSON'] ? true : false;

		$userList = [];
		if (is_array($users))
		{
			foreach ($users as $userId)
			{
				$userId = intval($userId);
				if ($userId > 0)
				{
					$userList[$userId] = $userId;
				}
			}
		}
		else
		{
			if ($users == self::USER_SELF)
			{
				global $USER;
				$userId = $USER->GetID();
			}
			else
			{
				$userId = intval($users);
			}
			if ($userId <= 0)
			{
				return false;
			}
			$userList[] = $userId;
		}

		$rows = \CPullChannel::getMany(array_values($userList), $type);

		$config = [];
		foreach ($userList as $userId)
		{
			$privateChannel = $rows[$userId] ?? [];
			$config[$userId] = [
				'USER_ID' => (int)$userId,
				'PUBLIC_ID' => $privateChannel["CHANNEL_PUBLIC_ID"] ?? null,
				'SIGNATURE' => \CPullChannel::GetPublicSignature($privateChannel["CHANNEL_PUBLIC_ID"] ?? null),
				'START' => $privateChannel['CHANNEL_DT'] ?? null,
				'END' => isset($privateChannel['CHANNEL_DT']) ? $privateChannel['CHANNEL_DT'] + \CPullChannel::CHANNEL_TTL : null,
			];
		}

		if ($returnJson)
		{
			foreach ($config as $userId => $userConfig)
			{
				$userConfig = array_change_key_case($userConfig, CASE_LOWER);
				$userConfig['start'] = date('c', $userConfig['start']);
				$userConfig['end'] = date('c', $userConfig['end']);
				$config[$userId] = $userConfig;
			}
		}

		return $config;
	}
}
