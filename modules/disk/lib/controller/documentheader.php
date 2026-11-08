<?php
declare(strict_types=1);

namespace Bitrix\Disk\Controller;

use Bitrix\Disk\Document\Models\DocumentSession;
use Bitrix\Disk\Internal\Service\DocumentHeader\HeaderCompositionService;
use Bitrix\Disk\Internal\Service\DocumentHeader\OpeningContext;
use Bitrix\Disk\Internal\Service\DocumentHeader\OpeningContextFactory;
use Bitrix\Disk\Internal\Service\DocumentHeader\RenameService;
use Bitrix\Disk\Internal\Service\DocumentPresence\InitialRosterService;
use Bitrix\Disk\Internal\Service\UnifiedLink\UnifiedLinkAccessService;
use Bitrix\Disk\Internal\Service\UnifiedLink\UnifiedLinkUrl;
use Bitrix\Disk\Internals\Engine;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\AutoWire\ExactParameter;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;

/**
 * Header of the document/board viewer: everything the header needs for a concrete opening.
 *
 * Holds no composition logic of its own and does not branch by editor engine - it builds the context
 * of the opening from the session and hands the decision to {@see HeaderCompositionService}.
 */
class DocumentHeader extends Engine\Controller
{
	public const ERROR_SESSION_NOT_FOUND = 'SESSION_NOT_FOUND';
	public const ERROR_PRESENCE_NOT_AVAILABLE = InitialRosterService::ERROR_ROSTER_UNAVAILABLE;

	private const ERROR_MESSAGES = [
		self::ERROR_SESSION_NOT_FOUND => 'DISK_CONTROLLER_DOCUMENT_HEADER_ERROR_SESSION_NOT_FOUND',
		self::ERROR_PRESENCE_NOT_AVAILABLE => 'DISK_CONTROLLER_DOCUMENT_HEADER_ERROR_PRESENCE_NOT_AVAILABLE',
		HeaderCompositionService::ERROR_OBJECT_NOT_FOUND => 'DISK_CONTROLLER_DOCUMENT_HEADER_ERROR_OBJECT_NOT_FOUND',
		HeaderCompositionService::ERROR_ACCESS_DENIED => 'DISK_CONTROLLER_DOCUMENT_HEADER_ERROR_ACCESS_DENIED',
		RenameService::ERROR_INVALID_NAME => 'DISK_CONTROLLER_DOCUMENT_HEADER_ERROR_INVALID_NAME',
		RenameService::ERROR_RENAME_FAILED => 'DISK_CONTROLLER_DOCUMENT_HEADER_ERROR_RENAME_FAILED',
	];

	public function getAutoWiredParameters(): array
	{
		return [
			new ExactParameter(
				DocumentSession::class,
				'documentSession',
				fn($className, string $documentSessionHash): ?DocumentSession
					=> $this->resolveDocumentSession($documentSessionHash),
			),
		];
	}

	/**
	 * The hash of an opening is not an identifier of a row and is no secret of one user: everybody who
	 * opens the same document shares it, because {@see DocumentSession::forkForUser()} copies the hash
	 * into the row of the next user and gives that row its own CONTEXT. Only the row of the current user
	 * describes the opening this user is asking about - another one names the attached object and the
	 * external link of somebody else, and the header would be composed, the roster built and the link
	 * addressed in a data area this user was never authorized for.
	 *
	 * An opening by a public link is kept by the same filter, see {@see buildSessionFilter()}.
	 */
	protected function resolveDocumentSession(string $documentSessionHash): ?DocumentSession
	{
		return $this->loadDocumentSession(
			$this->buildSessionFilter(
				$documentSessionHash,
				(int)($this->getCurrentUser() ?? CurrentUser::get())->getId(),
			),
		);
	}

	/**
	 * A visitor with no account is asked for by the absence of an account rather than by an exact id: the
	 * page of a public link records such an opening with the negative id of
	 * {@see \Bitrix\Disk\Document\Models\GuestUser}, while a session built by the commands of the module
	 * keeps the plain 0 of CurrentUser. Neither of them reaches the row of a portal user this way, and a
	 * portal user reaches nobody but themselves.
	 */
	protected function buildSessionFilter(string $documentSessionHash, int $userId): array
	{
		$filter = ['=EXTERNAL_HASH' => $documentSessionHash];
		if ($userId > 0)
		{
			$filter['=USER_ID'] = $userId;
		}
		else
		{
			$filter['<=USER_ID'] = 0;
		}

		return $filter;
	}

	protected function loadDocumentSession(array $filter): ?DocumentSession
	{
		return DocumentSession::load($filter);
	}

	public function configureActions(): array
	{
		return [
			'getComposition' => $this->getReadingActionConfiguration(),
			'getPresence' => $this->getReadingActionConfiguration(),
			'rename' => $this->getActionConfiguration(),
		];
	}

	/**
	 * The header is requested by a page that is already open, including the public external-link one, so
	 * the actions must not demand a portal user on their own: every one of them is authorized
	 * fail-closed inside its own service. CSRF stays on.
	 */
	private function getActionConfiguration(): array
	{
		return [
			'-prefilters' => [ActionFilter\Authentication::class],
			'+prefilters' => [
				new ActionFilter\HttpMethod([ActionFilter\HttpMethod::METHOD_POST]),
			],
		];
	}

	/**
	 * Reading the header writes nothing into the PHP session, so it lets the session go before the work
	 * begins: the roster is the longest of these actions, and the tab asks for the page, the composition
	 * and the roster at once. The presence actions of the editor shells hold the session the same way.
	 */
	private function getReadingActionConfiguration(): array
	{
		$configuration = $this->getActionConfiguration();
		array_unshift($configuration['+prefilters'], new ActionFilter\CloseSession());

		return $configuration;
	}

	public function getCompositionAction(
		CurrentUser $currentUser,
		HeaderCompositionService $headerCompositionService,
		?DocumentSession $documentSession = null,
	): ?array
	{
		if (!$documentSession)
		{
			$this->addSessionNotFoundError();

			return null;
		}

		$userId = (int)$currentUser->getId();
		$result = $headerCompositionService->compose(
			$this->buildOpeningContext($documentSession, $userId),
			$userId,
		);

		if (!$result->isSuccess())
		{
			$this->addLocalizedErrors($result);

			return null;
		}

		return $result->getData()['composition']->toArray();
	}

	/**
	 * API-02: the starting roster of presence together with the revision of the live one, so the header
	 * can drop the live events it has already outrun.
	 */
	public function getPresenceAction(
		CurrentUser $currentUser,
		HeaderCompositionService $headerCompositionService,
		InitialRosterService $initialRosterService,
		?DocumentSession $documentSession = null,
	): ?array
	{
		if (!$documentSession)
		{
			$this->addSessionNotFoundError();

			return null;
		}

		$userId = (int)$currentUser->getId();
		$openingContext = $this->buildPresenceContext($documentSession, $userId);

		$presence = $headerCompositionService->hasPresence($openingContext);
		// An opening that could not be described is refused by the answer above; naming it here as well
		// leaves nothing below reading a context of nothing.
		if ($openingContext === null || !$presence->isSuccess())
		{
			$this->addLocalizedErrors($presence);

			return null;
		}

		// The description of the opening decides whether it has presence at all - a board never has it -
		// so an opening without the element is refused instead of being answered with an empty roster.
		if (!$presence->getData()['presenceEnabled'])
		{
			$this->addError($this->localizeError(
				new Error('Presence is not a part of this opening.', self::ERROR_PRESENCE_NOT_AVAILABLE)
			));

			return null;
		}

		// A successful answer of the header means the opening was described, so its access type is the
		// one the roster of that opening is stored under.
		$roster = $initialRosterService->collect($documentSession, $userId, $openingContext->accessType());
		if (!$roster->isSuccess())
		{
			$this->addLocalizedErrors($roster);

			return null;
		}

		return $roster->getData();
	}

	/**
	 * API-03: renaming of the object from the header. Unlike reading, the answer does not depend on
	 * how the opening was authorized - renaming asks the object rights themselves.
	 */
	public function renameAction(
		CurrentUser $currentUser,
		RenameService $renameService,
		string $newName,
		?DocumentSession $documentSession = null,
	): ?array
	{
		if (!$documentSession)
		{
			$this->addSessionNotFoundError();

			return null;
		}

		$result = $renameService->rename($documentSession, $newName, (int)$currentUser->getId());
		if (!$result->isSuccess())
		{
			$this->addLocalizedErrors($result);

			return null;
		}

		return $result->getData();
	}

	/**
	 * The description is composed from the context of the opening; the session is the way this
	 * controller builds one, and the only thing about the header that still knows about a session.
	 */
	protected function buildOpeningContext(DocumentSession $documentSession, int $userId): ?OpeningContext
	{
		return $this->getOpeningContextFactory()->fromDocumentSession(
			$documentSession,
			$userId,
			$this->hasUnifiedLinkProof($documentSession),
		);
	}

	/**
	 * The presence action asks the description whether this user may read the opening and whether the
	 * opening carries a roster, and nothing else, so the context of it is built for those two questions
	 * alone {@see OpeningContextFactory::forPresence()}.
	 */
	protected function buildPresenceContext(DocumentSession $documentSession, int $userId): ?OpeningContext
	{
		return $this->getOpeningContextFactory()->forPresence(
			$documentSession,
			$userId,
			$this->hasUnifiedLinkProof($documentSession),
		);
	}

	/**
	 * A visitor the storage of the object grants nothing is let in by the unified link of it, so the
	 * description is told whether they proved they hold one - the signature naming the object of this
	 * very opening, the same proof the session filter of the editor and the reading of the file ask for
	 * {@see \Bitrix\Disk\Document\OnlyOffice\Filters\DocumentSessionCheck}. Bringing none is no error of
	 * the action: the description then refuses the way it refused before the link was ever asked about.
	 */
	protected function hasUnifiedLinkProof(DocumentSession $documentSession): bool
	{
		$objectId = (int)$documentSession->getObjectId();
		if ($objectId <= 0)
		{
			return false;
		}

		$uls = $this->getUnifiedLinkUrl()->getUlsFromRequest($this->getRequest());

		return $uls !== null
			&& $this->getUnifiedLinkAccessService()->validateUlsForObjectId($objectId, $uls)
		;
	}

	/**
	 * One instance per request, shared with every other consumer of the locator.
	 */
	protected function getOpeningContextFactory(): OpeningContextFactory
	{
		return ServiceLocator::getInstance()->get(OpeningContextFactory::class);
	}

	protected function getUnifiedLinkUrl(): UnifiedLinkUrl
	{
		return ServiceLocator::getInstance()->get(UnifiedLinkUrl::class);
	}

	protected function getUnifiedLinkAccessService(): UnifiedLinkAccessService
	{
		return ServiceLocator::getInstance()->get(UnifiedLinkAccessService::class);
	}

	private function addSessionNotFoundError(): void
	{
		$this->addError(
			$this->localizeError(new Error('Document session is not found.', self::ERROR_SESSION_NOT_FOUND))
		);
	}

	private function addLocalizedErrors(Result $result): void
	{
		foreach ($result->getErrors() as $error)
		{
			$this->addError($this->localizeError($error));
		}
	}

	private function localizeError(Error $error): Error
	{
		$messageId = self::ERROR_MESSAGES[$error->getCode()] ?? null;
		if ($messageId === null)
		{
			return $error;
		}

		return new Error((string)Loc::getMessage($messageId), $error->getCode());
	}
}
