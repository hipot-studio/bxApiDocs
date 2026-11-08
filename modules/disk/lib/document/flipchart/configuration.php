<?php

namespace Bitrix\Disk\Document\Flipchart;

use Bitrix\Main\Web\Uri;
use Bitrix\Disk\Controller\Integration\Flipchart;
use Bitrix\Disk\Document\Flipchart\DualMode\ConfigurationException;
use Bitrix\Disk\Document\Flipchart\DualMode\PilotProjectList;
use Bitrix\Disk\Document\Flipchart\DualMode\ServiceAddress;
use Bitrix\Disk\Document\Flipchart\DualMode\ServiceProfile;
use Bitrix\Disk\Driver;
use Bitrix\Main\Config;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Engine\UrlManager;
use Bitrix\Main\IO\File;
use Bitrix\Main\Web\Json;

class Configuration
{
	private const JWT_SECRET_OPTION = 'flipchart.jwt_secret';
	private const ANNOTATIONS_API_PATH = '/api/disk/flipchart/annotations/v1';
	private const COMMENTS_API_PATH = '/api/disk/flipchart/comments/v1';
	private const COMMENT_MENTIONS_MAX_ITEMS_DEFAULT = 30;

	private static $localValues = null;

	private static function loadLocalValues(): void
	{
		self::$localValues = [];
		$path = $_SERVER["DOCUMENT_ROOT"]."/bitrix/php_interface/disk-boards.php";
		if (File::isFileExists($path))
		{
			$localValues = require($path);
			if (is_array($localValues))
			{
				self::$localValues = $localValues;
			}
		}
	}

	private static function getFromSettings(string $name, $default = null)
	{
		if (is_null(self::$localValues))
		{
			self::loadLocalValues();
		}

		$localValue = self::$localValues[$name] ?? null;
		$boardsConfigPrimary = Config\Configuration::getInstance()->get('boards');
		$boardsConfig = Config\Configuration::getInstance('disk')->get('boards');

		return $localValue ?? $boardsConfigPrimary[$name] ?? $boardsConfig[$name] ?? $default;
	}

	public static function isBoardsEnabled(): bool
	{
		return Option::get('disk', 'boards_enabled', 'N') === 'Y';
	}

	public static function isUsingDocumentProxy(): bool
	{
		return Option::get('disk', 'boards_use_documentproxy', 'N') === 'Y';
	}

	public static function getClientTokenHeaderLookup(): string
	{
		$default = self::getFromSettings('client_token_header_lookup', 'X-Permissions');

		return Option::get('disk', 'flipchart.client_token_header_lookup', $default);
	}

	public static function getApiHost(ServiceProfile $profile = ServiceProfile::Old): string
	{
		if ($profile === ServiceProfile::New)
		{
			return self::getNewServiceValue('new_service_api_host', 'flipchart.new_service.api_host');
		}

		$default = self::getFromSettings('api_host', 'https://flip-backend');

		return Option::get('disk', 'flipchart.api_host', $default);
	}

	/**
	 * When no secret is configured, one is generated once and persisted into the
	 * "disk.flipchart.jwt_secret" option, which then becomes the source of truth and
	 * takes priority over getFromSettings (php_interface/disk-boards.php, .settings.php).
	 * A custom secret must therefore be set before the first call, or the option itself changed.
	 * In proxy mode the secret is not materialized: signing and verification go through cloud
	 * registration, so the HS256 secret is unused and an empty string is returned as-is.
	 */
	public static function getJwtSecret(): string
	{
		$default = self::getFromSettings('jwt_secret');
		$secret = (string)Option::get('disk', self::JWT_SECRET_OPTION, (string)$default);
		if ($secret === '' && !self::isUsingDocumentProxy())
		{
			return self::generatePersistentJwtSecret();
		}

		return $secret;
	}

	private static function generatePersistentJwtSecret(): string
	{
		$existing = (string)Option::get('disk', self::JWT_SECRET_OPTION, '');
		if ($existing !== '')
		{
			return $existing;
		}

		$generated = sha1(random_bytes(32));
		Option::set('disk', self::JWT_SECRET_OPTION, $generated);

		return $generated;
	}

	public static function getJwtTtl(): int
	{
		$default = self::getFromSettings('jwt_ttl', 30);

		return (int)Option::get('disk', 'flipchart.jwt_ttl', $default);
	}

	public static function getAppUrl(ServiceProfile $profile = ServiceProfile::Old): string
	{
		if ($profile === ServiceProfile::New)
		{
			return self::getNewServiceValue('new_service_app_url', 'flipchart.new_service.app_url');
		}

		$default = self::getFromSettings('app_url', 'https://flip-backend/app');

		return Option::get('disk', 'flipchart.app_url', $default);
	}

	public static function getAnnotationsApiUrl(): ?string
	{
		$explicitUrl = Option::get(
			'disk',
			'flipchart.annotations_api_url',
			self::getFromSettings('annotations_api_url'),
		);
		if ($explicitUrl === null || $explicitUrl === '')
		{
			return self::getSiteAnnotationsApiUrl();
		}

		return self::isValidAppUrl($explicitUrl) ? $explicitUrl : null;
	}

	public static function getCommentsApiUrl(): ?string
	{
		$explicitUrl = Option::get(
			'disk',
			'flipchart.comments_api_url',
			self::getFromSettings('comments_api_url'),
		);
		if ($explicitUrl === null || $explicitUrl === '')
		{
			return self::getSiteApiUrl(self::COMMENTS_API_PATH);
		}

		return self::isValidAppUrl($explicitUrl) ? $explicitUrl : null;
	}

	private static function getSiteAnnotationsApiUrl(): ?string
	{
		return self::getSiteApiUrl(self::ANNOTATIONS_API_PATH);
	}

	/**
	 * The default is signed into the board JWT, so it is built only from the configured site address
	 * over https and never from the request host.
	 */
	private static function getSiteApiUrl(string $path): ?string
	{
		$serverName = trim((string)Option::get('main', 'server_name', ''));
		if ($serverName === '')
		{
			return null;
		}

		$url = 'https://' . $serverName . $path;
		$uri = new Uri($url);
		if ($uri->getScheme() !== 'https' || $uri->getHost() === '' || $uri->getPath() !== $path)
		{
			return null;
		}

		return $url;
	}

	public static function getSaveDeltaTime(): int
	{
		$default = self::getFromSettings('save_delta_time', 30);

		return (int)Option::get('disk', 'flipchart.save_delta_time', $default);
	}

	public static function getSaveProbabilityCoef(): float
	{
		$default = self::getFromSettings('save_probability_coef', 0.1);

		return (float)Option::get('disk', 'flipchart.save_probability_coef', $default);
	}

	public static function getDocumentIdSalt(): string
	{
		$default = self::getFromSettings(
			'document_id_salt',
			crc32(
				\defined('BX24_DB_NAME')
					? BX24_DB_NAME
					: UrlManager::getInstance()->getHostUrl()
			)
		);

		return (string)Option::get('disk', 'flipchart.document_id_salt', $default);
	}

	/**
	 * @see Flipchart::webhookAction()
	 */
	public static function getWebhookUrl(): string
	{
		$default = self::getFromSettings(
			'webhook_url',
			'/bitrix/services/main/ajax.php?action=disk.integration.flipchart.webhook'
		);

		$urlManager = UrlManager::getInstance();
		$webhookUrl = $urlManager->getHostUrl() . $default;

		return Option::get('disk', 'flipchart.webhook_url', $webhookUrl);
	}

	public static function getAllowedLanguages(): array
	{
		$default = self::getFromSettings('allowed_languages', [
			'ar',
			'br',
			'en',
			'fr',
			'id',
			'it',
			'ja',
			'la',
			'ms',
			'pl',
			'ru',
			'sc',
			'tc',
			'th',
			'tr',
			'ua',
			'vn',
			'de',
			'kz',
		]);

		$option = Option::get('disk', 'flipchart.allowed_languages');
		if (!$option)
		{
			return (array)$default;
		}

		return (array)Json::decode($option);
	}

	public static function getDefaultLanguage(): string
	{
		$default = self::getFromSettings('default_language', 'en');

		return (string)Option::get('disk', 'flipchart.default_language', $default);
	}

	/**
	 * SDK derives the expected message origin from this url, so an unusable value must stop the editor
	 * instead of opening the channel without an origin check.
	 */
	public static function isValidAppUrl(mixed $appUrl): bool
	{
		if (!is_string($appUrl) || $appUrl === '')
		{
			return false;
		}

		$uri = new Uri($appUrl);

		return in_array($uri->getScheme(), ['http', 'https'], true) && $uri->getHost() !== '';
	}

	public static function isForceHttpForDocumentUrl(): bool
	{
		$default = self::getFromSettings('force_http_for_document_url', 'N');

		return Option::get('disk', 'flipchart.force_http_for_document_url', $default) === 'Y';
	}

	public static function isReloadBoardAfterInactivityEnabled(): bool
	{
		$default = self::getFromSettings('reload_board_after_inactivity', 'Y');

		return Option::get('disk', 'flipchart.reload_board_after_inactivity', $default) === 'Y';
	}

	public static function isTasksEnabled(): bool
	{
		return Option::get('disk', 'flipchart.tasks_enabled', 'N') === 'Y';
	}

	public static function isAnnotationsEnabled(): bool
	{
		return Option::get('disk', 'boards.annotations_enabled', 'N') === 'Y';
	}

	public static function isTagsEnabled(): bool
	{
		return Option::get('disk', 'boards.tags_enabled', 'N') === 'Y';
	}

	public static function isCommentsEnabled(): bool
	{
		return Option::get('disk', 'boards.comments_enabled', 'N') === 'Y';
	}

	/**
	 * A single request's `mentions` is capped at this many items: exceeding it never refuses the
	 * request, the caller (comment create/update) keeps only the first items in the order received.
	 * A stored value that is not a positive integer is not trusted and falls back to the default.
	 */
	public static function getCommentMentionsMaxItems(): int
	{
		$value = (int)Option::get(
			'disk',
			'flipchart.comment_mentions_max_items',
			self::COMMENT_MENTIONS_MAX_ITEMS_DEFAULT,
		);

		return $value > 0 ? $value : self::COMMENT_MENTIONS_MAX_ITEMS_DEFAULT;
	}

	/**
	 * @return int Delay in ms (15 minutes by default)
	 */
	public static function getReloadBoardAfterInactivityDelay(): int
	{
		$default = self::getFromSettings('reload_board_after_inactivity_delay', 1000 * 60 * 15);

		return (int)Option::get('disk', 'flipchart.reload_board_after_inactivity_delay', $default);
	}

	/**
	 * @param array{clientId: string, secretKey: string, serverHost: string} $data
	 * @return void
	 */
	public function storeCloudRegistration(array $data): void
	{
		if (!isset($data['clientId'], $data['secretKey'], $data['serverHost']))
		{
			return;
		}

		Option::set(Driver::INTERNAL_MODULE_ID, 'disk_boards_b24_clientId', $data['clientId']);
		Option::set(Driver::INTERNAL_MODULE_ID, 'disk_boards_b24_secretKey', $data['secretKey']);
		Option::set(Driver::INTERNAL_MODULE_ID, 'disk_boards_b24_serverHost', $data['serverHost']);
	}

	/**
	 * @return null|array{clientId: string, secretKey: string, serverHost: string}
	 */
	public static function getCloudRegistrationData(): ?array
	{
		$data = array_filter([
			'clientId' => Option::get(Driver::INTERNAL_MODULE_ID, 'disk_boards_b24_clientId'),
			'secretKey' => Option::get(Driver::INTERNAL_MODULE_ID, 'disk_boards_b24_secretKey'),
			'serverHost' => Option::get(Driver::INTERNAL_MODULE_ID, 'disk_boards_b24_serverHost'),
		]);

		if (count($data) === 3)
		{
			return $data;
		}

		return null;
	}

	public function resetTempSecretForDomainVerification(): void
	{
		Option::set(Driver::INTERNAL_MODULE_ID, 'disk_boards_temp_secret', null);
	}

	public function storeTempSecretForDomainVerification(string $value): void
	{
		Option::set(Driver::INTERNAL_MODULE_ID, 'disk_boards_temp_secret', $value);
	}

	public function getTempSecretForDomainVerification(): string
	{
		return Option::get(Driver::INTERNAL_MODULE_ID, 'disk_boards_temp_secret');
	}

	public function resetCloudRegistration(): void
	{
		Option::delete('disk', [
			'name' => 'disk_boards_b24_clientId',
		]);
		Option::delete('disk', [
			'name' => 'disk_boards_b24_secretKey',
		]);
		Option::delete('disk', [
			'name' => 'disk_boards_b24_serverHost',
		]);
	}

	/**
	 * Address of the new service instance.
	 *
	 * The flat api_host / app_url keys belong to the old instance and are deliberately unreachable
	 * from this profile: reusing them would route the new profile back to the old instance.
	 *
	 * The grammar is checked on read as well as on write: an option written by hand never passed
	 * through PilotProjectService, and both readers of this value are sinks that trust it.
	 */
	private static function getNewServiceValue(string $localKey, string $optionName): string
	{
		$default = (string)self::getFromSettings($localKey, '');
		$value = ServiceAddress::normalize((string)Option::get('disk', $optionName, $default));

		if ($value === '')
		{
			throw new ConfigurationException("Board service address {$optionName} is not configured");
		}

		if (!ServiceAddress::isValid($value))
		{
			throw new ConfigurationException(
				"Board service address {$optionName} is not an address of the form http(s)://host[:port][/path]",
			);
		}

		return $value;
	}

	public static function getRawPilotProjects(): string
	{
		return (string)Option::get('disk', 'flipchart.new_service_group_ids', '');
	}

	public static function getPilotProjects(): PilotProjectList
	{
		return PilotProjectList::fromRaw(self::getRawPilotProjects());
	}
}
