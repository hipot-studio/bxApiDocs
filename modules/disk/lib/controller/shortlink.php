<?php

declare(strict_types=1);

namespace Bitrix\Disk\Controller;

use Bitrix\Disk\Document\DocumentEditorUser;
use Bitrix\Disk\Internal\Access\UnifiedLink\UnifiedLinkAccessLevel;
use Bitrix\Disk\Internal\Service\UnifiedLink\ExternalLinkContext;
use Bitrix\Disk\Internal\Service\UnifiedLink\Render\StandalonePageRenderer;
use Bitrix\Disk\Internal\Service\UnifiedLink\Render\UnifiedLinkRenderer;
use Bitrix\Disk\Internal\Service\UnifiedLink\ShortLinkOpening;
use Bitrix\Disk\User;
use Bitrix\Main\Context;
use Bitrix\Main\Engine\ActionFilter\Authentication;
use Bitrix\Main\Engine\ActionFilter\Csrf;
use Bitrix\Main\Engine\ActionFilter\HttpMethod;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Error;
use Bitrix\Main\HttpResponse;
use Bitrix\Main\Response;
use Bitrix\Main\UserTable;

/**
 * The entry of the short public address /docs/pub/s/{code}/, reached through the named route of intranet.
 *
 * A plain GET opens the link in the mode the link grants. A POST to the same address carries the code once
 * more, the requested mode and the operation: a mode transition is the explicit step of an employee and needs
 * the session, the CSRF token and the right of the employee; a continuation (password, retry, deferred load)
 * keeps the anonymous contract and never raises the mode above the one of the link. The move is checked by the
 * effective right of the employee {@see ShortLinkOpening::getEffectiveAccessLevel()}. Every refusal reads the
 * same and tells nothing about the file or the reason.
 */
class ShortLink extends Controller
{
	public const PARAM_CODE = 'shortLinkCode';
	public const PARAM_REQUESTED_MODE = 'requestedMode';
	public const PARAM_OPERATION = 'operation';

	public const MODE_VIEW = 'view';
	public const MODE_EDIT = 'edit';

	public const OPERATION_MODE_TRANSITION = 'modeTransition';
	public const OPERATION_CONTINUE = 'continue';

	/**
	 * Actions of the public page of a file served on the short address itself: the content, its previews and
	 * viewers. The page names them in the query, and every one resolves and checks the opening again.
	 */
	private const PAGE_ACTIONS = [
		'download',
		'showbygoogleviewer',
		'showbyonlyofficeviewer',
		'showbyboardsviewer',
		'showviewhtml',
		'showhtml',
		'showfile',
		'showtiffpreview',
		'showpreview',
		'showview',
	];

	/** Query parameters naming a mode or another source, which a short address never takes. */
	private const FORBIDDEN_QUERY_PARAMS = [
		self::PARAM_CODE,
		self::PARAM_REQUESTED_MODE,
		self::PARAM_OPERATION,
		'attachedId',
		'versionId',
		'exactMode',
	];

	protected function getDefaultPreFilters(): array
	{
		// The entry is public: authentication and the CSRF token are checked by the operation that needs them,
		// and the disabled CSRF filter keeps the controller from adding the enabled one to every POST.
		return [
			new HttpMethod([HttpMethod::METHOD_GET, HttpMethod::METHOD_POST]),
			new Csrf(false),
		];
	}

	public function configureActions(): array
	{
		return [
			'getNativeOpening' => [
				'-prefilters' => [
					Csrf::class,
					HttpMethod::class,
				],
				'+prefilters' => [
					new Authentication(),
					new HttpMethod([HttpMethod::METHOD_POST]),
					new Csrf(),
				],
			],
		];
	}

	public function finalizeResponse(Response $response): void
	{
		if ($response instanceof HttpResponse)
		{
			self::protect($response);
		}
	}

	/**
	 * @param string $any The rest of the path after /docs/pub/s/, the code with or without the trailing slash.
	 */
	public function openAction(string $any = ''): HttpResponse
	{
		// A viewer of the page may finish the request itself and send the response of the context instead of
		// the one returned here, so that response carries the same protection.
		$contextResponse = Context::getCurrent()?->getResponse();
		if ($contextResponse instanceof HttpResponse)
		{
			self::protect($contextResponse);
		}

		$code = str_ends_with($any, '/') ? substr($any, 0, -1) : $any;
		if (!ShortLinkOpening::isValidCode($code) || $this->hasForbiddenQuery())
		{
			return $this->deny();
		}

		$opening = ShortLinkOpening::resolve($code);
		if ($opening === null)
		{
			return $this->deny();
		}

		$pageAction = $this->request->getQuery('action');
		if ($pageAction !== null)
		{
			return is_string($pageAction) && in_array(strtolower($pageAction), self::PAGE_ACTIONS, true)
				? self::protect($this->renderPageAction($opening, $pageAction))
				: $this->deny()
			;
		}

		$accessLevel = $this->request->isPost()
			? $this->resolvePostedAccessLevel($opening)
			: $this->resolveSourceAccessLevel($opening)
		;
		if ($accessLevel === null)
		{
			return $this->deny();
		}

		return self::protect($this->renderOpening($opening, $accessLevel));
	}

	/**
	 * The unique code of the file for the native viewer of the mobile application: with it the opener shows the
	 * file itself, without it the short address in the web view. Only an employee gets it, and only while the
	 * unified address of the file gives him what the short address does
	 * {@see ShortLinkOpening::getNativeUniqueCode()}; every refusal is one and the same error.
	 *
	 * @return array{uniqueCode: string}|null
	 */
	public function getNativeOpeningAction(string $code): ?array
	{
		$opening = ShortLinkOpening::resolve($code);
		$userId = $this->getEmployeeId();
		$uniqueCode =
			$opening !== null && $userId !== null && !self::isExternalUser($userId)
				? $opening->getNativeUniqueCode($userId)
				: null
		;
		if ($uniqueCode === null)
		{
			$this->addError(new Error('Access denied.', 'ACCESS_DENIED'));

			return null;
		}

		return ['uniqueCode' => $uniqueCode];
	}

	private function resolveSourceAccessLevel(ShortLinkOpening $opening): UnifiedLinkAccessLevel
	{
		return $opening->allowsEditBySource() ? UnifiedLinkAccessLevel::Edit : UnifiedLinkAccessLevel::Read;
	}

	private function resolvePostedAccessLevel(ShortLinkOpening $opening): ?UnifiedLinkAccessLevel
	{
		$code = $this->request->getPost(self::PARAM_CODE);
		$requestedMode = $this->request->getPost(self::PARAM_REQUESTED_MODE);
		$operation = $this->request->getPost(self::PARAM_OPERATION);
		if (
			!is_string($code)
			|| $code !== $opening->getCode()
			|| !in_array($requestedMode, [self::MODE_VIEW, self::MODE_EDIT], true)
		)
		{
			return null;
		}

		return match ($operation)
		{
			self::OPERATION_MODE_TRANSITION => $this->resolveTransitionAccessLevel($opening, $requestedMode),
			// A continuation goes on in the mode of the source: the body may lower it, never raise it.
			self::OPERATION_CONTINUE => $requestedMode === self::MODE_VIEW
				? UnifiedLinkAccessLevel::Read
				: $this->resolveSourceAccessLevel($opening)
			,
			default => null,
		};
	}

	private function resolveTransitionAccessLevel(
		ShortLinkOpening $opening,
		string $requestedMode,
	): ?UnifiedLinkAccessLevel
	{
		$userId = $this->getEmployeeId();
		if ($userId === null || !$this->isCsrfTokenValid())
		{
			return null;
		}

		// The move reads the effective right: the own right of the employee and the checked source, nothing else.
		$effectiveAccessLevel = $opening->getEffectiveAccessLevel($userId);
		if ($effectiveAccessLevel === null)
		{
			return null;
		}

		if ($requestedMode === self::MODE_VIEW)
		{
			return $effectiveAccessLevel->canRead() ? UnifiedLinkAccessLevel::Read : null;
		}

		// An exact revision is read-only whoever asks, and the effective right of it is never above reading.
		return $effectiveAccessLevel->canEdit() ? UnifiedLinkAccessLevel::Edit : null;
	}

	/**
	 * Draws the opening in the checked mode through the response of the unified link, on the short address.
	 */
	protected function renderOpening(ShortLinkOpening $opening, UnifiedLinkAccessLevel $accessLevel): HttpResponse
	{
		$controller = new UnifiedLinkController($this->request);
		$currentUser = $this->getCurrentUser();
		if ($currentUser !== null)
		{
			$controller->setCurrentUser($currentUser);
		}

		// The stub of a deferred load goes on as a continuation, which cannot carry the move of an employee.
		$isModeTransition = $this->request->isPost()
			&& $this->request->getPost(self::PARAM_OPERATION) === self::OPERATION_MODE_TRANSITION
		;

		return $controller->renderShortLinkOpening($opening, $accessLevel, immediate: $isModeTransition);
	}

	/**
	 * Runs an action of the public page of the source: the page checks the password and the token of the
	 * content itself, as on the HASH address, and a refusal or an expired token lands on the opening itself.
	 */
	protected function renderPageAction(ShortLinkOpening $opening, string $action): HttpResponse
	{
		$content = StandalonePageRenderer::render('bitrix:disk.external.link', '', [
			'action' => $action,
			'FROM_UNIFIED_LINK' => true,
			'BASE_OBJECT' => $opening->getFile(),
			'EXTERNAL_LINK' => $opening->getExternalLink(),
			'VERSION' => $opening->getVersion(),
			'SHORT_LINK_OPENING' => $opening,
		]);

		return (new HttpResponse())->setContent($content);
	}

	/**
	 * The user of the session when it is an employee; the public editor accounts are guests of the link.
	 */
	protected function getEmployeeId(): ?int
	{
		$userId = (int)$this->getCurrentUser()?->getId();
		if ($userId <= 0)
		{
			return null;
		}

		$user = User::loadById($userId);
		if (
			!$user instanceof User
			|| in_array($user->getExternalAuthId(), ['call', DocumentEditorUser::EXTERNAL_AUTH_ID], true)
		)
		{
			return null;
		}

		return $userId;
	}

	/**
	 * A user of a type the portal keeps for its services and guests: a bot, a mail correspondent, a guest of the
	 * messenger, the public account of the editor and the like.
	 */
	private static function isExternalUser(int $userId): bool
	{
		return in_array(
			(string)User::loadById($userId)?->getExternalAuthId(),
			UserTable::getExternalUserTypes(),
			true,
		);
	}

	protected function isCsrfTokenValid(): bool
	{
		$token = $this->request->getPost('sessid') ?? $this->request->getHeader('X-Bitrix-Csrf-Token');

		return is_string($token) && $token !== '' && hash_equals(bitrix_sessid(), $token);
	}

	private function hasForbiddenQuery(): bool
	{
		foreach ([...self::FORBIDDEN_QUERY_PARAMS, ExternalLinkContext::getParameterName()] as $name)
		{
			if ($this->request->getQuery($name) !== null)
			{
				return true;
			}
		}

		return false;
	}

	private function deny(): HttpResponse
	{
		return self::protect(
			(new HttpResponse())
				->setStatus(404)
				->setContent(UnifiedLinkRenderer::renderAccessDeniedPage())
		);
	}

	private static function protect(HttpResponse $response): HttpResponse
	{
		$response->getHeaders()->set('Cache-Control', 'private, no-store');
		$response->getHeaders()->set('Referrer-Policy', 'no-referrer');

		return $response;
	}
}
