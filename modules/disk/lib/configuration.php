<?php


namespace Bitrix\Disk;


use Bitrix\Disk\Document\BitrixHandler;
use Bitrix\Disk\Document\LocalDocumentController;
use Bitrix\Disk\Document\OnlyOffice\OnlyOfficeHandler;
use Bitrix\Disk\Integration\Bitrix24Manager;
use Bitrix\Disk\Internal\Enum\CustomServerTypes;
use Bitrix\Disk\Internal\Enum\ServersTypesEnum;
use Bitrix\Disk\Internal\Service\MarkdownRenderService;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\UI\Viewer\Transformation\Document;
use Bitrix\Main\UI\Viewer\Transformation\Video;
use Bitrix\Main\Web\Json;
use InvalidArgumentException;

final class Configuration
{
	public const DEFAULT_CACHE_TIME = 60;
	public const REVISION_API       = 8;
	public const OPTION_ONLYOFFICE_SERVERS_TYPE = 'disk_onlyoffice_servers_type';
	public const OPTION_ONLYOFFICE_SWITCH_SERVERS_TYPE_STATE = 'disk_onlyoffice_switch_servers_type_state';
	public const OPTION_DEFAULT_VIEWER_CUSTOM_CONFIG_TYPE = 'default_viewer_service_custom_config_type';

	public static function isEnabledDefaultEditInUf()
	{
		static $isAllow = null;
		if($isAllow === null)
		{
			$isAllow = 'Y' == Option::get(Driver::INTERNAL_MODULE_ID, 'disk_allow_edit_object_in_uf', 'Y');
		}
		return $isAllow;
	}

	public static function isEnabledKeepVersion()
	{
		static $isAllow = null;
		if($isAllow === null)
		{
			$isAllow = 'Y' == Option::get(Driver::INTERNAL_MODULE_ID, 'disk_keep_version', 'Y');
		}
		return $isAllow;
	}

	public static function isEnabledDocuments()
	{
		static $isAllow = null;
		if($isAllow === null)
		{
			$isAllow = ('Y' == Option::get(Driver::INTERNAL_MODULE_ID, 'documents_enabled', 'N'));
		}

		return $isAllow;
	}

	public static function isEnabledMarkdownViewer(): bool
	{
		// The viewer renders markdown via Parsedown, which ships with the "ui" module. Without a
		// working renderer it cannot work, so it is treated as unavailable even when the option is enabled.
		return 'Y' === Option::get(Driver::INTERNAL_MODULE_ID, 'disk_enable_markdown_viewer', 'N')
			&& MarkdownRenderService::isAvailable();
	}

	/**
	 * Returns maximum size (in bytes) of a markdown file that the viewer renders.
	 * Single source of truth for the markdown render size limit.
	 *
	 * @return int
	 */
	public static function getMaxSizeForMarkdownRender(): int
	{
		return 2097152; // 2 MB
	}

	public static function isEnabledHtmlViewer(): bool
	{
		// Unlike the markdown viewer there is no renderer dependency: the html is served as-is,
		// so the option alone decides whether the viewer is on.
		return 'Y' === Option::get(Driver::INTERNAL_MODULE_ID, 'disk_enable_html_viewer', 'N');
	}

	/**
	 * Returns maximum size (in bytes) of an html file that the viewer serves.
	 * Single source of truth for the html viewer size limit; checked only when the content is served.
	 *
	 * A non-positive option value would turn the viewer off for every file, including an empty one,
	 * with a "too large" stub. Turning the viewer off is what disk_enable_html_viewer is for, so such
	 * a value is read as "no limit set" and falls back to the default.
	 *
	 * @return int
	 */
	public static function getMaxSizeForHtmlViewer(): int
	{
		$defaultMaxSize = 10485760; // 10 MB
		$maxSize = (int)Option::get(Driver::INTERNAL_MODULE_ID, 'disk_html_viewer_max_size', $defaultMaxSize);

		return $maxSize > 0 ? $maxSize : $defaultMaxSize;
	}

	/**
	 * Extra hosts a portal may allow for the html viewer's passive subresources (styles, fonts, images),
	 * on top of the built-in CDN allowlist. Lets an operator whitelist a generator's CDN without a release.
	 *
	 * The value goes straight into a Content-Security-Policy header, so every entry is validated against a
	 * strict https-origin pattern (no port, path, wildcard or CSP delimiter): anything else is dropped to
	 * keep an operator from injecting a directive. Script hosts are intentionally not configurable here —
	 * external script execution would defeat the isolation (ADR § 6).
	 *
	 * @return string[] validated `https://host` origins, deduplicated
	 */
	public static function getHtmlViewerAllowedHosts(): array
	{
		$raw = (string)Option::get(Driver::INTERNAL_MODULE_ID, 'disk_html_viewer_csp_hosts', '');
		if ($raw === '')
		{
			return [];
		}

		$hosts = [];
		foreach (preg_split('/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) as $host)
		{
			$host = mb_strtolower($host);
			if (preg_match('#^https://[a-z0-9.-]+$#', $host) === 1)
			{
				$hosts[$host] = true;
			}
		}

		return array_keys($hosts);
	}

	public static function isEnabledStorageSizeRestriction()
	{
		static $isAllow = null;
		if($isAllow === null)
		{
			$isAllow = 'Y' == Option::get(Driver::INTERNAL_MODULE_ID, 'disk_restriction_storage_size_enabled', 'N');
		}
		return $isAllow;
	}

	public static function getVersionLimitPerFile()
	{
		$value = (int)Option::get(Driver::INTERNAL_MODULE_ID, 'disk_version_limit_per_file', 0);

		return $value?: null;
	}

	public static function isPossibleToShowExternalLinkControl()
	{
		static $isAllow = null;
		if($isAllow === null)
		{
			$isAllow = 'Y' == Option::get(Driver::INTERNAL_MODULE_ID, 'disk_allow_use_external_link', 'Y');
		}
		return $isAllow;
	}

	public static function isEnabledExternalLink()
	{
		return self::isEnabledManualExternalLink();
	}

	public static function isEnabledManualExternalLink()
	{
		static $isAllow = null;
		if($isAllow === null)
		{
			$isAllow =
				static::isPossibleToShowExternalLinkControl() &&
				Bitrix24Manager::isFeatureEnabled('disk_manual_external_link')
			;
		}
		return $isAllow;
	}

	public static function isEnabledAutoExternalLink()
	{
		static $isAllow = null;
		if($isAllow === null)
		{
			$isAllow =
				static::isPossibleToShowExternalLinkControl() &&
				Bitrix24Manager::isFeatureEnabled('disk_auto_external_link')
			;
		}
		return $isAllow;
	}

	public static function isEnabledBoardExternalLink(): bool
	{
		static $isAllow = null;
		if($isAllow === null)
		{
			$isAllow =
				static::isPossibleToShowExternalLinkControl() &&
				Bitrix24Manager::isFeatureEnabled('disk_board_external_link')
			;
		}
		return (bool)$isAllow;
	}

	public static function isEnabledObjectLock()
	{
		static $isAllow = null;
		if($isAllow === null)
		{
			$isAllow = 'Y' == Option::get(Driver::INTERNAL_MODULE_ID, 'disk_object_lock_enabled', 'N');
		}
		return $isAllow;
	}

	public static function shouldAutoLockObjectOnEdit(): bool
	{
		static $isAllow = null;
		if ($isAllow === null)
		{
			$isAllow = 'Y' === Option::get(Driver::INTERNAL_MODULE_ID, 'disk_auto_lock_on_object_edit', 'N');
		}

		return $isAllow;
	}

	public static function shouldAutoUnlockObjectOnSave(): bool
	{
		static $isAllow = null;
		if ($isAllow === null)
		{
			$isAllow = 'Y' === Option::get(Driver::INTERNAL_MODULE_ID, 'disk_auto_release_lock_on_save', 'N');
		}

		return $isAllow;
	}

	public static function getMinutesToAutoReleaseObjectLock(): int
	{
		return (int)Option::get(Driver::INTERNAL_MODULE_ID, 'disk_time_auto_release_object_lock', 0);
	}

	public static function getDocumentServiceCodeForCurrentUser()
	{
		return UserConfiguration::getDocumentServiceCode();
	}

	public static function canCreateFileByCloud()
	{
		static $isAllow = null;
		if($isAllow === null)
		{
			$isAllow = 'Y' == Option::get(Driver::INTERNAL_MODULE_ID, 'disk_allow_create_file_by_cloud', 'Y');
		}
		return $isAllow;
	}

	public static function canAutoConnectSharedObjects()
	{
		static $isAllow = null;
		if($isAllow === null)
		{
			$isAllow = 'Y' == Option::get(Driver::INTERNAL_MODULE_ID, 'disk_allow_autoconnect_shared_objects', 'N');
		}
		return $isAllow;
	}

	public static function isSuccessfullyConverted()
	{
		return Option::get(
			Driver::INTERNAL_MODULE_ID,
			'successfully_converted',
			false
		) == 'Y';
	}

	public static function getRevisionApi()
	{
		return Option::get(
			Driver::INTERNAL_MODULE_ID,
			'disk_revision_api',
			0
		);
	}

	/**
	 * Returns max file size in bytes.
	 *
	 * @return string
	 * @throws \Bitrix\Main\ArgumentNullException
	 * @throws \Bitrix\Main\ArgumentOutOfRangeException
	 */
	public static function getMaxFileSizeForIndex()
	{
		$maxIntranetFileSize = (int)Option::get("search", "max_file_size", 0);
		$maxDiskFileSize = (int)Option::get(Driver::INTERNAL_MODULE_ID, 'disk_max_file_size_for_index', 1024);

		return min($maxIntranetFileSize * 1024, $maxDiskFileSize * 1024 * 1024);
	}

	/**
	 * Returns max size of index information which we save in b_disk_object.SEARCH_INDEX.
	 *
	 * @return float|int
	 * @throws \Bitrix\Main\ArgumentNullException
	 * @throws \Bitrix\Main\ArgumentOutOfRangeException
	 */
	public static function getMaxIndexSize()
	{
		return Option::get(
			Driver::INTERNAL_MODULE_ID,
			'disk_max_index_size',
			1
		) * 1024 * 1024;
	}

	/**
	 * Returns max size of index information which we save in b_disk_object_extended_index.SEARCH_INDEX.
	 *
	 * @return float|int
	 * @throws \Bitrix\Main\ArgumentNullException
	 * @throws \Bitrix\Main\ArgumentOutOfRangeException
	 */
	public static function getMaxExtendedIndexSize()
	{
		return Option::get(
			Driver::INTERNAL_MODULE_ID,
			'disk_max_extended_index_size',
			1
		) * 1024 * 1024;
	}

	/**
	 * Returns max size of index information which we save in b_disk_object_head_index.SEARCH_INDEX.
	 *
	 * @return float|int
	 * @throws \Bitrix\Main\ArgumentNullException
	 * @throws \Bitrix\Main\ArgumentOutOfRangeException
	 */
	public static function getMaxHeadIndexSize()
	{
		return Option::get(
			Driver::INTERNAL_MODULE_ID,
			'disk_max_extended_index_size',
			0.001
		) * 1024 * 1024;
	}

	public static function allowIndexFiles()
	{
		return Option::get(
			Driver::INTERNAL_MODULE_ID,
			'disk_allow_index_files',
			'Y'
		) == 'Y';
	}

	public static function allowFullTextIndex()
	{
		return true;
	}

	public static function allowUseExtendedFullText()
	{
		return Option::get(
			Driver::INTERNAL_MODULE_ID,
			'disk_allow_use_extended_fulltext',
			'N'
		) == 'Y';
	}

	public static function getDefaultViewerServiceCode($useCache = true)
	{
		static $service = null;
		if ($service !== null && $useCache)
		{
			return $service;
		}

		$service = Option::get(Driver::INTERNAL_MODULE_ID, 'default_viewer_service', BitrixHandler::getCode());

		return $service;
	}

	public static function setDefaultViewerService(string $code): void
	{
		Option::set(Driver::INTERNAL_MODULE_ID, 'default_viewer_service', $code);
	}

	public static function setDefaultViewerCustomConfigType(CustomServerTypes $type): void
	{
		Option::set(
			moduleId: Driver::INTERNAL_MODULE_ID,
			name: self::OPTION_DEFAULT_VIEWER_CUSTOM_CONFIG_TYPE,
			value: $type->value,
		);
	}

	public static function getDefaultViewerCustomConfigType(): ?CustomServerTypes
	{
		$defaultViewerCustomConfigType = Option::get(
			moduleId: Driver::INTERNAL_MODULE_ID,
			name: self::OPTION_DEFAULT_VIEWER_CUSTOM_CONFIG_TYPE,
		);

		if ($defaultViewerCustomConfigType === '')
		{
			return null;
		}

		return CustomServerTypes::tryFrom($defaultViewerCustomConfigType);
	}

	public static function removeDefaultViewerCustomConfigType(): void
	{
		Option::delete(
			moduleId: Driver::INTERNAL_MODULE_ID,
			filter: ['name' => self::OPTION_DEFAULT_VIEWER_CUSTOM_CONFIG_TYPE],
		);
	}

	/**
	 * @deprecated
	 * @return bool
	 * @throws \Bitrix\Main\ArgumentNullException
	 * @throws \Bitrix\Main\ArgumentOutOfRangeException
	 */
	public static function allowDocumentTransformation()
	{
		return Option::get(
				Driver::INTERNAL_MODULE_ID,
				'disk_allow_document_transformation',
				'N'
			) == 'Y';
	}

	public static function getMaxSizeForDocumentTransformation()
	{
		$documentTransformation = new Document();

		return $documentTransformation->getInputMaxSize();
	}

	/**
	 * @deprecated
	 * @return bool
	 * @throws \Bitrix\Main\ArgumentNullException
	 * @throws \Bitrix\Main\ArgumentOutOfRangeException
	 */
	public static function allowVideoTransformation()
	{
		return Option::get(
				Driver::INTERNAL_MODULE_ID,
				'disk_allow_video_transformation',
				'N'
			) == 'Y';
	}

	/**
	 * Returns maximum size (in bytes) of video files that could be transformed.
	 *
	 * @return int
	 */
	public static function getMaxSizeForVideoTransformation()
	{
		$videoTransformation = new Video();

		return $videoTransformation->getInputMaxSize();
	}

	public static function allowTransformFilesOnOpen()
	{
		static $allow = null;
		if ($allow !== null)
		{
			return $allow;
		}
		$allow = Option::get(
				Driver::INTERNAL_MODULE_ID,
				'disk_transform_files_on_open',
				'N'
			) == 'Y';

		return $allow;
	}

	public static function getFileVersionTtl(): int
	{
		$dayLimit = Bitrix24Manager::getFeatureVariable('disk_file_history_ttl');
		if ($dayLimit !== null)
		{
			return (int)$dayLimit;
		}

		return (int)Option::get(Driver::INTERNAL_MODULE_ID, 'disk_file_history_ttl', -1);
	}

	public static function getTrashCanTtl(): int
	{
		$ttl = Bitrix24Manager::getFeatureVariable('disk_trashcan_ttl');
		if ($ttl !== null)
		{
			return (int)$ttl;
		}

		return (int)Option::get(Driver::INTERNAL_MODULE_ID, 'disk_trashcan_ttl', -1);
	}

	public static function setOnlyOfficeServersType(ServersTypesEnum $serversType): void
	{
		Option::set(
			moduleId: Driver::INTERNAL_MODULE_ID,
			name: self::OPTION_ONLYOFFICE_SERVERS_TYPE,
			value: $serversType->value,
		);
	}

	public static function getOnlyOfficeServersType(): ServersTypesEnum
	{
		$optionServersType = Option::get(
			moduleId: Driver::INTERNAL_MODULE_ID,
			name: self::OPTION_ONLYOFFICE_SERVERS_TYPE,
			default: null,
		);

		// if option doesn't exist, default - regular
		if (!is_string($optionServersType))
		{
			return ServersTypesEnum::Regular;
		}

		if (is_null($serversType = ServersTypesEnum::tryFrom($optionServersType)))
		{
			throw new InvalidArgumentException(
				message: "Invalid value \"$optionServersType\" for option"
				. " \"" . self::OPTION_ONLYOFFICE_SERVERS_TYPE . "\""
			);
		}

		return $serversType;
	}

	public static function storeStateForOnlyofficeSwitchServersType(array $state): void
	{
		$state['dateTime'] = $state['dateTime']->format(DATE_ATOM);

		Option::set(
			moduleId: Driver::INTERNAL_MODULE_ID,
			name: self::OPTION_ONLYOFFICE_SWITCH_SERVERS_TYPE_STATE,
			value: Json::encode($state),
		);
	}

	public static function getStateForOnlyofficeSwitchServersType(): ?array
	{
		$serializedState = Option::get(
			moduleId: Driver::INTERNAL_MODULE_ID,
			name: self::OPTION_ONLYOFFICE_SWITCH_SERVERS_TYPE_STATE,
			default: null,
		);

		if (!is_string($serializedState))
		{
			return null;
		}

		$state = Json::decode($serializedState);
		$state['dateTime'] = DateTime::createFromPhp(new \DateTime($state['dateTime']));

		return $state;
	}

	public static function deleteStateForOnlyofficeSwitchServersType(): void
	{
		Option::delete(
			moduleId: Driver::INTERNAL_MODULE_ID,
			filter: ['name' => self::OPTION_ONLYOFFICE_SWITCH_SERVERS_TYPE_STATE],
		);
	}

	public static function getCustomServers(): ?array
	{
		return Configuration::getFromSettings('customServers');
	}

	public static function getCustomServersRegions(): ?array
	{
		return Configuration::getFromSettings('customServersRegions');
	}

	public static function getVersionMatchers(): ?array
	{
		return Configuration::getFromSettings('versionMatchers');
	}

	protected static function getFromSettings(string $key): mixed
	{
		return \Bitrix\Main\Config\Configuration::getInstance('disk')->get($key);
	}
}

/**
 * Class UserConfiguration
 * Represents configuration for current user
 * @package Bitrix\Disk
 */
final class UserConfiguration
{
	public static function resetDocumentServiceCode(): void
	{
		$userSettings = \CUserOptions::getOption(
			Driver::INTERNAL_MODULE_ID,
			'doc_service',
			[
				'default' => '',
				'primary' => '',
				'was_reset_to_onlyoffice' => '',
				'was_reset' => '',
			]
		);

		$wasResetToOnlyOffice = $userSettings['was_reset_to_onlyoffice'] ?? '';

		$wasReset = null;
		if (isset($userSettings['was_reset']) && is_int($userSettings['was_reset']))
		{
			$wasReset = (int)$userSettings['was_reset'];
		}

		self::setDocumentServiceOptions('', '', $wasResetToOnlyOffice, $wasReset);
	}

	private static function setDocumentServiceOptions(string $default, string $primary, string $wasResetToOnlyOffice = 'N', int $wasReset = null): void
	{
		\CUserOptions::setOption(
			Driver::INTERNAL_MODULE_ID,
			'doc_service',
			[
				'default' => $default,
				'primary' => $primary,
				'was_reset_to_onlyoffice' => $wasResetToOnlyOffice,
				'was_reset' => $wasReset ?? '',
			]
		);
	}

	public static function resetDocumentServiceForAllUsers(): void
	{
		Option::set(Driver::INTERNAL_MODULE_ID, 'reset_user_edit_service', time());
	}

	public static function getDocumentServiceCode()
	{
		global $USER;
		static $service = null;

		if ($service !== null || !($USER instanceof \CUser) || !$USER->getId() )
		{
			return $service;
		}

		$userSettings = \CUserOptions::getOption(
			Driver::INTERNAL_MODULE_ID,
			'doc_service',
			[
				'default' => '',
				'primary' => '',
				'was_reset_to_onlyoffice' => '',
				'was_reset' => '',
			]
		);

		$defaultService = $userSettings['default'] ?? '';
		$primaryService = $userSettings['primary'] ?? '';
		$wasResetToOnlyOffice = $userSettings['was_reset_to_onlyoffice'] ?? '';
		$wasReset = $userSettings['was_reset'] ?? '';

		if (!$wasResetToOnlyOffice && Option::get(Driver::INTERNAL_MODULE_ID, 'reset_user_edit_service_to_onlyoffice', 'N') === 'Y')
		{
			self::setDocumentServiceOptions(OnlyOfficeHandler::getCode(), '', 'Y');
		}

		$timeWhenResetStart = Option::get(Driver::INTERNAL_MODULE_ID, 'reset_user_edit_service', 'N');
		if ($timeWhenResetStart !== 'N' && $timeWhenResetStart > $wasReset)
		{
			self::setDocumentServiceOptions('', '', 'N', $timeWhenResetStart);
		}

		if ($primaryService === OnlyOfficeHandler::getCode() && OnlyOfficeHandler::isEnabled())
		{
			$service = OnlyOfficeHandler::getCode();

			return $service;
		}
		if (!$primaryService && OnlyOfficeHandler::isEnabled())
		{
			$defaultHandlerForView = Driver::getInstance()->getDocumentHandlersManager()->getDefaultHandlerForView();
			if ($defaultHandlerForView instanceof OnlyOfficeHandler)
			{
				$service = OnlyOfficeHandler::getCode();

				return $service;
			}
		}
		if ($primaryService === OnlyOfficeHandler::getCode() && !OnlyOfficeHandler::isEnabled())
		{
			$primaryService = '';
		}

		$service = $primaryService?: $defaultService;

		return $service;
	}

	public static function isSetLocalDocumentService(): bool
	{
		return LocalDocumentController::isLocalService(self::getDocumentServiceCode());
	}
}
