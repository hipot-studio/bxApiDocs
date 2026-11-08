<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Guest\Auth;

use Bitrix\Im\V2\Entity\User\UserGuest;
use Bitrix\Main\Application as MainApplication;
use Bitrix\Main\Authentication\Application;
use Bitrix\Main\Authentication\ApplicationManager;
use Bitrix\Main\Event;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Web\Uri;

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

	/**
	 * Public scope of the portal {@see \Bitrix\Intranet\PublicApplication}: its allowlist is the list of
	 * public pages, so a page it serves is a page a guest may be handed over to.
	 */
	protected const PORTAL_PUBLIC_SCOPE_ID = 'public';

	/**
	 * Prefixes of that scope carrying the web messenger instead of a public page: bare, they answer a
	 * visitor without a portal account with the login form. The value constrains the segment that turns
	 * into 'alias' under the prefix; null - there is no such route and the alias comes from the query.
	 */
	protected const PORTAL_MESSENGER_PREFIXES = [
		'/online/' => '/^[\.\-0-9a-zA-Z]+$/',
		'/desktop_app/' => null,
	];

	/**
	 * What {@see \Bitrix\Main\HttpRequest::getScriptFile} returns when the address resolved to no page at
	 * all. The public scope matches the raw address too, so without this the portal's 404 under a prefix
	 * of that scope would cost the guest their session.
	 */
	protected const UNRESOLVED_REQUEST_SCRIPTS = [
		'/bitrix/routing_index.php',
		'/bitrix/urlrewrite.php',
		'/404.php',
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
	 * Three off-scope cases get an answer of their own, everything else falls through to the prolog's
	 * 403. Logout() must not run on those: Logout()→regenerateId() destroys the session and races the
	 * JS bundle's AJAX burst (the 401/500/"Could not start session" cascade).
	 */
	public static function onApplicationScopeError(Event $event): void
	{
		if (!static::handlesScope((string)$event->getParameter('APPLICATION_ID')))
		{
			return;
		}

		$request = MainApplication::getInstance()->getContext()->getRequest();
		$uri = (string)$request->getDecodedUri();

		if (static::rendersEmployeeProfileStub() && str_starts_with($uri, '/company/personal/user/'))
		{
			static::renderEmployeeProfileStub($request);
		}

		if (static::isPortalPublicPageNavigation($request, static::getCurrentExternalAuthId()))
		{
			static::leavePortalScope($request);
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

	/** Read through static::ID: that is what lets a subclass be served by this handler under its own id. */
	protected static function handlesScope(string $applicationId): bool
	{
		return $applicationId === static::ID;
	}

	/**
	 * Whether an off-scope hit on the employee profile is answered with the web stub page: the stub
	 * renders over the web messenger, so a scope whose client is the native app answers with 403.
	 */
	protected static function rendersEmployeeProfileStub(): bool
	{
		return true;
	}

	/**
	 * A guest cannot pass the file-level access on '/company', so the intranet profile component and its
	 * own "no access" stub are never reached: render the stub here instead of a blank 403 or login form.
	 */
	protected static function renderEmployeeProfileStub(HttpRequest $request): never
	{
		// The profile opens inside a SidePanel iframe (IFRAME=Y) over the guest messenger. A full-page
		// load carries no IFRAME param and would strand the guest on a bare stub without the messenger:
		// send them back into the chat by invite code, so reload stays navigable like on the portal.
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

	/**
	 * Tells a guest following a chat link onto a public page of the portal from any other off-scope hit.
	 * The public scope carries the decision on the page, minus the places where its allowlist means "a
	 * session of that scope is let here" rather than "a page for a visitor without an account".
	 *
	 * @param string|null $externalAuthId EXTERNAL_AUTH_ID of the current session, null if anonymous
	 */
	protected static function isPortalPublicPageNavigation(HttpRequest $request, ?string $externalAuthId): bool
	{
		// An employee session opened with an application password carries an applicationId too, and
		// its scope errors must never end in a logout.
		if ($externalAuthId !== UserGuest::AUTH_ID)
		{
			return false;
		}

		if (!static::isTopLevelNavigation($request))
		{
			return false;
		}

		if (static::hasTraversalSegment($request))
		{
			return false;
		}

		if (static::isPortalMessengerWithoutAlias($request))
		{
			return false;
		}

		if (!static::resolvedToPage($request))
		{
			return false;
		}

		return static::servesPortalPublicScope();
	}

	/**
	 * The alias is looked for in both places it can be by the time the prolog runs: routing has not
	 * happened yet, so under '/online/' it is still a path segment - and only one inside the route
	 * constraint becomes it - while 'router.php', hit directly, branches on the query alone.
	 *
	 * @see self::PORTAL_MESSENGER_PREFIXES
	 */
	protected static function isPortalMessengerWithoutAlias(HttpRequest $request): bool
	{
		$path = rawurldecode((new Uri((string)$request->getRequestUri()))->getPath());

		foreach (static::PORTAL_MESSENGER_PREFIXES as $prefix => $aliasPattern)
		{
			if (!str_starts_with($path, $prefix))
			{
				continue;
			}

			$queryAlias = $request->get('alias');
			if (is_string($queryAlias) && $queryAlias !== '')
			{
				return false;
			}

			$segment = explode('/', substr($path, strlen($prefix)))[0];

			return $aliasPattern === null || preg_match($aliasPattern, $segment) !== 1;
		}

		return false;
	}

	/** @see self::UNRESOLVED_REQUEST_SCRIPTS */
	protected static function resolvedToPage(HttpRequest $request): bool
	{
		return !in_array((string)$request->getScriptFile(), static::UNRESOLVED_REQUEST_SCRIPTS, true);
	}

	/**
	 * A path with a '..' segment names one page and is served another - the scope matches the raw request
	 * target, the web server hands PHP the resolved path - so there is nothing to hand over.
	 */
	protected static function hasTraversalSegment(HttpRequest $request): bool
	{
		$path = (new Uri((string)$request->getRequestUri()))->getPath();

		return in_array('..', explode('/', rawurldecode($path)), true);
	}

	/**
	 * Asked exactly as the prolog asks it, so a page is judged by the same rule that lets the visitor
	 * onto it afterwards. Returns false when the scope is not registered at all.
	 */
	protected static function servesPortalPublicScope(): bool
	{
		return ApplicationManager::getInstance()->checkScope(static::PORTAL_PUBLIC_SCOPE_ID) === true;
	}

	/**
	 * A request nobody navigated must not end the session: the guest page fires counter and keepalive
	 * hits of its own, and a browser prefetches a link long before the guest follows it. isAjaxRequest()
	 * goes first: it reads BX-Ajax / X-Requested-With, which the framework's own calls do send.
	 */
	protected static function isTopLevelNavigation(HttpRequest $request): bool
	{
		if ($request->getRequestMethod() !== 'GET' || $request->isAjaxRequest())
		{
			return false;
		}

		if (static::isSpeculativeLoad($request))
		{
			return false;
		}

		$mode = $request->getHeader('Sec-Fetch-Mode');
		$destination = $request->getHeader('Sec-Fetch-Dest');

		// Either header present means the client speaks Fetch Metadata and the set has to confirm the
		// navigation, as far as the scope needs it {@see requiresUserActivation()}; both missing means the
		// client speaks none of it and must not lose the exit for that {@see acceptsDocument()}.
		if ($mode === null && $destination === null)
		{
			return static::acceptsDocument($request);
		}

		if ($mode !== 'navigate' || $destination !== 'document')
		{
			return false;
		}

		// Sec-Fetch-Site is left unread on purpose - a link out of an external mail is cross-site - and a
		// redirect carries the activation across: a foreign page the guest clicked can answer 302 to a
		// public page with Sec-Fetch-User still '?1' (measured). The price is the one accepted below.
		return !static::requiresUserActivation() || $request->getHeader('Sec-Fetch-User') === '?1';
	}

	/**
	 * Whether a client that speaks Fetch Metadata has to confirm the navigation with Sec-Fetch-User on
	 * top of the pair above: the webview of the native app sends no such header at all, measured
	 * {@see \Bitrix\Mobile\Auth\MobileGuestApplication::requiresUserActivation}.
	 */
	protected static function requiresUserActivation(): bool
	{
		return true;
	}

	/**
	 * The only thing a client without Fetch Metadata leaves to judge by, and it tells a page from a
	 * subresource but not from a hidden frame: a foreign site can end such a client's session by framing
	 * a public page. Accepted - the cookies survive, the invite link returns the same guest to the chat.
	 */
	protected static function acceptsDocument(HttpRequest $request): bool
	{
		// 'text/html' and not just "no image type": a real navigation always names it. Accept is
		// CORS-safelisted, so a scripted no-cors fetch() may name it too; both that and the frame need
		// 'cookies.samesite' => 'None', which .settings.php ships without, to carry the guest cookies.
		return str_contains(mb_strtolower((string)$request->getHeader('Accept')), 'text/html');
	}

	/** Sec-Purpose carries a token list ('prefetch;prerender'); Purpose is the pre-standard spelling. */
	protected static function isSpeculativeLoad(HttpRequest $request): bool
	{
		foreach (['Sec-Purpose', 'Purpose'] as $headerName)
		{
			$purpose = mb_strtolower((string)$request->getHeader($headerName));
			if (str_contains($purpose, 'prefetch') || str_contains($purpose, 'prerender'))
			{
				return true;
			}
		}

		return false;
	}

	protected static function getCurrentExternalAuthId(): ?string
	{
		global $USER;

		if (!is_object($USER) || !$USER->IsAuthorized())
		{
			return null;
		}

		$externalAuthId = $USER->GetParam('EXTERNAL_AUTH_ID');

		return is_string($externalAuthId) ? $externalAuthId : null;
	}

	/**
	 * Ends the guest session and repeats the same request without the im_guest scope, so the target page
	 * authorizes the visitor itself. Suspended, not logged out, so the invite link returns the same guest
	 * {@see AuthorizationService::suspendCurrentGuestSession}; once the attempts are spent, 403 stands.
	 */
	protected static function leavePortalScope(HttpRequest $request): void
	{
		if (!AuthorizationService::getInstance()->suspendCurrentGuestSession())
		{
			return;
		}

		// The browser repeats the request the moment it reads the redirect, so the lock this request took on
		// the session by dropping SESS_AUTH goes back before the redirect, not on shutdown.
		AuthorizationService::getInstance()->releaseSessionLock();

		\LocalRedirect(static::buildRepeatedRequestUri($request));
	}

	/**
	 * A host-less path, so the repeat cannot leave this host; the query comes over as it was, save for
	 * the CR, LF and '&amp;' that Redirect::modifyBySecurity() takes out. Not rebuilt through
	 * Uri::addParams(): parse_str renames parameters holding a dot or a space and drops duplicates.
	 */
	protected static function buildRepeatedRequestUri(HttpRequest $request): string
	{
		$requested = new Uri((string)$request->getRequestUri());

		// Backslashes are trimmed along with the slashes: Uri drops a '//host' authority but keeps
		// '/\host', and a browser reads that in a Location header as '//host'.
		$path = '/' . ltrim($requested->getPath(), "/\\");
		$query = $requested->getQuery();

		return $query === '' ? $path : $path . '?' . $query;
	}
}
