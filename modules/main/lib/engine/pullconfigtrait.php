<?php

namespace Bitrix\Main\Engine;

use Bitrix\Main\Loader;
use Bitrix\Main\Security\Random;
use Bitrix\Main\Config\Option;
use Bitrix\Pull;

trait PullConfigTrait
{
	/**
	 * Returns an array with a configuration for a random pull channel.
	 *
	 * @return array
	 */
	public static function getPullConfig(): array
	{
		if (!Loader::includeModule('pull'))
		{
			return [];
		}

		$config['channelTag'] = Random::getString(32, true);
		$channel = Pull\Model\Channel::createWithTag($config['channelTag']);
		$config['pullConfig'] = Pull\Config::get(['CHANNEL' => $channel, 'JSON' => true]);
		$config['uniqueId'] = static::getUniqueId();

		return $config;
	}

	/**
	 * Returns the public unique ID (not the secret one), useful for distinguishing user profiles.
	 *
	 * @return string
	 */
	public static function getUniqueId(): string
	{
		$uniqid = Option::get('main', '~public_uniq_id');

		if ($uniqid == '')
		{
			$uniqid = Random::getString(16, true);
			Option::set('main', '~public_uniq_id', $uniqid);
		}

		return $uniqid;
	}
}
