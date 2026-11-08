<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\DocumentHeader;

use Bitrix\Disk\Configuration;
use Bitrix\Disk\document\SharingControlType;
use Bitrix\Disk\Document\BoardsHandler;
use Bitrix\Disk\Document\DocumentHandler;
use Bitrix\Disk\Document\DocumentHandlersManager;
use Bitrix\Disk\Document\LocalDocumentController;
use Bitrix\Disk\Document\Models;
use Bitrix\Disk\Document\OnlyOffice\OnlyOfficeHandler;
use Bitrix\Disk\Document\Vibeoffice\VibeofficeHandler;
use Bitrix\Disk\Driver;
use Bitrix\Disk\File;
use Bitrix\Disk\Integration\Baas\BaasSessionBoostService;
use Bitrix\Disk\Integration\Bitrix24Manager;
use Bitrix\Disk\Internal\Enum\DocumentHeaderElement;
use Bitrix\Disk\Internal\Service\HtmlViewerPolicy;
use Bitrix\Disk\UrlManager;
use Bitrix\Disk\User;
use Bitrix\Intranet\Util;
use Bitrix\Main\Application;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;

/**
 * Single place deciding which elements the header of a concrete opening consists of (TBL-01, ALG-01).
 *
 * Everything about the opening itself - what is open, how it was authorized and what is allowed to the
 * current user - is asked of {@see OpeningContext}; the service adds only what belongs to the portal
 * and to the header: the type of the user, the tariff of sharing and the list of editors.
 *
 * Neither cached nor caching: the composition depends on the rights of the current user and on the
 * mode the object was opened in.
 */
class HeaderCompositionService
{
	public const ERROR_OBJECT_NOT_FOUND = 'OBJECT_NOT_FOUND';
	public const ERROR_ACCESS_DENIED = 'ACCESS_DENIED';

	/**
	 * The boost service is asked for and not handed over: its constructor resolves the services of BaaS and
	 * the tariff group, while a board and the presence action never ask about the Boost element at all.
	 */
	public function __construct(
		private readonly UrlManager $urlManager,
		private ?BaasSessionBoostService $sessionBoostService = null,
	)
	{
	}

	/**
	 * @param OpeningContext|null $openingContext null when the opening names no object at all
	 * @return Result data key `composition` holds {@see HeaderCompositionDto}
	 */
	public function compose(?OpeningContext $openingContext, int $userId): Result
	{
		return $this->refuseInsteadOfThrowing(
			fn(): Result => $this->describeOpening($openingContext, $userId),
		);
	}

	private function describeOpening(?OpeningContext $openingContext, int $userId): Result
	{
		$result = new Result();

		if (!$openingContext)
		{
			return $result->addError($this->getObjectNotFoundError());
		}

		if (!$openingContext->canRead())
		{
			return $result->addError($this->getAccessDeniedError());
		}

		$isBoard = $openingContext->isBoard();
		$isIntranetUser = $this->isIntranetUser($userId);
		$isPortalEmployee = $this->isPortalEmployee($userId, $isIntranetUser);
		$sharingControlType = $isPortalEmployee
			? $this->resolveSharingControlType($openingContext, $isBoard)
			: null
		;
		// A symbolic link resolves its real object to answer this, so it is asked at most once for the
		// whole description - and not at all by an opening which neither copies the link of the object
		// nor names it: a public page pays for no read of the portal.
		$uniqueCode = null;
		$resolveUniqueCode = function () use (&$uniqueCode, $openingContext): string {
			return $uniqueCode ??= $this->getUniqueCode($openingContext->file());
		};

		$elements = $this->listElements(
			openingContext: $openingContext,
			isBoard: $isBoard,
			isIntranetUser: $isIntranetUser,
			isPortalEmployee: $isPortalEmployee,
			isExtensionEditable: fn(): bool => $this->isExtensionEditableByTheEngineOfTheOpening($openingContext),
			hasSharingControl: $sharingControlType !== null,
			supportsUnifiedLink: static fn(): bool => $resolveUniqueCode() !== '',
		);

		return $result->setData([
			'composition' => $this->buildDto($elements, $openingContext, $sharingControlType, $resolveUniqueCode),
		]);
	}

	/**
	 * The two questions the presence action has: may this user read the opening, and does it carry a
	 * roster. The answers and the refusals are those of {@see compose()}, but nothing else is built on
	 * the way; the context is built as narrowly {@see OpeningContextFactory::forPresence()}.
	 *
	 * @return Result data key `presenceEnabled`
	 */
	public function hasPresence(?OpeningContext $openingContext): Result
	{
		return $this->refuseInsteadOfThrowing(
			fn(): Result => $this->answerPresence($openingContext),
		);
	}

	private function answerPresence(?OpeningContext $openingContext): Result
	{
		$result = new Result();

		if (!$openingContext)
		{
			return $result->addError($this->getObjectNotFoundError());
		}

		if (!$openingContext->canRead())
		{
			return $result->addError($this->getAccessDeniedError());
		}

		return $result->setData([
			'presenceEnabled' => $this->isPresenceEnabled($openingContext),
		]);
	}

	/**
	 * The same description for the page which opens the object, so the header draws itself from what the
	 * page arrived with. A refusal is not raised to the page: the page keeps opening the object and falls
	 * back to the minimal header {@see composeMinimalForPage()}, and it is the page which leaves the
	 * trace of the refusal {@see \Bitrix\Disk\Internal\Service\HtmlViewerPageService}.
	 */
	public function composeForPage(?OpeningContext $openingContext, int $userId): ?array
	{
		try
		{
			$result = $this->compose($openingContext, $userId);
		}
		catch (\Throwable $e)
		{
			$this->logUnforeseenFailure($e);

			return null;
		}

		return $result->isSuccess() ? $result->getData()['composition']->toArray() : null;
	}

	/**
	 * The header of a page whose opening could not be described: the reader is already being served the
	 * content, so the page keeps the part of the header that stands on what it holds itself - the name it
	 * serves under and the address it was served with.
	 *
	 * Nothing here is decided by rights or by a session of the document, which is exactly what the refused
	 * description would have decided: an element asking either of them would have to be guessed, and a
	 * guess hands the reader an action it may not hold. What is left cannot hand out more than the page
	 * already did - the logo leads to the portal root, the name is the heading the page prints anyway, and
	 * the downloading is the same signed address whose endpoint decides the rights on its own.
	 *
	 * @return array|null null when the page holds nothing to put in a header at all
	 */
	public function composeMinimalForPage(string $name, string $downloadUrl): ?array
	{
		$hasDownload = $downloadUrl !== '';
		if ($name === '' && !$hasDownload)
		{
			return null;
		}

		$extension = (string)getFileExtension($name);

		// In the render order of DocumentHeaderElement, as the full matrix keeps it {@see listElements()}.
		$elements = [DocumentHeaderElement::Logo, DocumentHeaderElement::Name];
		if (HtmlViewerPolicy::isViewableExtension($extension))
		{
			$elements[] = DocumentHeaderElement::ContentWarning;
		}
		if ($hasDownload)
		{
			$elements[] = DocumentHeaderElement::Download;
		}

		return (new HeaderCompositionDto(
			elements: array_column($elements, 'value'),
			objectType: HeaderCompositionDto::OBJECT_TYPE_DOCUMENT,
			mode: HeaderCompositionDto::MODE_VIEW,
			objectId: null,
			objectName: $name,
			objectUniqueCode: null,
			objectExtension: $extension,
			unifiedLinkUrl: '',
			downloadUrl: $downloadUrl,
			accessControlType: '',
			editors: [],
			presenceEnabled: false,
			boostContainerRequired: false,
		))->toArray();
	}

	/**
	 * Composition matrix TBL-01. The order of {@see DocumentHeaderElement::cases()} is the render order.
	 *
	 * The ceiling of the editing engine is handed over unanswered: a board, a version and an opening
	 * already made for editing decide that row without it, while asking it costs the handler of the
	 * engine the opening was created by.
	 *
	 * @param \Closure(): bool $isExtensionEditable
	 * @param \Closure(): bool $supportsUnifiedLink
	 * @return DocumentHeaderElement[]
	 */
	private function listElements(
		OpeningContext $openingContext,
		bool $isBoard,
		bool $isIntranetUser,
		bool $isPortalEmployee,
		\Closure $isExtensionEditable,
		bool $hasSharingControl,
		\Closure $supportsUnifiedLink,
	): array
	{
		$isUnifiedAccess = $openingContext->accessType() === OpeningContext::ACCESS_TYPE_UNIFIED;
		$isExternalAccess = $openingContext->accessType() === OpeningContext::ACCESS_TYPE_EXTERNAL;
		$isVersion = $openingContext->versionId() !== null;
		$hasEditingEngine = $openingContext->editorService() !== null;
		$servesContentItself = $openingContext->servesContentItself();

		$elements = [];
		foreach (DocumentHeaderElement::cases() as $element)
		{
			$isIncluded = match ($element)
			{
				DocumentHeaderElement::Logo,
				DocumentHeaderElement::Name => true,

				// Asked about the content served and not about the object: a link pinned to an html
				// revision keeps serving it after the object was renamed to another type.
				DocumentHeaderElement::ContentWarning => $servesContentItself
					&& HtmlViewerPolicy::isViewableExtension($openingContext->contentExtension()),

				// The rename asks for the right on the object itself (API-03), which a guest of a link
				// never has; a version is a frozen state and carries no name to change
				// (@see RenameService::rename()).
				DocumentHeaderElement::Rename => !$isUnifiedAccess
					&& !$isVersion
					&& $openingContext->canRename(),

				// Only the page which draws the content itself is written by nobody and is told nothing;
				// a board carries the element as a permanent label of its mode.
				DocumentHeaderElement::SaveStatus => !$servesContentItself,

				// The boost sold is the one of an editing session (BaaS `disk_oo_edit`). The type of the
				// user is asked first: the availability of the purchase resolves BaaS and the tariff
				// group.
				DocumentHeaderElement::Boost => !$isBoard
					&& $hasEditingEngine
					&& $isIntranetUser
					&& $this->getSessionBoostService()->isAvailable(),

				// A frozen state carries no editing, and an extension the engine cannot edit is refused
				// by the shell itself (@see isExtensionEditableByTheEngineOfTheOpening()).
				DocumentHeaderElement::Edit => !$isBoard
					&& !$isVersion
					&& $openingContext->isViewMode()
					&& $isExtensionEditable()
					&& $openingContext->canEdit(),

				// An opening by a public link keeps the roster too: it is the live channel of that roster
				// such an opening never gets, not the roster itself.
				DocumentHeaderElement::Presence => $this->isPresenceEnabled($openingContext),

				// The board is asked the same question as the document: the page of the unique link is
				// not told apart from an ordinary one by the session, both reuse one internal row.
				DocumentHeaderElement::Access => $hasSharingControl,

				// A page entered by a public link copies that link and nothing else - the only address
				// its reader can open (D-1).
				DocumentHeaderElement::CopyLink => $isExternalAccess
					|| ($isPortalEmployee && $openingContext->offersObjectSharing() && $supportsUnifiedLink()),

				DocumentHeaderElement::Download => $openingContext->downloadUrl() !== '',

				// Only the surface drawing the document itself adapts to the mode: an editor and a board
				// hand the page over to their own canvas (D-2).
				DocumentHeaderElement::Presentation => $servesContentItself,
			};

			if ($isIncluded)
			{
				$elements[] = $element;
			}
		}

		return $elements;
	}

	/**
	 * @param DocumentHeaderElement[] $elements
	 */
	private function buildDto(
		array $elements,
		OpeningContext $openingContext,
		?SharingControlType $sharingControlType,
		\Closure $resolveUniqueCode,
	): HeaderCompositionDto
	{
		$hasElement = static fn(DocumentHeaderElement $element): bool => in_array($element, $elements, true);
		// Only an opening that offers the object itself is told which object that is: the identifier and
		// the unique code are read by the access popup and by the transition to editing, and an opening
		// offering neither - a revision, an attachment, a page entered by a public link - has no place to
		// use them at. The unique code addresses the unified link of the object, so a reader authorized by
		// an attachment would otherwise be handed the way to an object it was never given rights on.
		// Both name the real object: rights of a sharing are written on it and not on the link pointing
		// at it {@see \Bitrix\Disk\Sharing::addToManyEntities()}. A pair naming the link by one and its
		// target by the other shows the popup the settings of one object and saves them onto another.
		$file = $openingContext->file();
		$isPublicOpening = $openingContext->accessType() === OpeningContext::ACCESS_TYPE_EXTERNAL;
		$namesObjectInPortal = !$isPublicOpening && $file !== null && $openingContext->offersObjectSharing();

		return new HeaderCompositionDto(
			elements: array_column($elements, 'value'),
			objectType: $openingContext->isBoard()
				? HeaderCompositionDto::OBJECT_TYPE_BOARD
				: HeaderCompositionDto::OBJECT_TYPE_DOCUMENT,
			mode: $openingContext->isViewMode()
				? HeaderCompositionDto::MODE_VIEW
				: HeaderCompositionDto::MODE_EDIT,
			objectId: $namesObjectInPortal ? (int)$file->getRealObjectId() : null,
			objectName: $openingContext->name(),
			objectUniqueCode: $namesObjectInPortal ? $resolveUniqueCode() : null,
			objectExtension: $openingContext->contentExtension(),
			unifiedLinkUrl: $hasElement(DocumentHeaderElement::CopyLink)
				? $this->getCopyLinkUrl($openingContext)
				: '',
			downloadUrl: $hasElement(DocumentHeaderElement::Download)
				? $openingContext->downloadUrl()
				: '',
			accessControlType: $hasElement(DocumentHeaderElement::Access)
				? (string)$sharingControlType?->value
				: '',
			// The menu of the editors is drawn by the transition to editing and by nothing else, so an
			// opening which keeps no transition is asked for no list either. An opening by link keeps the
			// bare edit button of the previous shells: the choice of another editor asks for the rights on
			// the object, which a guest of a link never has.
			editors: (
				$hasElement(DocumentHeaderElement::Edit)
				&& $openingContext->accessType() !== OpeningContext::ACCESS_TYPE_UNIFIED
			)
				? $this->listEditors()
				: [],
			presenceEnabled: $hasElement(DocumentHeaderElement::Presence),
			boostContainerRequired: $hasElement(DocumentHeaderElement::Boost),
		);
	}

	/**
	 * One instance per request, shared with every other consumer of the locator.
	 */
	private function getSessionBoostService(): BaasSessionBoostService
	{
		return $this->sessionBoostService ??= ServiceLocator::getInstance()->get(BaasSessionBoostService::class);
	}

	/**
	 * One decision, so the matrix and the narrow answer of {@see hasPresence()} cannot drift apart. The
	 * roster is kept by the session of a document, which a board and the viewer hold none of.
	 */
	private function isPresenceEnabled(OpeningContext $openingContext): bool
	{
		return !$openingContext->isBoard() && !$openingContext->servesContentItself();
	}

	/**
	 * An unforeseen failure is answered with a refusal of the contract: an action would otherwise hand
	 * the browser an unhandled exception instead of a localized one. The refusal is the answer to the
	 * user and not the whole reaction, so the failure is logged as well.
	 *
	 * @param callable(): Result $describe
	 */
	private function refuseInsteadOfThrowing(callable $describe): Result
	{
		try
		{
			return $describe();
		}
		catch (\Throwable $e)
		{
			$this->logUnforeseenFailure($e);

			return (new Result())->addError($this->getAccessDeniedError());
		}
	}

	protected function logUnforeseenFailure(\Throwable $e): void
	{
		Application::getInstance()->getExceptionHandler()->writeToLog($e);
	}

	private function getObjectNotFoundError(): Error
	{
		return new Error('Document session has no object.', self::ERROR_OBJECT_NOT_FOUND);
	}

	private function getAccessDeniedError(): Error
	{
		return new Error('Read access to the object is denied.', self::ERROR_ACCESS_DENIED);
	}

	/**
	 * Both shells ask exactly this before they hand out an address and answer with an empty one
	 * otherwise (@see \CDiskFileEditorOnlyOfficeComponent::isEditAllowed()), so a header deciding it
	 * apart would draw a live button leading nowhere. Every engine is asked its own handler: the two
	 * office lists coincide today but are declared apart.
	 */
	private function isExtensionEditableByTheEngineOfTheOpening(OpeningContext $openingContext): bool
	{
		$extension = (string)$openingContext->file()?->getExtension();

		return match ($openingContext->editorService())
		{
			Models\DocumentService::OnlyOffice => OnlyOfficeHandler::isEditable($extension),
			Models\DocumentService::Vibeoffice => VibeofficeHandler::isEditable($extension),
			Models\DocumentService::FlipChart => BoardsHandler::isEditable($extension),
			// An opening served by no editing engine names no editor to ask.
			null => false,
		};
	}

	private function resolveSharingControlType(
		OpeningContext $openingContext,
		bool $isBoard,
	): ?SharingControlType
	{
		// An opening which does not offer the object itself has no access of its own to control, and an
		// opening entered by a link is bounded by that link and not by the rights on the object.
		if (
			!$openingContext->offersObjectSharing()
			|| $openingContext->accessType() === OpeningContext::ACCESS_TYPE_UNIFIED
		)
		{
			return null;
		}

		if (!$this->isSharingFeatureEnabled($isBoard, $openingContext->editorService() !== null))
		{
			return SharingControlType::BlockedByFeature;
		}

		if ($openingContext->canChangeRights())
		{
			return SharingControlType::WithChangeRights;
		}

		if ($openingContext->canShare())
		{
			return SharingControlType::WithSharing;
		}

		return SharingControlType::WithoutEdit;
	}

	/**
	 * A board is given out by the link of the object alone, so the tariff which closes its access
	 * button is the one of board links and not the one of file sharing.
	 *
	 * The tariff of the editor answers only for an opening an editing engine serves. An opening served
	 * by none is gated by file sharing alone, which is the tariff its previous page was gated by
	 * (@see \Bitrix\Disk\Internal\Service\HtmlViewerPageService).
	 */
	protected function isSharingFeatureEnabled(bool $isBoard, bool $hasEditingEngine): bool
	{
		if ($isBoard)
		{
			return $this->isFeatureEnabled('disk_board_external_link');
		}

		return $this->isFeatureEnabled('disk_file_sharing')
			|| ($hasEditingEngine && $this->isFeatureEnabled('disk_onlyoffice_edit'))
		;
	}

	protected function isFeatureEnabled(string $feature): bool
	{
		return Bitrix24Manager::isFeatureEnabled($feature);
	}

	protected function isIntranetUser(int $userId): bool
	{
		return $userId > 0 && Loader::includeModule('intranet') && Util::isIntranetUser($userId);
	}

	/**
	 * The intranet answer is handed over rather than asked again: the same description already asks it for
	 * the Boost row of the matrix, and {@see Util::isIntranetUser()} promises no cache of its own.
	 */
	protected function isPortalEmployee(int $userId, bool $isIntranetUser): bool
	{
		return $isIntranetUser || $this->isCollaber($userId);
	}

	protected function isCollaber(int $userId): bool
	{
		return $userId > 0 && (bool)User::loadById($userId)?->isCollaber();
	}

	private function getUniqueCode(?File $file): string
	{
		return (string)$file?->getRealObject()?->getUniqueCode();
	}

	/**
	 * The form of the address is the one of the opening (D-1): the public link for a page entered by
	 * one, the unified link of the object everywhere else.
	 */
	private function getCopyLinkUrl(OpeningContext $openingContext): string
	{
		// Neither form of the address exists without the object it names, and the element is composed
		// for no such opening in the first place {@see listElements()}.
		$file = $openingContext->file();
		if ($file === null)
		{
			return '';
		}

		if ($openingContext->accessType() === OpeningContext::ACCESS_TYPE_EXTERNAL)
		{
			// A public page holds no address inside the portal, an unforeseen opening without a link
			// included: falling back to the unified link would hand one out to whoever holds the link.
			$externalLink = $openingContext->externalLink();

			return $externalLink === null
				? ''
				: $this->urlManager->getPublicExternalLink($file, (string)$externalLink->getHash())
			;
		}

		return $this->getUnifiedLinkUrl($openingContext, $file);
	}

	private function getUnifiedLinkUrl(OpeningContext $openingContext, File $file): string
	{
		$options = ['absolute' => true];

		$attachedObjectId = (int)$openingContext->attachedObject()?->getId();
		if ($attachedObjectId > 0)
		{
			$options['attachedId'] = $attachedObjectId;
		}

		return $this->urlManager->getUnifiedLink($file, $options);
	}

	/**
	 * @return array<int, array{code: string, name: string, isDefault: bool}>
	 */
	private function listEditors(): array
	{
		$handlersManager = Driver::getInstance()->getDocumentHandlersManager();
		$defaultCode = (string)$handlersManager->getDefaultServiceForCurrentUser()?->getCode();

		$editors = [];
		foreach ($this->listCloudHandlers($handlersManager) as $handler)
		{
			$editors[] = [
				'code' => $handler::getCode(),
				'name' => $handler::getName(),
				'isDefault' => $handler::getCode() === $defaultCode,
			];
		}

		$editors[] = [
			'code' => LocalDocumentController::getCode(),
			'name' => LocalDocumentController::getName(),
			'isDefault' => LocalDocumentController::getCode() === $defaultCode,
		];

		return $editors;
	}

	/**
	 * @return DocumentHandler[]
	 */
	private function listCloudHandlers(DocumentHandlersManager $handlersManager): array
	{
		if (!Configuration::canCreateFileByCloud())
		{
			return [];
		}

		return array_values($handlersManager->getHandlersForCreatingFile());
	}
}
