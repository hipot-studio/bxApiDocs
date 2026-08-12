<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Guest\Auth;

use Bitrix\Main\Application as MainApplication;
use Bitrix\Main\Authentication\Application;
use Bitrix\Main\Event;
use Bitrix\Main\Localization\Loc;

/**
 * Authentication scope for IM guest users.
 *
 * Confines a guest session to a narrow set of physical paths needed for the guest UI,
 * REST and push. Any request outside this allowlist triggers main:onApplicationScopeError;
 * Bitrix's prolog responds with 403 and ends the request — see main/include.php.
 *
 * Mirrors {@see \Bitrix\Intranet\PublicApplication} architecturally but uses a dedicated
 * scope so the wider 'public' allowlist (livechat, videoconf, payment callbacks) does not
 * leak to guests.
 *
 * @see AuthorizationService::authorize() — issues sessions with this scope
 * @see \Bitrix\Im\V2\Controller\Filter\AuthorizationPrefilter — V2 controller allowlist
 * @see GuestRestAuth — legacy REST method allowlist
 */
class GuestApplication extends Application
{
	public const ID = 'im_guest';

	/**
	 * checkScope matches against $request->getScriptFile() (the physical PHP script after
	 * urlrewrite/SEF resolution), prefix-matched. So values here are script-file prefixes,
	 * not request URIs. '/guest/' covers '/guest/index.php' and anything below it.
	 *
	 * /bitrix/tools/conversion/ and /bitrix/tools/public_session.php are listed because the
	 * bitrix24 site template auto-fires them right after the guest page loads (analytics
	 * counter and session-keepalive); without the allowlist, scope check fails on the
	 * counter hit and triggers Logout()→regenerateId(), which destroys the guest session
	 * mid-AJAX-burst and surfaces as 401/500/"Could not start session by PHP" intermittently.
	 *
	 * Deliberately excluded: '/online/', '/desktop_app/', '/video/', '/pub/', '/docs/pub/',
	 * '/conference/', '/bitrix/tools/sale_*' — wider public scenarios that aren't part of
	 * the guest experience.
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
	];

	public static function onApplicationsBuildList(): array
	{
		return [
			'ID' => self::ID,
			'NAME' => Loc::getMessage('IM_GUEST_APPLICATION_NAME'),
			'DESCRIPTION' => Loc::getMessage('IM_GUEST_APPLICATION_DESCRIPTION'),
			'SORT' => 90,
			'CLASS' => self::class,
			'VISIBLE' => false,
		];
	}

	/**
	 * Two off-scope cases get special handling; everything else falls through to the prolog's
	 * default 403:
	 *
	 * - Employee profile ('/company/personal/user/...'): a guest can't pass the page's file-level
	 *   access (no Read on /company), so the intranet profile component — and its "no access"
	 *   stub — is never reached. Render that stub here instead of a blank 403 / login form.
	 * - Portal root ('/'): the guest is actively navigating into the portal — log out and redirect.
	 *
	 * For any other off-scope script (analytics counter, asset endpoint, stray legacy script) we
	 * fall through. Logout() must not run on those: Logout()→regenerateId() destroys the session
	 * and races the JS bundle's AJAX burst (the 401/500/"Could not start session" cascade).
	 */
	public static function onApplicationScopeError(Event $event): void
	{
		if ($event->getParameter('APPLICATION_ID') !== self::ID)
		{
			return;
		}

		$request = MainApplication::getInstance()->getContext()->getRequest();
		$uri = (string)$request->getDecodedUri();

		if (str_starts_with($uri, '/company/personal/user/'))
		{
			// The profile opens inside a SidePanel iframe (IFRAME=Y) over the guest messenger —
			// render the stub there. A full-page load of the same URL (reload / direct hit) carries
			// no IFRAME param and would strand the guest on a bare stub without the messenger; send
			// them back into the chat by invite code so reload stays navigable, like the portal.
			if ($request->get('IFRAME') !== 'Y')
			{
				$code = InviteCode::createFromRequest();
				if ($code !== null)
				{
					// Trailing slash: cloud nginx rejects the extension-less path without it before PHP routing.
					\LocalRedirect(\Bitrix\Im\V2\Guest\GuestService::ROUTE_PATH . $code->getValue() . '/');
				}
			}

			global $APPLICATION;
			$APPLICATION->RestartBuffer();
			require __DIR__ . '/../Controller/views/guest_profile_stub.php';
			die();
		}

		if ($uri !== '/')
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
