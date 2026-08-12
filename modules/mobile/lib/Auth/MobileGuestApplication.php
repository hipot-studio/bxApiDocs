<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Auth;

use Bitrix\Im\V2\Guest\Auth\GuestApplication;
use Bitrix\Main\Application as MainApplication;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Event;
use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\SiteTable;

// The base class lives in im while this class is resolved by main:OnApplicationsBuildList /
// main:onApplicationScopeError handlers in prolog_before, where im is not included yet and
// b_module_to_module order for equal SORT is nondeterministic. When im is too old or absent,
// leave this class undeclared: EventManager's class_exists() then skips the handlers silently,
// which is consistent with the product — mobile messenger is unavailable without im
// (mobile/tabs/chat.php).
if (!Loader::includeModule('im') || !class_exists(GuestApplication::class))
{
	return;
}

/**
 * Mobile-specific guest scope. Inherits the narrow base allow-list from {@see GuestApplication}
 * and adds only the mobile-native endpoints that the native mobile app actually hits during
 * guest checkout / messenger init.
 *
 * Replaces using the wide `MobileApplication` scope ('mobile') for guests: that scope allowed
 * CRM file endpoints, disk/extranet URLs, cloud bucket prefixes, /bitrix/components/* — none
 * of which are part of the guest experience. With a narrow scope, off-scope navigation surfaces
 * as a normal 403 instead of reaching legacy handlers that may not gate on `EXTERNAL_AUTH_ID`.
 *
 * @see GuestApplication — base scope (used for web-guest)
 * @see \MobileApplication — broad mobile scope used for employees
 * @see \Bitrix\Im\V2\Guest\Auth\AuthorizationService::authorize() — initial authorize with parent GuestApplication::ID
 * @see checkout.php — overrides applicationId to this one for mobile guest
 */
class MobileGuestApplication extends GuestApplication
{
	public const ID = 'im_guest_mobile';

	/**
	 * Base GuestApplication URLs + mobile-native specifics. JS-component / webcomponent /
	 * jn.php are listed because immobile messenger bundles are served from them and would
	 * otherwise off-scope after the first checkout.
	 */
	protected $validUrls = [
		'/guest/',
		'/bitrix/routing_index.php',
		'/rest/',
		'/bitrix/services/main/ajax.php',
		'/bitrix/services/rest/index.php',
		'/bitrix/services/pull/',
		'/bitrix/tools/conversion/',
		'/bitrix/tools/public_session.php',
		'/upload/',
		// mobile-native
		'/mobile/',
		'/mobileapp/',
		'/bitrix/services/mobileapp/jn.php',
		'/bitrix/services/mobile/jscomponent.php',
		'/bitrix/services/mobile/webcomponent.php',
	];

	public function __construct()
	{
		// When the portal has an extranet site enabled, the guest session can land on that
		// site (SITE_DIR='/extranet/') and the appmap returns '/extranet/mobile/...' URLs.
		// Mirror MobileApplication's extranet-site allowlist so native navigation doesn't
		// off-scope. We only add the mobile entry — '/extranet/contacts/personal.php' is
		// an employee feature and out of scope for guests.
		if (ModuleManager::isModuleInstalled('extranet'))
		{
			$extranetSiteId = Option::get('extranet', 'extranet_site', false);
			if ($extranetSiteId)
			{
				$site = SiteTable::getRow([
					'filter' => ['=LID' => $extranetSiteId],
					'select' => ['DIR'],
					'cache' => ['ttl' => 86400],
				]);
				if ($site && isset($site['DIR']))
				{
					$this->validUrls[] = $site['DIR'] . 'mobile/';
				}
			}
		}
	}

	public static function onApplicationsBuildList(): array
	{
		// Reuse parent's NAME/DESCRIPTION (loaded from im/lang/.../GuestApplication.php) —
		// application is invisible (VISIBLE=false), so user-facing labels don't matter;
		// keeping them in sync with the parent avoids duplicate translations.
		return array_merge(parent::onApplicationsBuildList(), [
			'ID' => self::ID,
			'CLASS' => self::class,
			'SORT' => 91,
		]);
	}

	/**
	 * Same intent as {@see GuestApplication::onApplicationScopeError} but bound to our own ID:
	 * only log out on '/' scope errors, ignore counter/asset off-scope hits so we don't kill
	 * the session in the middle of an AJAX burst.
	 */
	public static function onApplicationScopeError(Event $event): void
	{
		if ($event->getParameter('APPLICATION_ID') !== self::ID)
		{
			return;
		}

		$request = MainApplication::getInstance()->getContext()->getRequest();
		if ($request->getDecodedUri() !== '/')
		{
			return;
		}

		global $USER;
		if (is_object($USER) && $USER->IsAuthorized())
		{
			$USER->Logout();
		}

		\LocalRedirect('/');
	}
}