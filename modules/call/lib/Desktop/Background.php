<?php
namespace Bitrix\Call\Desktop;

use Bitrix\Im\Common;
use Bitrix\Main\Application;
use Bitrix\Main\Data\Cache;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ModuleManager;

/**
 * @internal
 */
class Background
{
	private static $path = '/bitrix/js/call/images/background';

	private const THEMES_CACHE_TTL = 3600;
	private const THEMES_CACHE_DIR = '/call/background-themes';
	// the same year the theme picker keeps its own reads of this table for
	private const THEMES_SELECT_CACHE_TTL = 3600 * 24 * 365;
	// the template the portal themes belong to; the picker is asked about this one
	private const THEME_TEMPLATE_ID = 'bitrix24';

	public static function get(): array
	{
		return array_merge(
			self::getImageFiles(),
			self::getVideoFiles(),
			self::getIntranetFiles()
		);
	}

	/**
	 * Returns static image backgrounds supported in web calls.
	 *
	 * @return array
	 */
	public static function getWebFiles(bool $withIntranetThemes = true): array
	{
		if (!$withIntranetThemes)
		{
			return self::getImageFiles();
		}

		return array_merge(
			self::getImageFiles(),
			self::getIntranetFiles()
		);
	}

	private static function getImageFiles(): array
	{
		$result = [
			[
				'id' => 'apartment',
				'title' => Loc::getMessage('IM_CALL_BG_IMAGE_APARTMENT'),
			],
			[
				'id' => 'night-office',
				'title' => Loc::getMessage('IM_CALL_BG_IMAGE_NIGHT_OFFICE'),
			],
			[
				'id' => 'basement',
				'title' => Loc::getMessage('IM_CALL_BG_IMAGE_BASEMENT'),
			],
			[
				'id' => 'tent',
				'title' => Loc::getMessage('IM_CALL_BG_IMAGE_TENT'),
			],
			[
				'id' => 'summer-park',
				'title' => Loc::getMessage('IM_CALL_BG_IMAGE_SUMMER_PARK'),
			],
			[
				'id' => 'winter-forest',
				'title' => Loc::getMessage('IM_CALL_BG_IMAGE_WINTER_FOREST'),
			],
			[
				'id' => 'botanical-garden',
				'title' => Loc::getMessage('IM_CALL_BG_IMAGE_BOTANICAL_GARDEN'),
			],
			[
				'id' => 'stadium',
				'title' => Loc::getMessage('IM_CALL_BG_IMAGE_STADIUM'),
			],
			[
				'id' => 'safari',
				'title' => Loc::getMessage('IM_CALL_BG_IMAGE_SAFARI'),
			],
			[
				'id' => 'subway',
				'title' => Loc::getMessage('IM_CALL_BG_IMAGE_SUBWAY'),
			],
			[
				'id' => 'escalator',
				'title' => Loc::getMessage('IM_CALL_BG_IMAGE_ESCALATOR'),
			],
			[
				'id' => 'space-station',
				'title' => Loc::getMessage('IM_CALL_BG_IMAGE_SPACE_STATION'),
			],
			[
				'id' => 'moon',
				'title' => Loc::getMessage('IM_CALL_BG_IMAGE_MOON'),
			],
			[
				'id' => 'fireworks',
				'title' => Loc::getMessage('IM_CALL_BG_IMAGE_HOLIDAY'),
			],
			[
				'id' => 'hyperspace',
				'title' => Loc::getMessage('IM_CALL_BG_IMAGE_HYPERSPACE'),
			],
			[
				'id' => 'attractions',
				'title' => Loc::getMessage('IM_CALL_BG_IMAGE_ATTRACTIONS'),
			],
			[
				'id' => 'street',
				'title' => Loc::getMessage('IM_CALL_BG_IMAGE_STREET'),
			],
			[
				'id' => 'cathedral',
				'title' => Loc::getMessage('IM_CALL_BG_IMAGE_CATHEDRAL'),
			],
			[
				'id' => 'abandoned-building',
				'title' => Loc::getMessage('IM_CALL_BG_IMAGE_ABANDONED_BUILDING'),
			],
			[
				'id' => 'business-quarter',
				'title' => Loc::getMessage('IM_CALL_BG_IMAGE_BUSINESS_QUARTER'),
			],
			[
				'id' => 'under-water',
				'title' => Loc::getMessage('IM_CALL_BG_IMAGE_UNDER_WATER'),
			],
			[
				'id' => 'autumn-wall',
				'title' => Loc::getMessage('IM_CALL_BG_IMAGE_AUTUMN_WALL'),
			],
			[
				'id' => 'wooden-wall',
				'title' => Loc::getMessage('IM_CALL_BG_IMAGE_WOODEN_WALL'),
			],
			[
				'id' => 'bright-wall',
				'title' => Loc::getMessage('IM_CALL_BG_IMAGE_BRIGHT_WALL'),
			],
			[
				'id' => 'neon-space',
				'title' => Loc::getMessage('IM_CALL_BG_IMAGE_NEON_SPACE'),
			],
			[
				'id' => 'skeletons',
				'title' => Loc::getMessage('IM_CALL_BG_IMAGE_SKELETONS'),
			],
			[
				'id' => 'halloween',
				'title' => Loc::getMessage('IM_CALL_BG_IMAGE_HALLOWEEN'),
			],
			[
				'id' => 'christmas-tree',
				'title' => Loc::getMessage('IM_CALL_BG_IMAGE_CHRISTMAS_TREE'),
			],
		];

		if (Application::getInstance()->getLicense()->getRegion() === 'ru')
		{
			$result = array_merge(
				[
					[
						'id' => 'responsible-colleague',
						'title' => Loc::getMessage('IM_CALL_BG_IMAGE_RESPONSIBLE_COLLEAGUE'),
					],
					/*
					[
						'id' => 'business-lunch',
						'title' => Loc::getMessage('IM_CALL_BG_IMAGE_BUSINESS_LUNCH'),
					],
					*/
					[
						'id' => 'under-control',
						'title' => Loc::getMessage('IM_CALL_BG_IMAGE_UNDER_CONTROL'),
					],
					[
						'id' => 'lets-solve-problem',
						'title' => Loc::getMessage('IM_CALL_BG_IMAGE_LETS_SOLVE_PROBLEM'),
					],
				],
				$result
			);
		}

		foreach ($result as &$value)
		{
			$value['preview'] = static::$path."/{$value['id']}-preview.jpg";
			$value['background'] = static::$path."/{$value['id']}.jpg";
			$value['isVideo'] = false;
			$value['isSupported'] = true;
		}

		return $result;
	}

	private static function getVideoFiles(): array
	{
		$result = [
			[
				'id' => 'star-sky',
				'title' => Loc::getMessage('IM_CALL_BG_VIDEO_STAR_SKY'),
			],
			[
				'id' => 'waves',
				'title' => Loc::getMessage('IM_CALL_BG_VIDEO_WAVES'),
			],
			[
				'id' => 'jellyfishes',
				'title' => Loc::getMessage('IM_CALL_BG_VIDEO_JELLYFISHES'),
			],
			[
				'id' => 'sunset',
				'title' => Loc::getMessage('IM_CALL_BG_VIDEO_SUNSET'),
			],
			[
				'id' => 'rain',
				'title' => Loc::getMessage('IM_CALL_BG_VIDEO_RAIN'),
			],
			[
				'id' => 'rain-drops',
				'title' => Loc::getMessage('IM_CALL_BG_VIDEO_RAIN_DROPS'),
			],
			[
				'id' => 'grass',
				'title' => Loc::getMessage('IM_CALL_BG_VIDEO_GRASS'),
			],
			[
				'id' => 'stones',
				'title' => Loc::getMessage('IM_CALL_BG_VIDEO_STONES'),
			],
			[
				'id' => 'waterfall',
				'title' => Loc::getMessage('IM_CALL_BG_VIDEO_WATERFALL'),
			],
			[
				'id' => 'shining',
				'title' => Loc::getMessage('IM_CALL_BG_VIDEO_SHINING'),
			],
			[
				'id' => 'beach',
				'title' => Loc::getMessage('IM_CALL_BG_VIDEO_BEACH'),
			],
			[
				'id' => 'river',
				'title' => Loc::getMessage('IM_CALL_BG_VIDEO_RIVER'),
			],
		];

		foreach ($result as &$value)
		{
			$value['preview'] = static::$path."/video/{$value['id']}-preview.jpg";
			$value['background'] = static::$path."/video/{$value['id']}.mp4";
			$value['isVideo'] = true;
			$value['isSupported'] = true;
			$value['id'] .= ':video';
		}

		return $result;
	}

	/**
	 * The portal themes offered as call backgrounds. Reading them is not cheap: the theme picker
	 * resizes a preview and reads the file record of every custom theme the user has - up to forty
	 * of them - and on a cold resize cache it creates those previews inside this very request. The
	 * gallery is opened by a click in a running call, so the list is read from the cache instead.
	 *
	 * The key carries everything the list is built from, so a change to any of it is a different
	 * key and the next open builds the list anew - no invalidation to forget. That is: the themes
	 * of this user, the theme an administrator set for everyone (it joins the list even when it
	 * is not the user's own), the context the picker works in - which carries the site, so two
	 * sites of one installation never read each other's list - the language of the titles and the
	 * version of the module the standard themes ship with.
	 *
	 * The key is read without building the picker at all, and the picker is built only after a
	 * miss: a key that costs what the cache saves would be paid on every open - see
	 * getIntranetCacheId().
	 */
	private static function getIntranetFiles(): array
	{
		if (!Loader::includeModule('intranet'))
		{
			return [];
		}

		$userId = (int)CurrentUser::get()->getId();
		$cache = Cache::createInstance();
		$cacheId = self::getIntranetCacheId($userId);
		// a directory of this user's own, so their themes can be dropped without touching anyone else
		$cacheDir = self::THEMES_CACHE_DIR . '/' . ($userId % 100) . '/' . $userId;

		if ($cache->initCache(self::THEMES_CACHE_TTL, $cacheId, $cacheDir))
		{
			return $cache->getVars();
		}

		// Only now, and only because the list has to be built. Constructing the picker is itself
		// the expensive part - see getIntranetCacheId().
		$result = self::buildIntranetFiles(
			new \Bitrix\Intranet\Integration\Templates\Bitrix24\ThemePicker(self::THEME_TEMPLATE_ID)
		);

		$cache->startDataCache();
		$cache->endDataCache($result);

		return $result;
	}

	/**
	 * The key of the cached list, read without constructing the theme picker.
	 *
	 * The picker cannot be asked for any of this, and that is the whole point: its constructor
	 * hydrates the theme the user is on, and a custom one is hydrated through
	 * CFile::resizeImageGet() and CFile::getFileArray() - on a cold resize cache the preview is
	 * created inside this very request, which for the gallery means inside a running call. That
	 * is exactly the work this cache exists to save, so building the picker to find out whether
	 * the cache is warm would pay it on every open, hit or miss. It is built after a miss.
	 *
	 * What is read here instead are the sources the picker reads: the two user options it keeps
	 * the themes in, and the row of the theme an administrator set for everyone. The names of
	 * those options and the context are one-line formulas in ThemePicker, repeated here - and
	 * that is the one thing to know about this method. If they ever change there, the key stops
	 * noticing a theme edit and the gallery can be up to an hour behind; it cannot go wrong in
	 * any other way, and an hour is the lifetime of this cache anyway.
	 */
	private static function getIntranetCacheId(int $userId): string
	{
		$template = self::THEME_TEMPLATE_ID;

		return md5(serialize([
			\CUserOptions::getOption('intranet', 'bitrix24_custom_themes_' . $template, [], $userId),
			// the picker sorts the list by what was used last, so this is part of the list too
			\CUserOptions::getOption('intranet', 'bitrix24_last_themes_usage' . $template, [], $userId),
			self::getDefaultThemeKey($template . '_' . SITE_ID),
			// the context the picker works in carries the site, so two sites of one installation
			// never read each other's list
			$template . '_' . SITE_ID,
			LANGUAGE_ID,
			ModuleManager::getVersion('intranet'),
		]));
	}

	/**
	 * The theme an administrator set for everyone, as the identifiers it is stored as.
	 *
	 * The row is what the picker reads too, through a query cached for a year. What the theme is
	 * made of is not in the row - a picture can be replaced while the identifier stays the same -
	 * so the custom themes of the user who owns it go in as well; that read is an option, not a
	 * file. A standard theme has no such option behind it, and the read then only means that an
	 * administrator editing their own themes rebuilds this list once. That is rarer than getting
	 * the identifier format of a custom theme wrong here.
	 */
	private static function getDefaultThemeKey(string $context): array
	{
		$theme = \Bitrix\Intranet\Internals\ThemeTable::getList([
			'filter' => [
				'=ENTITY_TYPE' => \Bitrix\Intranet\Integration\Templates\Bitrix24\ThemePicker::ENTITY_TYPE_USER,
				'ENTITY_ID' => 0,
				'=CONTEXT' => $context,
			],
			'select' => ['THEME_ID', 'USER_ID'],
			'cache' => ['ttl' => self::THEMES_SELECT_CACHE_TTL],
		])->fetch();

		if (!$theme)
		{
			// nobody set one, and what the picker falls back to is the same for everyone
			return [];
		}

		$ownerId = (int)$theme['USER_ID'];

		return [
			$theme['THEME_ID'],
			$ownerId,
			$ownerId > 0
				? \CUserOptions::getOption('intranet', 'bitrix24_custom_themes_' . self::THEME_TEMPLATE_ID, [], $ownerId)
				: null,
		];
	}

	private static function buildIntranetFiles(
		\Bitrix\Intranet\Integration\Templates\Bitrix24\ThemePicker $themePicker
	): array
	{
		$result = [];
		$list = $themePicker->getList();

		foreach ($list as $element)
		{
			if (mb_strpos($element['id'], 'pattern') !== false)
			{
				continue;
			}

			if (mb_strpos($element['id'], 'video') !== false)
			{
				continue;
			}

			if (!$element['prefetchImages'][0])
			{
				continue;
			}

			$result[] = [
				'id' => $element['id'],
				'title' => $element['title'],
				'preview' => $element['previewImage'],
				'background' => $element['prefetchImages'][0],
				'isVideo' => false,
				'isSupported' => true
			];
		}

		return $result;
	}

	public static function getCustom(): array
	{
		$result = [];

		if (!\CIMDisk::Enabled())
		{
			return $result;
		}

		$folderModel = self::getUploadFolder();
		if (!$folderModel)
		{
			return $result;
		}

		$securityContext = new \Bitrix\Disk\Security\DiskSecurityContext(Common::getUserId());

		$parameters = [
			'filter' => [
				'PARENT_ID' => $folderModel->getId(),
				'STORAGE_ID' => $folderModel->getStorageId()
			],
			'order' => ['UPDATE_TIME' => 'DESC']
		];
		$parameters = \Bitrix\Disk\Driver::getInstance()->getRightsManager()->addRightsCheck($securityContext, $parameters, array('ID', 'CREATED_BY'));

		$fileCollection = \Bitrix\Disk\File::getModelList($parameters);

		foreach ($fileCollection as $fileModel)
		{
			if ($fileModel->getTypeFile() == \Bitrix\Disk\TypeFile::IMAGE)
			{
				$supported = in_array(mb_strtolower($fileModel->getExtension()), ['png', 'jpg', 'jpeg'], true);
			}
			else if ($fileModel->getTypeFile() == \Bitrix\Disk\TypeFile::VIDEO)
			{
				$supported = in_array(mb_strtolower($fileModel->getExtension()), ['mp4', 'mov', 'avi'], true);
			}
			else
			{
				$supported = false;
			}

			$result[] = [
				'id' => (int)$fileModel->getId(),
				'title' => $fileModel->getName(),
				'preview' => $supported? \CIMDisk::GetPublicPath(\CIMDisk::PATH_TYPE_PREVIEW, $fileModel, false): '',
				'background' => $supported? \CIMDisk::GetPublicPath(\CIMDisk::PATH_TYPE_SHOW, $fileModel, false): '',
				'isVideo' => $fileModel->getTypeFile() == \Bitrix\Disk\TypeFile::VIDEO,
				'isSupported' => $supported,
			];
		}

		return $result;
	}

	public static function getUploadFolder($userId = null): ?\Bitrix\Disk\Folder
	{
		$folderModel = \CIMDisk::GetBackgroundFolderModel($userId);
		if (!$folderModel)
		{
			return null;
		}

		return $folderModel;
	}

	public static function getLimitForJs(): array
	{
		$result = [];

		$image = \Bitrix\Call\Limit::getTypeCallBackground();
		$result[] = [
			'id' => $image['ID'],
			'active' => $image['ACTIVE'],
			'articleCode' => $image['ARTICLE_CODE'],
		];

		$blur = \Bitrix\Call\Limit::getTypeCallBlurBackground();
		$result[] = [
			'id' => $blur['ID'],
			'active' => $blur['ACTIVE'],
			'articleCode' => $blur['ARTICLE_CODE'],
		];

		return $result;
	}
}

