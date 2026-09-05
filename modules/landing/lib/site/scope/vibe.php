<?php

namespace Bitrix\Landing\Site\Scope;

use Bitrix\Landing;
use Bitrix\Landing\Block\BlockRepo;
use Bitrix\Landing\Rights;
use Bitrix\Landing\Role;
use Bitrix\Landing\Manager;
use Bitrix\Landing\Domain;
use Bitrix\Landing\Site\Scope;
use Bitrix\Landing\Site\Type;
use Bitrix\Landing\Transfer\Requisite\FinishRedirectLinkDto;
use Bitrix\Landing\Metrika;
use Bitrix\Main\Entity;
use Bitrix\Main\Event;
use Bitrix\Main\EventManager;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

/**
 * Scope for Main page (welcome)
 */
class Vibe extends Scope
{
	private const ALLOWED_EXTENSIONS = [
		'landing.widgetvue',
		'landing_carousel',
		'landing_jquery',
		'landing_icon_fonts',
		'landing_inline_video',
		'landing.utils',
		'mediaplayer',
		'loader',
	];

	/**
	 * Method for first time initialization scope.
	 * @param array $params Additional params.
	 * @return void
	 */
	public static function init(array $params = [])
	{
		parent::init($params);
		Role::setExpectedType(self::$currentScopeId);

		// init() runs again on every scope switch (a batch switches the scope per command),
		// and the handler below is an anonymous function, so without this flag every switch
		// would add one more copy of it to the same event
		static $filtersHandlerRegistered = false;
		if ($filtersHandlerRegistered)
		{
			return;
		}
		$filtersHandlerRegistered = true;

		$eventManager = EventManager::getInstance();
		$eventManager->addEventHandler(
			'landing',
			'onBlockRepoSetFilters',
			function (Event $event)
			{
				// the handler stays registered for the whole process, while the scope is switched
				// per command (REST / AJAX batch), so it checks the scope of the event itself:
				// otherwise a command that left Vibe would still get the Vibe set of blocks
				if (Type::getCurrentScopeId() !== Type::SCOPE_CODE_VIBE)
				{
					return null;
				}

				$result = new Entity\EventResult();
				$result->modifyFields([
					'ENABLE' => BlockRepo::FILTER_SKIP_COMMON_BLOCKS,
					'DISABLE' => BlockRepo::FILTER_SKIP_HIDDEN_BLOCKS,
				]);

				return $result;
			}
		);
	}

	/**
	 * Returns publication path string.
	 * @return string
	 */
	public static function getPublicationPath()
	{
		return '/';
	}

	/**
	 * Return general key for site path.
	 * @return string
	 */
	public static function getKeyCode()
	{
		return 'CODE';
	}

	/**
	 * Returns domain id for new site.
	 * @return int
	 */
	public static function getDomainId()
	{
		if (!Manager::isB24())
		{
			return Domain::getCurrentId();
		}

		return 0;
	}

	/**
	 * Returns filter value for 'TYPE' key.
	 * @return string
	 */
	public static function getFilterType()
	{
		return self::getCurrentScopeId();
	}

	/**
	 * Returns array of hook's codes, which excluded by scope.
	 * @return array
	 */
	public static function getExcludedHooks(): array
	{
		return [
			'B24BUTTON',
			'COPYRIGHT',
			'CSSBLOCK',
			'COOKIES',
			'FAVICON',
			'GACOUNTER',
			'GTM',
			'HEADBLOCK',
			'METAGOOGLEVERIFICATION',
			'METAMAIN',
			'METAROBOTS',
			'METAYANDEXVERIFICATION',
			'PIXELFB',
			'PIXELVK',
			'ROBOTS',
			'SETTINGS',
			'SPEED',
			'TRANSITION',
			'THEMEFONTS',
			'UP',
			'YACOUNTER',
		];
	}

	/**
	 * Scoped method for returning available operations of site.
	 *
	 * The main page is not managed through the roles of landing: the access to it is granted
	 * explicitly and its own entrances check the granted right strictly. The common model answers
	 * otherwise - it gives the whole set of operations to everyone while the scope has no rights
	 * rows at all, and the demo role of the scope, once installed, carries the same set to the group
	 * of all employees. Both make the site of the main page writable for an employee who was never
	 * given anything, so the operations of the scope are taken from its granted rights instead.
	 *
	 * @param int $siteId Site id.
	 * @return array|null Null leaves the site to the common model.
	 * @see \Bitrix\Landing\Rights::getOperationsForSite
	 * @see \Bitrix\Landing\Vibe\Provider\AbstractVibeProvider::canEdit
	 */
	public static function getOperationsForSite(int $siteId): ?array
	{
		// system actions of the portal run under ScopeGuard, which switches the checks off
		if (!Rights::isOn())
		{
			return null;
		}

		if (
			Manager::isAdmin()
			|| self::hasGrantedRight(Rights::ADDITIONAL_RIGHTS['admin'])
		)
		{
			return [
				Rights::ACCESS_TYPES['read'],
				Rights::ACCESS_TYPES['edit'],
				Rights::ACCESS_TYPES['sett'],
				Rights::ACCESS_TYPES['public'],
				Rights::ACCESS_TYPES['delete'],
			];
		}

		// the creator of a main page fills it with a template, which is written into its site
		if (self::hasGrantedRight(Rights::ADDITIONAL_RIGHTS['create']))
		{
			return [
				Rights::ACCESS_TYPES['read'],
				Rights::ACCESS_TYPES['edit'],
				Rights::ACCESS_TYPES['sett'],
				Rights::ACCESS_TYPES['public'],
			];
		}

		return [Rights::ACCESS_TYPES['read']];
	}

	/**
	 * Explicitly granted right of the scope (strict check).
	 * With the landing permissions feature off hasAdditionalRight() allows any code of the registry
	 * to everyone, so the feature gates the whole rights channel here as well as on the entrances.
	 * @param string $code Code from Rights::ADDITIONAL_RIGHTS.
	 * @return bool
	 * @see \Bitrix\Landing\Vibe\Provider\AbstractVibeProvider::hasExplicitVibeRight
	 */
	private static function hasGrantedRight(string $code): bool
	{
		return
			Manager::checkFeature(Manager::FEATURE_PERMISSIONS_AVAILABLE)
			&& Rights::hasAdditionalRight($code, Type::SCOPE_CODE_VIBE, false, true)
		;
	}

	/**
	 * Change manifest field by special conditions of site type
	 * @param array $manifest
	 * @return array prepared manifest
	 */
	public static function prepareBlockManifest(array $manifest): array
	{
		$allowedManifestKeys = [
			'block',
			'cards',
			'nodes',
			'style',
			'assets',
			'callbacks',
		];
		$manifest = array_filter(
			$manifest,
			function ($key) use ($allowedManifestKeys)
			{
				return in_array(mb_strtolower($key), $allowedManifestKeys);
			},
			ARRAY_FILTER_USE_KEY
		);

		$manifest['block']['type'] = (array)$manifest['block']['type'];

		// not all assets allowed
		if (isset($manifest['assets']))
		{
			$manifest['assets'] = [
				'ext' => array_filter(
					(array)$manifest['assets']['ext'],
					static function ($item)
					{
						return in_array(mb_strtolower($item), self::ALLOWED_EXTENSIONS, true);
					}
				),
			];

			if (empty($manifest['assets']['ext']))
			{
				unset($manifest['assets']);
			}
		}

		// unset not allowed subtypes
		if (isset($manifest['block']['subtype']))
		{
			$allowedSubtypes = [
				'widgetvue',
			];
			$manifest['block']['subtype'] = array_filter(
				(array)$manifest['block']['subtype'],
				function ($item) use ($allowedSubtypes)
				{
					return in_array(mb_strtolower($item), $allowedSubtypes);
				}
			);
		}
		if (empty($manifest['block']['subtype']))
		{
			unset($manifest['block']['subtype'], $manifest['block']['subtype_params']);
		}

		// unset not allowed callbacks
		if (isset($manifest['callbacks']))
		{
			$allowedCallbacks = [
				'afteradd',
				'beforeview',
			];
			$manifest['callbacks'] = array_filter(
				(array)$manifest['callbacks'],
				function ($item) use ($allowedCallbacks)
				{
					return in_array(mb_strtolower($item), $allowedCallbacks);
				},
				ARRAY_FILTER_USE_KEY
			);

			if (empty($manifest['callbacks']))
			{
				unset($manifest['callbacks']);
			}
		}

		//unset not allowed style
		$allowedStyles = [
			//for landing block
			'background',
			'color',
			'background-color',
			'padding-top',
			'padding-bottom',
			'padding-left',
			'padding-right',
			'margin-top',
			'margin-bottom',
			'margin-left',
			'margin-right',
			'text-align',
			'font-size',
			'font-family',
			'font-weight',
			'button',
			'text-transform',
			'container-max-width',
			//for widget
			'widget',
			'widget-type',
			//for separators
			'fill-first',
			'fill-second',
			'height-increased--md',
		];

		if (isset($manifest['style']['block']['type']))
		{
			$manifest['style']['block']['type'] = (array)$manifest['style']['block']['type'];
			$manifest['block']['section'] = (array)$manifest['block']['section'];
			$filtered = array_intersect($manifest['style']['block']['type'], $allowedStyles);
			$manifest['style']['block']['type'] = array_values($filtered);
			if (
				!in_array('widget-type', $manifest['style']['block']['type'], true)
				&& !in_array('widgets_separators', $manifest['block']['section'], true)
			)
			{
				$manifest['style']['block']['type'][] = 'widget-type';
			}
		}

		foreach (($manifest['style']['nodes'] ?? []) as &$node)
		{
			$node['type'] = (array)$node['type'];
			$node['type'] = array_values(array_intersect($node['type'], $allowedStyles));
		}
		unset($node);

		// if manifest not exist in style sections block and nodes
		if (
			isset($manifest['style'])
			&& !isset($manifest['style']['block'])
			&& !isset($manifest['style']['nodes'])
		)
		{
			foreach ($manifest['style'] as &$node)
			{
				$node['type'] = (array)$node['type'];
				$node['type'] = array_values(array_intersect($node['type'], $allowedStyles));
			}
			unset($node);
		}

		return $manifest;
	}

	public static function isExtensionAllow(string $code): bool
	{
		if (Landing\Landing::getEditMode())
		{
			return true;
		}

		return in_array($code, self::ALLOWED_EXTENSIONS, true);
	}

	/**
	 * @inheritdoc
	 */
	public static function getScopeIdForTransfer(): string
	{
		return 'mainpage';
	}

	/**
	 * @inheritdoc
	 */
	public static function onTransferFinishRedirectUrlGet(int $siteId): ?FinishRedirectLinkDto
	{
		$vibe = Landing\Vibe\Vibe::createBySiteId($siteId);
		if (
			isset($vibe)
			&& Loader::includeModule('intranet')
		)
		{
			return new FinishRedirectLinkDto(
				href: $vibe->getUrlPublic() ?? '/',
				text: Loc::getMessage('LANDING_IMPORT_FINISH_GOTO_VIBE')
			);
		}

		return null;
	}

	/**
	 * @inheritdoc
	 */
	public static function onTransferFinishAnalyticSend(int $siteId, Metrika\Metrika $metrika): void
	{
		$vibe = Landing\Vibe\Vibe::createBySiteId($siteId);
		if (isset($vibe))
		{
			$metrika->setParam(
				2,
				'chapter',
				$vibe->getModuleId() . '-' . $vibe->getEmbedId()
			);
		}
	}
}