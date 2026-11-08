<?php
declare(strict_types=1);

namespace Bitrix\Disk\Internal\Service\DocumentHeader;

use Bitrix\Disk\AttachedObject;
use Bitrix\Disk\Document\Models;
use Bitrix\Disk\ExternalLink;
use Bitrix\Disk\File;
use Bitrix\Disk\Internal\Access\UnifiedLink\UnifiedLinkAccessLevel;
use Bitrix\Disk\Internal\Service\UnifiedLink\UnifiedLinkAccessService;
use Bitrix\Disk\Security\SecurityContext;
use Bitrix\Disk\Version;
use Bitrix\Main\Application;
use Bitrix\Main\DI\ServiceLocator;

/**
 * Builds the context of an opening: from the session of a document for the shells of the editors, and
 * from one of the four entries of the html viewer otherwise. The only place of the header which still
 * knows about a document session.
 *
 * No rule of rights is restated here - each is delegated to the session or to the access check of the
 * unified link, which is what authorized the page in the first place.
 */
class OpeningContextFactory
{
	/** Asked for and not handed over: an opening authorized by object rights never reaches it. */
	public function __construct(
		private ?UnifiedLinkAccessService $unifiedLinkAccessService = null,
	)
	{
	}

	/**
	 * @param string $downloadUrl address already authorized by the page and handed to the shared header
	 * @param bool $hasUnifiedLinkProof whether the visitor proved to the caller that they hold the unified
	 *     link of the object {@see resolveRights()}; the description obtains no proof of its own
	 * @return OpeningContext|null null when the session names no object - there is no opening to describe
	 */
	public function fromDocumentSession(
		Models\DocumentSession $documentSession,
		int $userId,
		bool $hasUnifiedLinkProof = false,
		string $downloadUrl = '',
	): ?OpeningContext
	{
		$file = $documentSession->getObject();
		if (!$file)
		{
			return null;
		}

		return $this->describeOrRefuse(
			$file,
			fn(): OpeningContext => $this->describeOpening(
				$documentSession,
				$file,
				$userId,
				$downloadUrl,
				$hasUnifiedLinkProof,
			),
		);
	}

	/**
	 * The read-only opening of a file by the html viewer.
	 *
	 * The download address is handed over and not built here: the page builds it, signature and all
	 * {@see \Bitrix\Disk\Internal\Service\HtmlViewerPageService}, and the header must hand out the very
	 * address the page was served with. The rights are handed over the same way where the caller already
	 * decided them: the page was served by that very answer, and the chain behind it costs the external
	 * link, the user, the rights and the attachment to walk again {@see UnifiedLinkAccessService::check()}.
	 */
	public function fromFile(
		File $file,
		int $userId,
		string $downloadUrl,
		?UnifiedLinkAccessLevel $accessLevel = null,
	): OpeningContext
	{
		return $this->describeOrRefuse($file, fn(): OpeningContext => $this->describeViewing(
			file: $file,
			userId: $userId,
			downloadUrl: $downloadUrl,
			accessLevel: $accessLevel ?? $this->getUnifiedLinkAccessService()->check($file, userId: $userId),
			offersObjectSharing: true,
		));
	}

	/**
	 * The opening of a concrete revision: it carries the name it was saved under and offers nothing of
	 * the object - a revision has no access of its own. A revision whose object is gone is an opening
	 * all the same {@see describeOrphanedRevision()}.
	 */
	public function fromVersion(
		Version $version,
		int $userId,
		string $downloadUrl,
		?UnifiedLinkAccessLevel $accessLevel = null,
	): OpeningContext
	{
		$file = $version->getObject();
		if (!$file instanceof File)
		{
			return $this->describeOrphanedRevision($version, $downloadUrl);
		}

		return $this->describeOrRefuse($file, fn(): OpeningContext => $this->describeViewing(
			file: $file,
			userId: $userId,
			downloadUrl: $downloadUrl,
			accessLevel: $accessLevel
				?? $this->getUnifiedLinkAccessService()->check($file, userId: $userId, version: $version),
			offersObjectSharing: false,
			name: (string)$version->getName(),
			versionId: (int)$version->getId(),
		));
	}

	/**
	 * The opening of an attachment, which is what authorizes it: a reader of a task, a chat or a deal
	 * opens the document with no rights in the storage it lies in, and access is configured where it is
	 * attached, so the page offers nothing of the object.
	 *
	 * @return OpeningContext|null null when the attachment names no file
	 */
	public function fromAttachedObject(
		AttachedObject $attachedObject,
		int $userId,
		string $downloadUrl,
		?UnifiedLinkAccessLevel $accessLevel = null,
	): ?OpeningContext
	{
		$file = $attachedObject->getFile();
		if (!$file instanceof File)
		{
			return null;
		}

		return $this->describeOrRefuse($file, fn(): OpeningContext => $this->describeViewing(
			file: $file,
			userId: $userId,
			downloadUrl: $downloadUrl,
			accessLevel: $accessLevel
				?? $this->getUnifiedLinkAccessService()->check($file, $attachedObject, $userId),
			offersObjectSharing: false,
			name: (string)$attachedObject->getName(),
			versionId: (int)$attachedObject->getVersionId() ?: null,
			attachedObject: $attachedObject,
		));
	}

	/**
	 * The public page of a file, served to whoever holds the link - an anonymous visitor included. The
	 * link is the whole right of such a visitor, and everything needing a portal stays refused.
	 */
	public function fromExternalLink(
		File $file,
		ExternalLink $externalLink,
		?Version $pinnedVersion,
		int $userId,
		string $downloadUrl,
	): OpeningContext
	{
		return $this->describeOrRefuse($file, fn(): OpeningContext => $this->describeViewing(
			file: $file,
			userId: $userId,
			downloadUrl: $downloadUrl,
			accessLevel: $this->getUnifiedLinkAccessService()->check(
				$file,
				userId: $userId,
				externalLink: $externalLink,
				version: $pinnedVersion,
			),
			offersObjectSharing: false,
			accessType: OpeningContext::ACCESS_TYPE_EXTERNAL,
			name: (string)($pinnedVersion?->getName() ?? $file->getName()),
			versionId: $pinnedVersion === null ? null : (int)$pinnedVersion->getId(),
			externalLink: $externalLink,
		));
	}

	/**
	 * The layers below carry no guard of their own, so an opening whose rights could not be decided is
	 * described as one nobody may read: the header answers with its own refusal instead of handing the
	 * browser an unhandled exception, and the log keeps the trace.
	 *
	 * @param \Closure(): OpeningContext $describe
	 */
	private function describeOrRefuse(File $file, \Closure $describe): OpeningContext
	{
		try
		{
			return $describe();
		}
		catch (\Throwable $e)
		{
			$this->logUnforeseenFailure($e);

			return $this->refuseOpening($file);
		}
	}

	/**
	 * An opening of the html viewer, whichever of the four entries it came from: read-only, served by no
	 * engine, never a board and drawing the content itself.
	 *
	 * The transition to editing and the rename are refused: both are given a session of a document
	 * {@see RenameService::rename()}, and this surface holds none.
	 */
	private function describeViewing(
		File $file,
		int $userId,
		string $downloadUrl,
		UnifiedLinkAccessLevel $accessLevel,
		bool $offersObjectSharing,
		string $accessType = OpeningContext::ACCESS_TYPE_INTERNAL,
		?string $name = null,
		?int $versionId = null,
		?AttachedObject $attachedObject = null,
		?ExternalLink $externalLink = null,
	): OpeningContext
	{
		// The storage is asked for the rights of the object only where the surface offers them at all.
		$securityContext = $offersObjectSharing ? $this->getSecurityContext($file, $userId) : null;
		// An object whose rights could not be resolved offers nothing of itself either: there is no
		// access of its own to control and nothing for the popup of it to be filled from.
		$offersObject = $offersObjectSharing && $securityContext !== null;

		return new OpeningContext(
			file: $file,
			accessType: $accessType,
			isViewMode: true,
			isBoard: false,
			canRead: $accessLevel->canRead(),
			canEdit: false,
			canRename: false,
			canShare: $securityContext !== null && $file->canShare($securityContext),
			canChangeRights: $securityContext !== null && $file->canChangeRights($securityContext),
			offersObjectSharing: $offersObject,
			servesContentItself: true,
			versionId: $versionId,
			attachedObject: $attachedObject,
			externalLink: $externalLink,
			name: $name,
			downloadUrl: $downloadUrl,
		);
	}

	/**
	 * A revision whose object is gone: described from the revision itself, since everything else is a
	 * question about an object that is no longer there. Its unsigned endpoints refuse on their own
	 * {@see \Bitrix\Disk\Internal\Service\HtmlViewerPageService::signVersion()}, and the reading is the
	 * one the page was already authorized by.
	 */
	private function describeOrphanedRevision(Version $version, string $downloadUrl): OpeningContext
	{
		return new OpeningContext(
			file: null,
			accessType: OpeningContext::ACCESS_TYPE_INTERNAL,
			isViewMode: true,
			isBoard: false,
			canRead: true,
			canEdit: false,
			canRename: false,
			canShare: false,
			canChangeRights: false,
			offersObjectSharing: false,
			servesContentItself: true,
			versionId: (int)$version->getId(),
			name: (string)$version->getName(),
			downloadUrl: $downloadUrl,
		);
	}

	/**
	 * A session opened through an external link is authorized by that link and not by object rights. A
	 * row whose CONTEXT cannot be read is asked for the link in the column of its own.
	 */
	public function resolveAccessType(Models\DocumentSession $documentSession): string
	{
		return $this->accessTypeOf($documentSession, $this->getSessionContext($documentSession));
	}

	/**
	 * "The context names no link" and "the context could not be read" are two different rows: the second
	 * one is answered by the denormalized column of the session
	 * {@see Models\DocumentSession::getExternalLinkId()}, so an opening entered by a link keeps its type -
	 * and its ceiling - even when nothing else of the CONTEXT can be read.
	 */
	private function accessTypeOf(
		Models\DocumentSession $documentSession,
		?Models\DocumentSessionContext $sessionContext,
	): string
	{
		$externalLinkId = $sessionContext === null
			? ((int)$documentSession->getExternalLinkId() ?: null)
			: $sessionContext->getExternalLinkId()
		;

		return $externalLinkId === null
			? OpeningContext::ACCESS_TYPE_INTERNAL
			: OpeningContext::ACCESS_TYPE_UNIFIED
		;
	}

	/**
	 * The context of the session is dereferenced once and handed down to every question of rights: the
	 * model keeps none of its own {@see Models\DocumentSession::getContext()}, so asking it again decodes
	 * the CONTEXT anew and re-reads the rows of the attachment and of the link.
	 */
	private function describeOpening(
		Models\DocumentSession $documentSession,
		File $file,
		int $userId,
		string $downloadUrl,
		bool $hasUnifiedLinkProof,
	): OpeningContext
	{
		$sessionContext = $this->getSessionContext($documentSession);
		$accessType = $this->accessTypeOf($documentSession, $sessionContext);
		$attachedObject = $this->getAttachedObject($sessionContext);
		$externalLink = $this->getExternalLink($sessionContext);
		$securityContext = $this->getSecurityContext($file, $userId);
		$rights = $this->resolveRights(
			$file,
			$userId,
			$accessType,
			$securityContext,
			$attachedObject,
			$hasUnifiedLinkProof,
		);
		$editorService = $documentSession->getService();

		return new OpeningContext(
			file: $file,
			accessType: $accessType,
			isViewMode: $documentSession->isView(),
			isBoard: $editorService === Models\DocumentService::FlipChart,
			canRead: $rights->canRead(),
			canEdit: fn(): bool => $this->canOpenForEditing(
				$userId,
				$securityContext,
				$rights,
				$accessType,
				$attachedObject,
				$externalLink,
			),
			canRename: $securityContext !== null && $documentSession->canRename($securityContext),
			canShare: $securityContext !== null && $documentSession->canShare($securityContext),
			canChangeRights: $securityContext !== null && $documentSession->canChangeRights($securityContext),
			versionId: $documentSession->isVersion() ? (int)$documentSession->getVersionId() : null,
			editorService: $editorService,
			attachedObject: $attachedObject,
			externalLink: $externalLink,
			downloadUrl: $downloadUrl,
		);
	}

	/**
	 * The narrow build of the presence action, which asks two questions and no others: may this user
	 * read the opening, and does it carry a roster {@see HeaderCompositionService::hasPresence()}. The
	 * link is left undereferenced and every other right undecided.
	 *
	 * @param bool $hasUnifiedLinkProof the proof the whole description is given {@see fromDocumentSession()}:
	 *     the narrow description answers about reading, so it is decided by the same sources
	 * @return OpeningContext|null null when the session names no object
	 */
	public function forPresence(
		Models\DocumentSession $documentSession,
		int $userId,
		bool $hasUnifiedLinkProof = false,
	): ?OpeningContext
	{
		$file = $documentSession->getObject();
		if (!$file)
		{
			return null;
		}

		return $this->describeOrRefuse(
			$file,
			fn(): OpeningContext => $this->describePresence(
				$documentSession,
				$file,
				$userId,
				$hasUnifiedLinkProof,
			),
		);
	}

	private function describePresence(
		Models\DocumentSession $documentSession,
		File $file,
		int $userId,
		bool $hasUnifiedLinkProof,
	): OpeningContext
	{
		$sessionContext = $this->getSessionContext($documentSession);
		$accessType = $this->accessTypeOf($documentSession, $sessionContext);
		$editorService = $documentSession->getService();
		$rights = $this->resolveRights(
			$file,
			$userId,
			$accessType,
			$this->getSecurityContext($file, $userId),
			$this->getAttachedObject($sessionContext),
			$hasUnifiedLinkProof,
		);

		return new OpeningContext(
			file: $file,
			accessType: $accessType,
			isViewMode: $documentSession->isView(),
			isBoard: $editorService === Models\DocumentService::FlipChart,
			canRead: $rights->canRead(),
			canEdit: false,
			canRename: false,
			canShare: false,
			canChangeRights: false,
			offersObjectSharing: false,
			editorService: $editorService,
		);
	}

	private function refuseOpening(File $file): OpeningContext
	{
		return new OpeningContext(
			file: $file,
			accessType: OpeningContext::ACCESS_TYPE_INTERNAL,
			isViewMode: true,
			isBoard: false,
			canRead: false,
			canEdit: false,
			canRename: false,
			canShare: false,
			canChangeRights: false,
		);
	}

	/**
	 * An opening entered by a unified link is decided by the chain of the access check and by nothing
	 * else {@see UnifiedLinkAccessService::check()}: the chain is what let the page in, and the storage
	 * of the object grants such a visitor nothing.
	 *
	 * On the internal path a direct right to edit is asked first. A visitor who proved they hold the
	 * unified link is then answered by the whole chain, because the link may raise reading through an
	 * attachment to editing. An ordinary opening asks the object and the attachment without paying for
	 * the chain. A visitor with no security context is refused before it.
	 *
	 * The chain answers about a link, so it is asked only by a visitor who proved they hold one: the
	 * hash of an opening is no such proof, and the path of a visitor bringing none ends in the refusal
	 * it ended in before the chain was asked there at all.
	 */
	private function resolveRights(
		File $file,
		int $userId,
		string $accessType,
		?SecurityContext $securityContext,
		?AttachedObject $attachedObject,
		bool $hasUnifiedLinkProof,
	): UnifiedLinkAccessLevel
	{
		if ($accessType === OpeningContext::ACCESS_TYPE_UNIFIED)
		{
			return $this->getUnifiedLinkAccessService()->check($file, $attachedObject, $userId);
		}

		if (!$securityContext)
		{
			return UnifiedLinkAccessLevel::Denied;
		}

		if ($file->canUpdate($securityContext))
		{
			return UnifiedLinkAccessLevel::Edit;
		}

		if ($hasUnifiedLinkProof)
		{
			return $this->getUnifiedLinkAccessService()->check($file, $attachedObject, $userId);
		}

		if ($this->canReadOpening($file, $userId, $securityContext, $attachedObject))
		{
			return UnifiedLinkAccessLevel::Read;
		}

		return UnifiedLinkAccessLevel::Denied;
	}

	/**
	 * The rule of {@see Models\DocumentSession::canEdit()} over a verdict which has two sources: the
	 * right on the object, or - on the internal path - the chain of the unified link {@see resolveRights()}.
	 * The verdict is asked first because the description has already paid for it; the attachment costs the
	 * entity the document is attached to.
	 *
	 * The link a page was opened by is its ceiling: a viewing link keeps no transition to editing
	 * whatever rights the visitor holds. The refusal is closed - a link which no longer answers is a
	 * ceiling that cannot be read, not the absence of one {@see resolveAccessType()}.
	 */
	private function canOpenForEditing(
		int $userId,
		?SecurityContext $securityContext,
		UnifiedLinkAccessLevel $rights,
		string $accessType,
		?AttachedObject $attachedObject,
		?ExternalLink $externalLink,
	): bool
	{
		if ($accessType === OpeningContext::ACCESS_TYPE_UNIFIED)
		{
			return $rights->canEdit() && (bool)$externalLink?->allowEdit();
		}

		if ($securityContext === null)
		{
			return false;
		}

		try
		{
			return $rights->canEdit()
				|| (bool)$externalLink?->allowEdit()
				|| (bool)$attachedObject?->canUpdate($userId)
			;
		}
		catch (\Throwable $e)
		{
			$this->logUnforeseenFailure($e);

			return false;
		}
	}

	/**
	 * The rule of {@see Models\DocumentSession::canRead()}: an attachment authorizes the document it is
	 * attached to, so reading is not decided by the object in its storage alone.
	 */
	protected function canReadOpening(
		File $file,
		int $userId,
		SecurityContext $securityContext,
		?AttachedObject $attachedObject,
	): bool
	{
		return (bool)$attachedObject?->canRead($userId) || $file->canRead($securityContext);
	}

	protected function getSecurityContext(File $file, int $userId): ?SecurityContext
	{
		if ($userId <= 0)
		{
			return null;
		}

		return $file->getStorage()?->getSecurityContext($userId);
	}

	protected function getAttachedObject(?Models\DocumentSessionContext $sessionContext): ?AttachedObject
	{
		return $sessionContext?->getAttachedObject();
	}

	protected function getExternalLink(?Models\DocumentSessionContext $sessionContext): ?ExternalLink
	{
		return $sessionContext?->getExternalLink();
	}

	/**
	 * An unreadable CONTEXT is no reason to refuse the whole opening, so it degrades to "no attachment,
	 * no link", and only the type it was entered by is read from the column of the row
	 * {@see accessTypeOf()}. Such an opening silently loses what authorized it, so the row is left in
	 * the log.
	 */
	private function getSessionContext(Models\DocumentSession $documentSession): ?Models\DocumentSessionContext
	{
		try
		{
			return $documentSession->getContext();
		}
		catch (\Throwable $e)
		{
			$this->logUnforeseenFailure($e);

			return null;
		}
	}

	/** One instance per request, shared with every other consumer of the locator. */
	private function getUnifiedLinkAccessService(): UnifiedLinkAccessService
	{
		return $this->unifiedLinkAccessService ??= ServiceLocator::getInstance()->get(UnifiedLinkAccessService::class);
	}

	protected function logUnforeseenFailure(\Throwable $e): void
	{
		Application::getInstance()->getExceptionHandler()->writeToLog($e);
	}
}
