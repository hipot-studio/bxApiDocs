<?php

namespace Bitrix\Sign\Operation\Document;

use Bitrix\Main;
use Bitrix\Sign\Contract\Operation;
use Bitrix\Sign\Item;
use Bitrix\Sign\Repository\DocumentRepository;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Service\Integration\Crm\B2eDocumentService;
use Bitrix\Sign\Service\Sign\DocumentService;
use Bitrix\Sign\Service\Sign\MemberService;
use Bitrix\Sign\Type\DocumentStatus;

/**
 * Returns the document to the captured state.
 *
 * Public modify* methods of the document service reject empty values, so they can not put a field
 * back to the empty value it had before the change. Steps that went to the external signing
 * service are out of the operation scope.
 *
 * @see \Bitrix\Sign\Service\Sign\DocumentService::captureState()
 */
final class RestoreState implements Operation
{
	/**
	 * Only the properties whose column takes an empty value are listed. The external id is left
	 * out because the binder allows it a null of its own. The parties count and the source of the
	 * external id are left out because their columns take none: an emptied one of them is stored
	 * as a zero, that is a document of no parties and a source read back as the manual one.
	 *
	 * @var list<string> document properties the snapshot is allowed to empty
	 *
	 * @see \Bitrix\Sign\Model\ItemBinder\DocumentBinder::isAllowNullValue()
	 */
	private const NULLABLE_PROPERTIES = [
		'representativeId',
		'companyUid',
		'companyEntityId',
		'providerCode',
		'regionDocumentType',
		'hcmLinkExternalIdSettingId',
	];

	/**
	 * The lock the composition writers of one document are serialized by, spelled the way the
	 * domain path spells it: the branch of the empty snapshot writes the composition through the
	 * low-level clean instead of that path, so it takes the very same lock itself. The name is a
	 * copy because the domain path keeps it private.
	 *
	 * @see \Bitrix\Sign\Service\Sign\MemberService::setupB2eMembers() the writer this one races
	 */
	private const COMPOSITION_LOCK_NAME_PREFIX = 'sign_document_members_';

	private readonly DocumentRepository $documentRepository;
	private readonly MemberService $memberService;
	private readonly DocumentService $documentService;
	private readonly B2eDocumentService $b2eDocumentService;

	/**
	 * @param ?Item\Document\Configure\Journal $journal what the run has applied, where the caller keeps
	 *        such a journal: a step the run never applied has moved nothing, and putting that part of
	 *        the snapshot back would undo a state nobody has touched. Null asks the whole snapshot back.
	 */
	public function __construct(
		private readonly Item\Document\State $state,
		private readonly ?Item\Document\Configure\Journal $journal = null,
		?DocumentRepository $documentRepository = null,
		?MemberService $memberService = null,
		?DocumentService $documentService = null,
		?B2eDocumentService $b2eDocumentService = null,
	)
	{
		$container = Container::instance();

		$this->documentRepository = $documentRepository ?? $container->getDocumentRepository();
		$this->memberService = $memberService ?? $container->getMemberService();
		$this->documentService = $documentService ?? $container->getDocumentService();
		$this->b2eDocumentService = $b2eDocumentService ?? $container->getB2eDocumentService();
	}

	public function launch(): Main\Result
	{
		$result = new Main\Result();
		if ($this->isNothingApplied())
		{
			return $result;
		}

		$document = $this->documentRepository->getByUid($this->state->documentUid);
		if ($document === null)
		{
			return $result->addError(new Main\Error('Document not found'));
		}

		$result->addErrors($this->restoreMembers()->getErrors());

		// the member composition writes document fields on its own, so the document is re-read after it
		$document = $this->documentRepository->getByUid($this->state->documentUid);
		if ($document === null)
		{
			return $result->addError(new Main\Error('Document not found'));
		}

		$result->addErrors($this->restoreFields($document)->getErrors());

		return $result;
	}

	/**
	 * A run that has applied nothing has nothing to be returned from: a restore of an untouched
	 * document would write its member composition anew and could report a failure of its own.
	 */
	private function isNothingApplied(): bool
	{
		return $this->journal !== null && $this->journal->listApplied() === [];
	}

	/**
	 * Whether the run has applied the step. A caller that keeps no journal asks for the whole
	 * snapshot back and gets every part of it.
	 */
	private function isApplied(string $stepName): bool
	{
		if ($this->journal === null)
		{
			return true;
		}

		foreach ($this->journal->listApplied() as $step)
		{
			if ($step->name === $stepName)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the run has written the member composition.
	 *
	 * @see Item\Document\Configure\Step::COMPOSITION_WRITERS the steps that write it
	 */
	private function isCompositionApplied(): bool
	{
		foreach (Item\Document\Configure\Step::COMPOSITION_WRITERS as $stepName)
		{
			if ($this->isApplied($stepName))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * Both branches read the document themselves: the composition is written under a lock of its
	 * own, and the document the state of it is asked of has to be the one behind that lock.
	 */
	private function restoreMembers(): Main\Result
	{
		// the composition nobody has moved needs neither the members nor the card of it put back
		if (!$this->isCompositionApplied())
		{
			return new Main\Result();
		}

		if ($this->state->members->isEmpty())
		{
			return $this->clearMembers();
		}

		// the domain path mutates the composition it applies, so the snapshot goes as a clone of
		// itself and stays applicable again
		return $this->memberService->setupB2eMembers(
			documentUid: $this->state->documentUid,
			memberCollection: $this->state->members->cloneMembers(),
			representativeId: (int)$this->state->representativeId,
			skipPermissionCheck: true,
		);
	}

	/**
	 * The empty snapshot is put back by the low-level clean of the composition, and that one
	 * neither serializes itself against the domain path nor looks at the document at all. Both are
	 * asked here instead: the branch that wipes a composition must be no weaker than the branch
	 * that writes one, or a compensation would take the members of a document a neighbour has
	 * meanwhile filled and sent.
	 *
	 * The lock is the one the domain path takes, and this branch never calls that path, so there is
	 * no lock of ours to take twice.
	 *
	 * @see \Bitrix\Sign\Service\Sign\MemberService::setupB2eMembers() the branch of the non-empty
	 *      snapshot, refusing and locking by the same two rules
	 */
	private function clearMembers(): Main\Result
	{
		$documentUid = $this->state->documentUid;
		if (!$this->acquireCompositionLock($documentUid))
		{
			return (new Main\Result())->addError(
				new Main\Error(
					message: (string)Main\Localization\Loc::getMessage(
						'SIGN_OPERATION_DOCUMENT_RESTORE_STATE_COMPOSITION_IS_BUSY',
					),
					code: 'SIGN_DOCUMENT_MEMBERS_BUSY',
				),
			);
		}

		try
		{
			return $this->clearMembersUnderLock();
		}
		finally
		{
			$this->releaseCompositionLock($documentUid);
		}
	}

	/**
	 * The document is read anew under the lock: the status of it is what decides here, and the
	 * document could have gone to signing while the lock was being taken.
	 */
	private function clearMembersUnderLock(): Main\Result
	{
		$document = $this->documentRepository->getByUid($this->state->documentUid);
		if ($document === null)
		{
			return (new Main\Result())->addError(new Main\Error('Document not found'));
		}

		$refusal = self::findCompositionRefusal($document);
		if ($refusal !== null)
		{
			return (new Main\Result())->addError($refusal);
		}

		// the card follows the company member, so only a composition in the document could have moved it
		$documentHasMembers = !$this->memberService->listByDocumentId((int)$document->id)->isEmpty();

		$cleanResult = $this->memberService->cleanByDocumentId((int)$document->id);
		if (!$cleanResult->isSuccess())
		{
			return $cleanResult;
		}

		return $documentHasMembers ? $this->restoreMyCompany($document) : new Main\Result();
	}

	/**
	 * Why the member composition of the document may no longer be written, if it may not. The
	 * window and the refusal are the ones of the domain path, so a compensation refused by the
	 * state of the document reports the same reason whichever branch it took.
	 *
	 * @see \Bitrix\Sign\Service\Sign\MemberService::setupB2eMembers() the same window
	 */
	private static function findCompositionRefusal(Item\Document $document): ?Main\Error
	{
		if (!in_array($document->status, DocumentStatus::getEnding(), true))
		{
			return null;
		}

		return new Main\Error(
			message: (string)Main\Localization\Loc::getMessage(
				'SIGN_OPERATION_DOCUMENT_RESTORE_STATE_COMPOSITION_CANNOT_BE_CHANGED',
			),
			code: 'SIGN_DOCUMENT_INCORRECT_STATUS',
			customData: [
				'has' => $document->status,
				'expected' => array_values(
					array_diff(DocumentStatus::getAll(), DocumentStatus::getEnding()),
				),
			],
		);
	}

	/**
	 * Zero timeout: a composition another writer is already changing is refused right away instead
	 * of holding the request until it is done.
	 */
	private function acquireCompositionLock(string $documentUid): bool
	{
		return Main\Application::getConnection()->lock(self::COMPOSITION_LOCK_NAME_PREFIX . $documentUid, 0);
	}

	private function releaseCompositionLock(string $documentUid): bool
	{
		return Main\Application::getConnection()->unlock(self::COMPOSITION_LOCK_NAME_PREFIX . $documentUid);
	}

	/**
	 * The applied composition mirrors its company member onto the smart process card, so a
	 * composition applied back takes the card with it. An empty one has nothing to mirror:
	 * the card is put back on its own.
	 *
	 * @see \Bitrix\Sign\Service\Sign\MemberService::setupB2eMembers()
	 */
	private function restoreMyCompany(Item\Document $document): Main\Result
	{
		$myCompanyId = $this->state->myCompanyId;
		if ($myCompanyId === null || $document->isTemplated())
		{
			return new Main\Result();
		}

		return $myCompanyId > 0
			? $this->b2eDocumentService->setMyCompany($document, $myCompanyId)
			: $this->b2eDocumentService->resetMyCompany($document)
		;
	}

	private function restoreFields(Item\Document $document): Main\Result
	{
		$document->representativeId = $this->state->representativeId;
		$document->companyUid = $this->state->companyUid;
		$document->companyEntityId = $this->state->companyEntityId;
		$document->providerCode = $this->state->providerCode;
		$document->regionDocumentType = $this->state->regionDocumentType;
		$document->externalId = $this->state->externalId;
		$document->externalIdSourceType = $this->state->externalIdSourceType;
		$document->hcmLinkExternalIdSettingId = $this->state->hcmLinkExternalIdSettingId;
		$document->parties = $this->state->parties;

		$updateResult = $this->documentRepository->updateAllowingNull($document, ...self::NULLABLE_PROPERTIES);
		if (!$updateResult->isSuccess())
		{
			return $updateResult;
		}

		// the smart process card title is derived from the document title and the external id
		return $this->documentService->refreshSmartDocumentTitle($document);
	}
}
