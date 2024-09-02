<?php

namespace Bitrix\AI;

use Bitrix\AI\Facade\Bitrix24;
use Bitrix\AI\Synchronization\PlanSync;
use Bitrix\AI\Synchronization\PromptSync;
use Bitrix\AI\Synchronization\RoleIndustrySync;
use Bitrix\AI\Synchronization\RoleSync;
use Bitrix\AI\Synchronization\SectionSync;
use Bitrix\Main\Application;
use Bitrix\Main\IO\File;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\Json;
use CAgent;
use Exception;

/**
 * Class Updater. Refreshes local DB from remote host.
 * @package Bitrix\AI
 */
final class Updater
{
	private const OPTION_CODE_EXPIRED_TIME = 'prompt_expired_time';
	private const OPTION_CODE_CURRENT_VERSION = 'prompt_version';
	private const OPTION_CODE_FORMAT_CURRENT_VERSION = 'format_version';
	private const CURRENT_JSON_FORMAT_VERSION = 2;

	private const TTL_HOURS = 3;

	/**
	 * Refreshes local DB from remote host.
	 *
	 * @return void
	 */
	public static function refreshFromRemote(): void
	{
		$http = new HttpClient();
		$http->setHeader('Content-Type', 'application/json');
		$response = $http->get(self::getRemoteDbUri());
		// @todo remove after creating real roles, industries and role's prompts
		if (File::isFileExists(Application::getDocumentRoot() . '/upload/ai/world-demo.json'))
		{
			$response = File::getFileContents(Application::getDocumentRoot() . '/upload/ai/world-demo.json');
		}

		self::refreshFromJson($response);
	}

	/**
	 * Refreshes local DB from local file.
	 *
	 * @param string $jsonFile JSON file.
	 * @return void
	 */
	public static function refreshFromLocalFile(string $jsonFile): void
	{
		self::refreshFromJson(Facade\File::getContents($jsonFile));
	}

	private static function getRemoteDbUri(): string
	{
		if (Bitrix24::shouldUseB24() === false)
		{
			return 'https://static-ai-proxy.bitrix.info/v2/box.json';
		}

		return Config::getValue('ai_prompt_db_uri');
	}

	/**
	 * Refreshes local DB from remote JSON file.
	 *
	 * @param string $rawJson JSON string.
	 * @return void
	 */
	private static function refreshFromJson(string $rawJson): void
	{
		if (!Application::getConnection()->lock('ai_prompt_update', 30))
		{
			return;
		}

		try
		{
			$response = Json::decode($rawJson);
		}
		catch (Exception)
		{
			return;
		}

		if ((int)$response['format_version'] !== self::CURRENT_JSON_FORMAT_VERSION)
		{
			return;
		}

		if (empty($response['version']))
		{
			$response['version'] = 1;
		}

		if ($response['version'] > self::getVersion() || $response['format_version'] > self::getFormatVersion())
		{
			(new RoleSync())->sync($response['roles'] ?? []);
			(new RoleIndustrySync())->sync($response['industries'] ?? []);
			(new PromptSync())->sync($response['abilities'] ?? [], ['=IS_SYSTEM' => 'Y']);
			(new PlanSync())->sync($response['plans'] ?? []);
			(new SectionSync())->sync($response['sections'] ?? []);

			self::setVersion($response['version']);
			self::setFormatVersion(self::CURRENT_JSON_FORMAT_VERSION);
		}

		self::makeExpired(self::TTL_HOURS);
	}

	/**
	 * Refreshes local DB from remote if expired.
	 *
	 * @return void
	 */
	public static function refreshIfExpired(): void
	{
		if (self::isExpired())
		{
			self::refreshFromRemote();
		}
	}

	/**
	 * Refreshes local DB from remote if needed.
	 * @return string
	 */
	public static function refreshDbAgent(): string
	{
		self::refreshIfExpired();

		return __CLASS__ . '::refreshDbAgent();';
	}

	/**
	 * Delayed refreshed local DB from remote if expired.
	 *
	 * @param int $seconds Delay in seconds.
	 * @return void
	 */
	public static function refreshIfExpiredDelayed(int $seconds = 30): void
	{
		if (self::isExpired())
		{
			$funcName = __CLASS__ . '::refreshFromRemote();';
			$res = CAgent::getList(
				[],
				[
					'MODULE_ID' => 'ai',
					'NAME' => $funcName,
				]
			);
			if (!$res->fetch())
			{
				CAgent::addAgent($funcName, 'ai', next_exec: (new DateTime())->add("+$seconds seconds"));
			}
		}
	}

	/**
	 * Makes local Prompts' DB expired after specified hours.
	 *
	 * @param int $hours Expired in hours.
	 * @return void
	 */
	public static function makeExpired(int $hours): void
	{
		Config::setOptionsValue(self::OPTION_CODE_EXPIRED_TIME, time() + $hours*3600);
	}

	/**
	 * Returns UNIX time when Prompts' local DB will be expired.
	 *
	 * @return bool
	 */
	public static function isExpired(): bool
	{
		return (int)Config::getValue(self::OPTION_CODE_EXPIRED_TIME) <= time();
	}


	/**
	 * Returns current version of Prompts' local DB.
	 *
	 * @return int
	 */
	public static function getVersion(): int
	{
		return (int)Config::getValue(self::OPTION_CODE_CURRENT_VERSION);
	}

	/**
	 * Sets new version of Prompts' local DB.
	 *
	 * @param int $version New version.
	 * @return void
	 */
	public static function setVersion(string $version): void
	{
		Config::setOptionsValue(self::OPTION_CODE_CURRENT_VERSION, $version);
	}

	/**
	 * Sets new version of Prompts' local DB.
	 *
	 * @param int $version New version.
	 * @return void
	 */
	public static function setFormatVersion(int $version): void
	{
		Config::setOptionsValue(self::OPTION_CODE_FORMAT_CURRENT_VERSION, $version);
	}

	/**
	 * Returns current format version of local DB.
	 *
	 * @return int
	 */
	public static function getFormatVersion(): int
	{
		return (int)Config::getValue(self::OPTION_CODE_FORMAT_CURRENT_VERSION);
	}

	/**
	 * Decreases version of Prompts' local DB.
	 *
	 * @return void
	 */
	public static function decreaseVersion(): void
	{
		self::setVersion(max(0, self::getVersion()-1));
	}
}
