<?php

namespace Bitrix\Sign\Operation\Member\ResultFile;

use Bitrix\Main;
use Bitrix\Sign\Contract;
use Bitrix\Sign\Item;
use Bitrix\Sign\Repository\EntityFileRepository;
use Bitrix\Sign\Repository\FileRepository;
use Bitrix\Sign\Repository\MemberRepository;
use Bitrix\Sign\Result\Result;
use Bitrix\Sign\Service\B2e\MyDocumentsGrid\EventService;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Service\Integration\HumanResources\HcmLinkSignedFileService;
use Bitrix\Sign\Service\Sign\LegalLogService;
use Bitrix\Sign\Type;

class Save implements Contract\Operation
{
	private readonly FileRepository $fileRepository;
	private readonly EntityFileRepository $entityFileRepository;
	private readonly LegalLogService $legalLogService;
	private readonly MemberRepository $memberRepository;
	private readonly HcmLinkSignedFileService $hcmLinkSignedFileService;
	private readonly EventService $myDocumentGridEventService;

	public function __construct(
		private readonly Item\Document $document,
		private readonly Item\Member $member,
		private readonly Item\Fs\File $resultFile,
	)
	{
		$this->fileRepository = Container::instance()->getFileRepository();
		$this->memberRepository = Container::instance()->getMemberRepository();
		$this->legalLogService = Container::instance()->getLegalLogService();
		$this->entityFileRepository = Container::instance()->getEntityFileRepository();
		$this->hcmLinkSignedFileService = Container::instance()->getHcmLinkSignedFileService();
		$this->myDocumentGridEventService = Container::instance()->getMyDocumentGridEventService();
	}

	public function launch(): Main\Result
	{
		$result = $this->validateArguments();
		if (!$result->isSuccess())
		{
			return $result;
		}
		$result = $this->saveResultFileIfItsNot();
		if (!$result->isSuccess())
		{
			return $result;
		}

		$member = $this->member;
		$documentItem = $this->document;

		// Receipt happens on the first save of the signed result file. The SIGNED entity file is created
		// below in addEntityFile(), so its prior absence is the idempotency marker: robust across both
		// save paths (sync callback + retrying agent) and independent of dateSigned, which is already
		// set on the synchronous SIGNER->DONE transition before this runs. Probe only when the receipt
		// scenario applies at all, so unrelated saves do not pay for an extra SELECT.
		$wasNotReceivedBefore = false;
		if ($this->isReceiptScenarioApplicable($documentItem, $member))
		{
			$wasNotReceivedBefore = $this->entityFileRepository->getOne(
				Type\EntityType::MEMBER,
				$member->id,
				Type\EntityFileCode::SIGNED,
			) === null;
		}

		$isDone = $this->addEntityFile();

		if (!$isDone->isSuccess())
		{
			Result::createByErrorMessage('Failed to save file');

			return $result;
		}

		$this->logMemberFileSaved($documentItem, $member, $this->resultFile);
		$this->updateMemberDateSigned($member);

		if (!empty($documentItem->hcmLinkCompanyId))
		{
			$this->hcmLinkSignedFileService
				->processSignedDocument($documentItem, $member)
			;
		}

		$this->myDocumentGridEventService->onMemberResultFileSave($documentItem, $member);

		$this->notifyEmployeeAboutCompanyReceipt($documentItem, $member, $wasNotReceivedBefore);

		return $result;
	}

	/**
	 * SC-001: on the receipt event (the company side saving the signed result file), notify the
	 * employee signer with the "document signed" message plus a receipt line. Additive side effect,
	 * idempotent on the first save of the result file.
	 */
	private function notifyEmployeeAboutCompanyReceipt(
		Item\Document $document,
		Item\Member $member,
		bool $wasNotReceivedBefore,
	): void
	{
		if (!$wasNotReceivedBefore || !$this->isReceiptScenarioApplicable($document, $member))
		{
			return;
		}

		// Guard against the revoke race: a stopped document must not send a receipt message even if
		// the retrying download agent still saves the result file after the stop.
		if ($document->status === Type\DocumentStatus::STOPPED)
		{
			if (!empty($document->hcmLinkCompanyId))
			{
				Container::instance()
					->getLogger('B2e')
					->debug(
						'sign b2e receipt marks SC-001 skipped: hcm-linked document is stopped, documentId=' . $document->id,
					)
				;
			}

			return;
		}

		$sendResult = Container::instance()
			->getHrBotMessageService()
			->handleCompanyReceivedSignedByCompanyDocument($document, $member)
		;

		if (!$sendResult->isSuccess())
		{
			// Best-effort side effect: an IM failure must not break the save flow, but unlike SC-002
			// (whose errors surface in the operation Result) a failed SC-001 send would otherwise be
			// silent. Log identifiers and error codes only, never recipient names or message content.
			$errorCodes = implode(',', array_map(
				static fn(Main\Error $error): string => (string)$error->getCode(),
				$sendResult->getErrors(),
			));

			Container::instance()
				->getLogger('B2e')
				->warning(
					'sign b2e receipt marks SC-001 delivery failed: documentId=' . $document->id
					. ', memberId=' . $member->id
					. ', errorCodes=' . $errorCodes,
				)
			;
		}
	}

	private function isReceiptScenarioApplicable(Item\Document $document, Item\Member $member): bool
	{
		// Receipt marks are a B2E-only flow. This operation runs for every scenario (B2B, legacy),
		// where initiatedByType defaults to COMPANY and a counterparty is a SIGNER as well.
		return Type\DocumentScenario::isB2EScenario($document->scenario)
			&& !$document->isInitiatedByEmployee()
			&& $member->role === Type\Member\Role::SIGNER
		;
	}

	private function addEntityFile(): Main\Result
	{
		$result = $this->validateArguments();
		if (!$result->isSuccess())
		{
			return $result;
		}
		$result = $this->saveResultFileIfItsNot();
		if (!$result->isSuccess())
		{
			return $result;
		}

		$fileItem = new Item\EntityFile(
			id: null,
			entityTypeId: Type\EntityType::MEMBER,
			entityId: $this->member->id,
			code: Type\EntityFileCode::SIGNED,
			fileId: $this->resultFile->id,
		);

		return $this->entityFileRepository->add($fileItem);
	}

	private function logMemberFileSaved(Item\Document $document, Item\Member $member, Item\Fs\File $fsFile): void
	{
		if (!$this->validateArguments()->isSuccess())
		{
			return;
		}
		if (!$this->saveFileIfItsNot($fsFile)->isSuccess())
		{
			return;
		}

		$this->legalLogService->registerMemberFileSaved($document, $member, $fsFile->id);
	}

	private function updateMemberDateSigned(Item\Member $member): void
	{
		if (!$this->validateArguments()->isSuccess())
		{
			return;
		}

		if ($member->dateSigned === null)
		{
			$member->dateSigned = new Main\Type\DateTime();
			$this->memberRepository->update($member);
		}
	}

	private function validateArguments(): Main\Result
	{
		$result = new Main\Result();
		if ($this->member->id === null)
		{
			$result->addError(new Main\Error('Member id is required'));
		}

		if ($this->document->id === null)
		{
			$result->addError(new Main\Error('Document id is required'));
		}

		// Document and member reach this operation resolved from independently supplied uids, so an
		// inconsistent pair would save the file and send the receipt message across two documents.
		if (
			$this->member->id !== null
			&& $this->document->id !== null
			&& $this->member->documentId !== $this->document->id
		)
		{
			$result->addError(new Main\Error('Document id mismatch'));
		}

		return $result;
	}

	private function saveResultFileIfItsNot(): Main\Result
	{
		return $this->saveFileIfItsNot($this->resultFile);
	}

	private function saveFileIfItsNot(Item\Fs\File $file): Main\Result
	{
		$result = new Main\Result();
		if ($file->id === null)
		{
			$saveResult = $this->fileRepository->put($file);
			if (!$saveResult->isSuccess())
			{
				$result->addErrors($saveResult->getErrors());
			}
		}

		return $result;
	}
}
