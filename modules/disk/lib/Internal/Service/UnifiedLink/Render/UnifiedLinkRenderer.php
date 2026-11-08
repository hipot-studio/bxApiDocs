<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\UnifiedLink\Render;

use Bitrix\Disk\AttachedObject;
use Bitrix\Disk\BaseObject;
use Bitrix\Disk\Configuration;
use Bitrix\Disk\Document\DocumentEditorUser;
use Bitrix\Disk\Driver;
use Bitrix\Disk\ExternalLink;
use Bitrix\Disk\File;
use Bitrix\Disk\Folder;
use Bitrix\Disk\Internal\Access\UnifiedLink\UnifiedLinkAccessLevel;
use Bitrix\Disk\Internal\Service\HtmlViewer\PublicDomainAccessContext;
use Bitrix\Disk\Internal\Service\UnifiedLink\BaseObjectResolver;
use Bitrix\Disk\Internal\Service\UnifiedLink\FileHandler\AutomaticEditModeCapable;
use Bitrix\Disk\Internal\Service\UnifiedLink\FileHandler\EditTransitionAware;
use Bitrix\Disk\Internal\Service\UnifiedLink\FileHandler\HandlerOperationResult;
use Bitrix\Disk\Internal\Service\UnifiedLink\FileHandler\HtmlRenderableHandler;
use Bitrix\Disk\Internal\Service\UnifiedLink\FileHandler\HtmlRenderableHandlerFactory;
use Bitrix\Disk\Internal\Service\UnifiedLink\FileHandler\OpeningNoticeCodeAware;
use Bitrix\Disk\Internal\Service\UnifiedLink\FileHandler\VibeofficeHtmlRenderableHandler;
use Bitrix\Disk\Internal\Service\UnifiedLink\UnifiedLinkAccessService;
use Bitrix\Disk\Public\Provider\ExternalLinkProvider;
use Bitrix\Disk\TypeFile;
use Bitrix\Disk\User;
use Bitrix\Disk\Version;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\CurrentUser;
use LogicException;

class UnifiedLinkRenderer
{
	private UnifiedLinkAccessService $unifiedLinkAccessService;
	private HtmlRenderableHandlerFactory $handlerFactory;
	private ExternalLinkProvider $externalLinkProvider;
	private ?CurrentUser $currentUser;
	private ?UnifiedLinkAccessLevel $accessLevel = null;
	private ?bool $shouldRenderExternal = null;
	private bool $isStoredExternalLinkResolved = false;
	private ?ExternalLink $storedExternalLink = null;

	public function __construct(
		private readonly BaseObject $baseObject,
		private readonly ?AttachedObject $attachedObject = null,
		private readonly ?Version $version = null,
		private readonly array $analytics = [],
		?CurrentUser $currentUser = null,
		private readonly bool $deferred = false,
		private readonly ?ExternalLink $externalLink = null,
		private readonly ModeSelection $modeSelection = ModeSelection::KeepRequested,
		private readonly ?PublicDomainAccessContext $publicDomainAccessContext = null,
	) {
		if ($currentUser instanceof CurrentUser && (int)$currentUser->getId() === 0)
		{
			$currentUser = null;
		}

		$serviceLocator = ServiceLocator::getInstance();
		$this->unifiedLinkAccessService = $serviceLocator->get(UnifiedLinkAccessService::class);
		$this->handlerFactory = $serviceLocator->get(HtmlRenderableHandlerFactory::class);
		$this->externalLinkProvider = $serviceLocator->get(ExternalLinkProvider::class);
		$this->currentUser = $currentUser;
	}

	public function getAccessLevel(): UnifiedLinkAccessLevel
	{
		if ($this->accessLevel === null)
		{
			$baseObject = $this->resolveBaseObject();
			$version = $this->resolveVersion();
			$this->accessLevel = $this->unifiedLinkAccessService->check(
				$baseObject,
				$this->resolveAttachedObject(),
				userId: $this->publicDomainAccessContext?->getUserId() ?? (int)$this->currentUser?->getId(),
				externalLink: $this->externalLink,
				version: $version,
			);

			if ($this->opensHistoricalRevision() && $this->accessLevel === UnifiedLinkAccessLevel::Edit)
			{
				$this->accessLevel = UnifiedLinkAccessLevel::Read;
			}
		}

		return $this->accessLevel;
	}

	/**
	 * A revision is read-only however the link names it: by the versionId of the address or by an
	 * attachment pinned to it, which the session is bound to just the same.
	 */
	private function opensHistoricalRevision(): bool
	{
		return $this->resolveVersion() !== null || (bool)$this->resolveAttachedObject()?->isSpecificVersion();
	}

	public function shouldRenderExternal(): bool
	{
		if ($this->publicDomainAccessContext !== null)
		{
			return false;
		}

		return $this->shouldRenderExternal ??= (function (): bool {
			if ($this->getAccessLevel() !== UnifiedLinkAccessLevel::Denied)
			{
				return false;
			}
			$externalLink = $this->resolveExternalLink();

			return $externalLink instanceof ExternalLink && $externalLink->hasPassword();
		})();
	}

	public function resolveBaseObject(): BaseObject
	{
		return BaseObjectResolver::resolve($this->baseObject, $this->version);
	}

	/**
	 * The attachment the link addresses, which is one of the addressed file or none: an attachedId naming
	 * an attachment of another object is answered for by neither the access check nor the document source,
	 * so the link opens the file it names whichever caller built the renderer.
	 */
	public function resolveAttachedObject(): ?AttachedObject
	{
		return BaseObjectResolver::resolveAttachedObject($this->baseObject, $this->attachedObject);
	}

	/**
	 * The revision the link addresses, which is one of the addressed file or none: a versionId naming
	 * a revision of another object is answered for by neither the access check nor the handler, so the
	 * link opens the file it names.
	 */
	public function resolveVersion(): ?Version
	{
		return BaseObjectResolver::resolveVersion($this->baseObject, $this->version);
	}

	public function render(?UnifiedLinkAccessLevel $accessLevel = null): RenderResult
	{
		$baseAccessLevel = $this->baseAccessLevelForRender($accessLevel);
		$shouldRenderExternal = $this->shouldRenderExternal();

		if ($baseAccessLevel === UnifiedLinkAccessLevel::Denied && !$shouldRenderExternal)
		{
			return new RenderResult(self::renderAccessDeniedPageForBaseObject($this->resolveBaseObject()), 403);
		}
		$handler = $this->handlerFactory->createHandler(
			baseObject: $this->baseObject,
			attachedObject: $this->resolveAttachedObject(),
			version: $this->resolveVersion(),
			analytics: $this->analytics,
			currentUser: $this->currentUser ?? ($this->publicDomainAccessContext !== null ? CurrentUser::get() : null),
			deferred: $this->deferred,
			externalLink: $this->resolveExternalLinkForHandler(),
			// The answer this render was already given, so a handler drawing a page off the rights of the
			// opening is not made to walk the chain behind them a second time.
			accessLevel: $this->getAccessLevel(),
		);
		if ($handler instanceof OpeningNoticeCodeAware)
		{
			$handler->setOpeningNoticeCode(null);
		}

		$renderAccessLevel = $this->accessLevelForRender($baseAccessLevel, $handler);

		// The choice may answer lower than the level the gate above saw, so a refusal is answered here too.
		if ($renderAccessLevel === UnifiedLinkAccessLevel::Denied && !$shouldRenderExternal)
		{
			return new RenderResult(self::renderAccessDeniedPageForBaseObject($this->resolveBaseObject()), 403);
		}

		$this->applyEditTransition($handler, $renderAccessLevel);

		$result = match ($renderAccessLevel)
		{
			UnifiedLinkAccessLevel::Edit => $handler->edit(),
			default => $handler->view(),
		};

		if (!$result->isSuccess())
		{
			$content = $this->renderServerErrorPage($result);

			return new RenderResult($content, 500);
		}

		return new RenderResult(
			content: $result->getValue(),
			status: 200,
			redirectUrl: $result->getRedirectUrl(),
			component: $result->getComponent(),
			headers: $result->getHeaders(),
		);
	}

	/**
	 * A link saved before the signed context carries no external link, so it is taken from the object itself:
	 * without it an anonymous visitor loses both the public page and the password gate.
	 */
	protected function resolveExternalLink(): ?ExternalLink
	{
		if ($this->externalLink instanceof ExternalLink)
		{
			return $this->externalLink;
		}

		if (!$this->isStoredExternalLinkResolved)
		{
			$this->isStoredExternalLinkResolved = true;
			$this->storedExternalLink = $this->externalLinkProvider->getForUnifiedLinkAccessCheck(
				$this->resolveBaseObject()->getId(),
			);
		}

		return $this->storedExternalLink;
	}

	/**
	 * The public page is for guests and external callers: a source mark names where the mode is taken from
	 * and does not move an employee off the internal route.
	 */
	protected function resolveExternalLinkForHandler(): ?ExternalLink
	{
		if ($this->shouldUseInternalPortalFlow() && !$this->shouldRenderExternal())
		{
			return null;
		}

		return $this->resolveExternalLink();
	}

	private function shouldUseInternalPortalFlow(): bool
	{
		if ($this->publicDomainAccessContext !== null)
		{
			return true;
		}

		$userId = (int)$this->currentUser?->getId();
		if ($userId <= 0)
		{
			return false;
		}

		$user = User::loadById($userId);
		if (!$user instanceof User)
		{
			return false;
		}

		return !$this->isPublicEditorUser($user);
	}

	private function isPublicEditorUser(?User $user): bool
	{
		return in_array($user?->getExternalAuthId(), ['call', DocumentEditorUser::EXTERNAL_AUTH_ID], true);
	}

	public function getPublicDomainAccessContext(): ?PublicDomainAccessContext
	{
		return $this->publicDomainAccessContext;
	}

	public static function renderAccessDeniedPage(array $params = []): string
	{
		return StandalonePageRenderer::render('bitrix:disk.error.page', 'standalone', $params);
	}

	public static function renderAccessDeniedPageForBaseObject(?BaseObject $baseObject): string
	{
		$params = [];

		if ($baseObject instanceof Folder)
		{
			$params['TITLE_MESSAGE_CODE'] = 'DISK_ERROR_PAGE_STANDALONE_FOLDER_DENIED_TITLE';
		}

		return self::renderAccessDeniedPage($params);
	}

	private function renderServerErrorPage(HandlerOperationResult $result): string
	{
		if ($result->isSuccess())
		{
			throw new LogicException('Cannot get server error response from success result');
		}

		return 'Server error occurred. Please try again later.';
	}

	protected function baseAccessLevelForRender(?UnifiedLinkAccessLevel $accessLevel): UnifiedLinkAccessLevel
	{
		$baseObject = $this->resolveBaseObject();
		$fileAccessLevel = $this->getAccessLevel();

		if ($baseObject instanceof File && (int)$baseObject->getTypeFile() === TypeFile::BOARD)
		{
			return $fileAccessLevel;
		}

		return $accessLevel ?? $fileAccessLevel;
	}

	/**
	 * The single point where the render mode is chosen. The handler the factory has already built answers
	 * whether this opening may be raised to editing, so the factory rules are never repeated here.
	 */
	protected function accessLevelForRender(
		UnifiedLinkAccessLevel $baseAccessLevel,
		HtmlRenderableHandler $handler,
	): UnifiedLinkAccessLevel
	{
		$renderAccessLevel = $baseAccessLevel;
		if (
			$this->modeSelection === ModeSelection::Auto
			&& $handler instanceof AutomaticEditModeCapable
			&& $handler->supportsAutomaticEditMode()
		)
		{
			$renderAccessLevel = $this->lowerToOpeningSource($this->getAccessLevel());
		}

		if (
			$renderAccessLevel !== UnifiedLinkAccessLevel::Edit
			|| !($handler instanceof VibeofficeHtmlRenderableHandler)
			|| !$this->isObjectLockEnabled()
		)
		{
			return $renderAccessLevel;
		}

		// The handler above is built for a file alone, and a lock is what only a file carries.
		$baseObject = $this->resolveBaseObject();
		if (!$baseObject instanceof File)
		{
			return $renderAccessLevel;
		}

		$objectLock = $baseObject->getLock();
		if (
			$objectLock === null
			|| !$objectLock->isExclusive()
			|| $objectLock->canUnlock((int)$this->currentUser?->getId())
		)
		{
			return $renderAccessLevel;
		}

		if ($handler instanceof OpeningNoticeCodeAware)
		{
			$handler->setOpeningNoticeCode(OpeningNoticeCodeAware::OBJECT_LOCK_EDIT_VIEW);
		}

		return UnifiedLinkAccessLevel::Read;
	}

	/**
	 * A handler able to take the move is told its address, or told there is none, from the mode of this render.
	 */
	protected function applyEditTransition(
		HtmlRenderableHandler $handler,
		UnifiedLinkAccessLevel $renderAccessLevel,
	): void
	{
		if (!$handler instanceof EditTransitionAware)
		{
			return;
		}

		$handler->setEditTransitionUrl(
			$handler instanceof AutomaticEditModeCapable && $handler->supportsAutomaticEditMode()
				? $this->editTransitionUrl($renderAccessLevel)
				: null,
		);
	}

	/**
	 * Offered by the right of the person and not by the access this opening started at, and a lock does not
	 * take it away: the reopened page answers a refusal itself.
	 */
	private function editTransitionUrl(UnifiedLinkAccessLevel $renderAccessLevel): ?string
	{
		if ($renderAccessLevel === UnifiedLinkAccessLevel::Edit || !$this->mayEditWhatIsOpen())
		{
			return null;
		}

		return Driver::getInstance()->getUrlManager()->getUnifiedEditLink(
			$this->resolveBaseObject(),
			[
				'attachedId' => $this->resolveAttachedObject()?->getId(),
				'versionId' => $this->resolveVersion()?->getId(),
			],
		);
	}

	/**
	 * A historical revision is read-only whatever the person may do with the live file, and the chain is
	 * walked again only for a marked opening: otherwise the access of the opening already is their own.
	 */
	private function mayEditWhatIsOpen(): bool
	{
		if ($this->currentUser === null || $this->opensHistoricalRevision())
		{
			return false;
		}

		if (!$this->externalLink instanceof ExternalLink)
		{
			return $this->getAccessLevel() === UnifiedLinkAccessLevel::Edit;
		}

		return $this->unifiedLinkAccessService->check(
			$this->resolveBaseObject(),
			$this->resolveAttachedObject(),
			(int)$this->currentUser->getId(),
			version: $this->resolveVersion(),
		) === UnifiedLinkAccessLevel::Edit;
	}

	/**
	 * The source link lowers the mode inside an access already granted and never takes the access away:
	 * an internal link set to no access, and a person not entitled to use one at all, are both answered
	 * Denied by the source, while their right to the document comes from elsewhere. So the choice keeps a
	 * floor of Read whenever the access of the opening is not a refusal, and a refusal is left to the gates.
	 */
	private function lowerToOpeningSource(UnifiedLinkAccessLevel $actualAccessLevel): UnifiedLinkAccessLevel
	{
		if ($actualAccessLevel === UnifiedLinkAccessLevel::Denied)
		{
			return $actualAccessLevel;
		}

		$sourceAccessLevel = $this->openingSourceAccessLevel();
		if ($sourceAccessLevel->value >= $actualAccessLevel->value)
		{
			return $actualAccessLevel;
		}

		return $sourceAccessLevel === UnifiedLinkAccessLevel::Denied
			? UnifiedLinkAccessLevel::Read
			: $sourceAccessLevel
		;
	}

	/**
	 * For a marked opening and for a guest the access already counted is the access of the source link.
	 */
	private function openingSourceAccessLevel(): UnifiedLinkAccessLevel
	{
		if (
			$this->externalLink instanceof ExternalLink
			|| $this->currentUser === null
			|| $this->isPublicEditorUser(User::loadById((int)$this->currentUser->getId()))
		)
		{
			return $this->getAccessLevel();
		}

		return $this->unifiedLinkAccessService->checkUnifiedLinkSource(
			$this->resolveBaseObject(),
			(int)$this->currentUser->getId(),
		);
	}

	protected function isObjectLockEnabled(): bool
	{
		return Configuration::isEnabledObjectLock();
	}
}
