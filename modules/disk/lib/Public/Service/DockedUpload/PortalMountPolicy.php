<?php

declare(strict_types=1);

namespace Bitrix\Disk\Public\Service\DockedUpload;

use Bitrix\Disk\Configuration;
use Bitrix\Disk\Integration\Bitrix24Manager;
use Bitrix\Disk\Internal\Service\UnifiedLink\UnifiedLinkUrl;
use Bitrix\Main\Application;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\HttpRequest;

/**
 * Whether the docked uploader entry point is mounted on a portal page.
 *
 * The answer is asked on every hit that reaches the epilog, so the rules go from the cheapest to the
 * most expensive: constants and feature flags first, the Disk tool access (a business tools lookup in
 * the cloud) last. The policy reads the request and the environment only: it neither writes nor asks
 * the upload queue - whether a queue is there to restore is the concern of the extension settings.
 *
 * The Disk tool access of a limited license looks the user up on every call, so its verdict - a grant and
 * a denial alike - is kept in the kernel session for the user and a few minutes (TOOL_ACCESS_TTL_SECONDS):
 * a spent allowance and an access granted by an administrator in the day both show within that time.
 * Without a started session (CLI, a hit that never opened it) the access is asked as is.
 */
final class PortalMountPolicy
{
	private const PORTAL_TEMPLATE_ID = 'bitrix24';

	/** Same markers as the im application context, kept here so the entry point does not need im. */
	private const DESKTOP_API_VERSION_PARAMETER = 'BXD_API_VERSION';
	private const DESKTOP_USER_AGENT_MARKER = 'bitrixdesktop';
	private const MOBILE_USER_AGENT_MARKER = 'bitrixmobile';

	/** Defined before the epilog by a page that must stay without the docked uploader. */
	public const SKIP_CONSTANT = 'BX_DISK_DOCKED_UPLOADER_SKIP';

	/** Kernel session entry: ['userId' => int, 'checkedAt' => int, 'granted' => bool], one user at a time. */
	private const TOOL_ACCESS_SESSION_KEY = 'DISK_DOCKED_UPLOADER_PORTAL_TOOL_ACCESS';

	/**
	 * How long a kept verdict answers, in seconds. Five minutes spare the lookup on most hits of a browsing
	 * user, and a change of the access - the allowance spent or granted anew - is late by five minutes at most.
	 */
	private const TOOL_ACCESS_TTL_SECONDS = 300;

	private const TOOL_CODE = 'disk';

	/** @var \Closure(string): mixed */
	private readonly \Closure $constantReader;

	/** @var \Closure(): ?\ArrayAccess */
	private readonly \Closure $sessionProvider;

	/** @var \Closure(int): bool */
	private readonly \Closure $toolAccessChecker;

	/** @var \Closure(): int */
	private readonly \Closure $clock;

	/**
	 * Every closure is there for tests; the portal leaves them all to the defaults.
	 *
	 * @param \Closure(string): mixed|null $constantReader Value of a named constant, null when it is not
	 * defined. The page constants are defined once per process, so tests put their own reader here.
	 * @param \Closure(): ?\ArrayAccess|null $sessionProvider Storage for the tool access verdict, null when
	 * there is none; the default is the kernel session once it is started.
	 * @param \Closure(int): bool|null $toolAccessChecker Whether the user has the Disk tool, without counting.
	 * @param \Closure(): int|null $clock The current time as a Unix timestamp.
	 */
	public function __construct(
		private readonly UnifiedLinkUrl $unifiedLinkUrl,
		?\Closure $constantReader = null,
		?\Closure $sessionProvider = null,
		?\Closure $toolAccessChecker = null,
		?\Closure $clock = null,
	)
	{
		$this->constantReader = $constantReader
			?? static fn (string $name): mixed => defined($name) ? constant($name) : null;
		$this->sessionProvider = $sessionProvider ?? static function (): ?\ArrayAccess {
			$session = Application::getInstance()->getKernelSession();

			return $session->isStarted() && $session instanceof \ArrayAccess ? $session : null;
		};
		// The check form: the counting one spends a day's allowance of a limited user on every page view.
		$this->toolAccessChecker = $toolAccessChecker
			?? static fn (int $userId): bool => (bool)Bitrix24Manager::checkAccessEnabled(self::TOOL_CODE, $userId);
		$this->clock = $clock ?? static fn (): int => time();
	}

	public static function getInstance(): self
	{
		return ServiceLocator::getInstance()->get(self::class);
	}

	public function shouldMountOnPortal(HttpRequest $request, int $userId): bool
	{
		if (!Configuration::isEnabledDockedUploaderOnPortal())
		{
			return false;
		}

		if ($userId <= 0)
		{
			return false;
		}

		if (!$this->isPortalPage())
		{
			return false;
		}

		if ($this->isBackgroundOrFramedRequest($request))
		{
			return false;
		}

		if ($this->isApplicationClient($request))
		{
			return false;
		}

		// The signature alone is enough: a page opened by unified link stays with its own uploader.
		if (is_string($this->unifiedLinkUrl->getUlsFromRequest($request)))
		{
			return false;
		}

		return $this->hasDiskTool($userId);
	}

	private function hasDiskTool(int $userId): bool
	{
		$session = ($this->sessionProvider)();
		if ($session === null)
		{
			return ($this->toolAccessChecker)($userId);
		}

		$now = ($this->clock)();
		$cached = $session[self::TOOL_ACCESS_SESSION_KEY] ?? null;
		if (
			is_array($cached)
			&& ($cached['userId'] ?? null) === $userId
			&& is_int($cached['checkedAt'] ?? null)
			&& $now - $cached['checkedAt'] < self::TOOL_ACCESS_TTL_SECONDS
			&& is_bool($cached['granted'] ?? null)
		)
		{
			return $cached['granted'];
		}

		$granted = ($this->toolAccessChecker)($userId);
		$session[self::TOOL_ACCESS_SESSION_KEY] = ['userId' => $userId, 'checkedAt' => $now, 'granted' => $granted];

		return $granted;
	}

	private function isPortalPage(): bool
	{
		if ($this->readConstant('ADMIN_SECTION') === true)
		{
			return false;
		}

		if ($this->readConstant('SITE_TEMPLATE_ID') !== self::PORTAL_TEMPLATE_ID)
		{
			return false;
		}

		if ($this->readConstant('BX_IM_FULLSCREEN'))
		{
			return false;
		}

		// An ajax response of a component or a controller, whatever way the request came in.
		if ($this->readConstant('PUBLIC_AJAX_MODE') !== null)
		{
			return false;
		}

		return $this->readConstant(self::SKIP_CONSTANT) === null;
	}

	private function readConstant(string $name): mixed
	{
		return ($this->constantReader)($name);
	}

	private function isBackgroundOrFramedRequest(HttpRequest $request): bool
	{
		// AJAX_CALL is the ajax mode of components: in the query, or in the post of a form sent to a hidden
		// frame with no ajax header at all. The search ajax_call comes as XHR and is caught above it.
		if ($request->isAjaxRequest() || $request->get('AJAX_CALL') === 'Y')
		{
			return true;
		}

		// A slider, and a social network popup (IFRAME=Y&SONET=Y) as well: both render in a frame.
		if ($request->getQuery('IFRAME') === 'Y')
		{
			return true;
		}

		return $request->getQuery('RELOAD') === 'Y';
	}

	private function isApplicationClient(HttpRequest $request): bool
	{
		if ($request->getQuery(self::DESKTOP_API_VERSION_PARAMETER) !== null)
		{
			return true;
		}

		$userAgent = mb_strtolower((string)$request->getUserAgent());

		return str_contains($userAgent, self::DESKTOP_USER_AGENT_MARKER)
			|| str_contains($userAgent, self::MOBILE_USER_AGENT_MARKER);
	}
}
