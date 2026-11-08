<?php

namespace Bitrix\Sign\Operation;

use Bitrix\Main;
use Bitrix\Main\Type\DateTime;
use Bitrix\Sign\Contract;
use Bitrix\Sign\Debug\Logger;
use Bitrix\Sign\Item;
use Bitrix\Sign\Operation;
use Bitrix\Sign\Repository\DocumentRepository;
use Bitrix\Sign\Repository\MemberRepository;
use Bitrix\Sign\Service\Analytic\AnalyticService;
use Bitrix\Sign\Service\B2e\MyDocumentsGrid\EventService;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Service\HrBotMessageService;
use Bitrix\Sign\Service\Integration\Crm\EventHandlerService;
use Bitrix\Sign\Service\PullService;
use Bitrix\Sign\Service\Integration\Im\ImService;
use Bitrix\Sign\Service\Integration\Im\InviteCardService;
use Bitrix\Sign\Service\UserService;
use Bitrix\Sign\Service\Sign\LegalLogService;
use Bitrix\Sign\Service\Sign\MemberService;
use Bitrix\Sign\Service\Integration\HumanResources\HcmLinkService;
use Bitrix\Sign\Type;
use Bitrix\Sign\Item\Integration\Im\Messages\GroupChat\B2b\SigningStartedMessage;
use Bitrix\Sign\Item\Integration\Im\Messages\GroupChat\B2b\SigningCompletedMessage;
use Bitrix\Sign\Type\DocumentStatus;
use Bitrix\Sign\Type\Integration\Rest\EventType;
use Bitrix\Sign\Type\Integration\Rest\RestDocumentStatus;
use Throwable;

final class ChangeDocumentStatus implements Contract\Operation
{
	private DocumentRepository $documentRepository;
	private EventHandlerService $eventHandlerService;
	private PullService $pullService;
	private HrBotMessageService $hrBotMessageService;
	private MemberRepository $memberRepository;
	private readonly MemberService $memberService;
	private LegalLogService $legalLogService;
	private readonly EventService $myDocumentGridEventService;
	private readonly ImService $imService;
	private readonly UserService $userService;
	private readonly AnalyticService $analyticService;
	private readonly HcmLinkService $hcmLinkService;
	private readonly InviteCardService $inviteCardService;
	private readonly Logger $logger;

	/**
	 * @param bool $initiatorIsUnknown the status change repeats what already happened elsewhere - in the
	 *   signing service or on the other party - so nobody here initiated it. Without this the legal log
	 *   names the user who triggered the local change as the one who performed the action.
	 */
	public function __construct(
		private Item\Document $document,
		private readonly string $status,
		private readonly ?DateTime $signDate = null,
		private readonly ?Item\Member $initiatorMember = null,
		private readonly bool $initiatorIsUnknown = false,
		?Logger $logger = null,
	)
	{
		$container = Container::instance();
		$this->logger = $logger ?? $container->getLogger();
		$this->inviteCardService = $container->getInviteCardService();
		$this->documentRepository = $container->getDocumentRepository();
		$this->eventHandlerService = $container->getEventHandlerService();
		$this->pullService = $container->getPullService();
		$this->hrBotMessageService = $container->getHrBotMessageService();
		$this->memberRepository = $container->getMemberRepository();
		$this->legalLogService = $container->getLegalLogService();
		$this->memberService = $container->getMemberService();
		$this->myDocumentGridEventService = $container->getMyDocumentGridEventService();
		$this->imService = $container->getImService();
		$this->userService = $container->getUserService();
		$this->analyticService = $container->getAnalyticService();
		$this->hcmLinkService = $container->getHcmLinkService();
	}

	public function getDocument(): Item\Document
	{
		return $this->document;
	}

	public function launch(): Main\Result
	{
		$result = new Main\Result();
		if ($this->document->id === null)
		{
			return $result->addError(new Main\Error('Empty document ID.'));
		}

		if ($this->document->isTemplated() && $this->status === Type\DocumentStatus::STOPPED)
		{
			// Expected for every template registered before the signing service learned the template
			// flag: such a template keeps its signing deadline, and the service stops it once the
			// deadline passes. The guard leaves the template intact, so this is a normal event and not
			// a failure. Templates registered after the flag never reach it at all, so the creation
			// date is what separates the two, and the level follows the expected case.
			$this->logger->notice(
				'Refused to stop templated document {documentId} (uid {uid}, template {templateId}, '
				. 'created {dateCreate}); expected when created before the template flag was released, '
				. 'a recurrence to look into only when created after it',
				[
					'documentId' => $this->document->id,
					'uid' => $this->document->uid,
					'templateId' => $this->document->templateId,
					'dateCreate' => $this->document->dateCreate?->format('Y-m-d H:i:s'),
				],
			);

			return $result->addError(new Main\Error('Can not stop templated document.'));
		}

		// status may be changed asynchronously
		$this->document = $this->documentRepository->getById($this->document->id);

		if (!in_array($this->document->status, Type\DocumentStatus::getAll()))
		{
			return $result->addError(new Main\Error("Unknown document status '{$this->document->status}'"));
		}

		if ($this->status === Type\DocumentStatus::DONE)
		{
			$signDate = $this->signDate ?? new \DateTime();
			$signDate->setDefaultTimeZone();
			$this->document->dateSign = $signDate;
		}

		if ($this->document->status !== $this->status)
		{
			$this->document->dateStatusChanged = new DateTime();
		}

		$oldStatus = $this->document->status;
		$this->document->status = $this->status;

		$updateResult = $this->documentRepository->update($this->document);
		if (!$updateResult->isSuccess())
		{
			return $result->addErrors($updateResult->getErrors());
		}
		if (!isset($updateResult->getData()['document']))
		{
			return $result->addError(new Main\Error('Document result is missing after update.'));
		}
		$this->document = $updateResult->getData()['document'];

		$this->processDocumentStatusChange($this->document, $oldStatus);

		if (Type\DocumentScenario::isB2EScenario($this->document->scenario ?? ''))
		{
			$initiatorUserId = $this->initiatorMember
				? $this->memberService->getUserIdForMember($this->initiatorMember, $this->document)
				: $this->document->stoppedById
			;

			$this->legalLogService->registerDocumentChangedStatus(
				$this->document,
				$this->initiatorMember,
				$this->initiatorIsUnknown,
			);

			$sendMessageResult = $this->hrBotMessageService->handleDocumentStatusChangedMessage(
				$this->document,
				$this->status,
				$initiatorUserId,
			);
			$result->addErrors($sendMessageResult->getErrors());

			$this->eventHandlerService->handleCurrentDocumentStatus(
				$this->document,
				$initiatorUserId,
			);

			$members = $this->memberRepository->listByDocumentId($this->document->id);

			// A document that ended expects nobody to act any more, and this is its last event. Both
			// endings need it: stopping does not move participants to a finished status at all, and
			// past a completed document their own status change cannot mark anything either - the
			// action counts as over before and after it alike. On a completion the call normally finds
			// every card marked already and costs the lookup and nothing else. Above every early
			// return below, and on the list loaded here.
			if (in_array($this->status, Type\DocumentStatus::getFinalStatuses(), true))
			{
				$this->inviteCardService->markCompletedForMembers($members);
			}

			foreach ($members->toArray() as $member)
			{
				// TODO: remove this after the "My Documents" grid is released
				$this->pullService->sendMemberStatusChanged($this->document, $member);
			}

			if ($this->status === Type\DocumentStatus::STOPPED)
			{
				$addMembersResult = (new Operation\DocumentChat\AddMembersByStoppedDocument($this->document))->launch();
				if (!$addMembersResult->isSuccess())
				{
					return $result->addErrors($addMembersResult->getErrors());
				}

				$updateMyDocumentsCounterResult = (new Operation\Document\UpdateCounters(
					Type\CounterType::SIGN_B2E_MY_DOCUMENTS,
					$this->document,
				))->launch();

				if (!$updateMyDocumentsCounterResult->isSuccess())
				{
					return $updateMyDocumentsCounterResult;
				}

				$setDocumentStoppedByResult = $this->updateDocumentStoppedByValue($initiatorUserId);
				if (!$setDocumentStoppedByResult->isSuccess())
				{
					return $setDocumentStoppedByResult;
				}

				$kanbanPullEventOperation = new Operation\Kanban\B2e\SendDeleteEntityPullEvent($this->document);
				$kanbanPullEventOperationResult = $kanbanPullEventOperation->launch();
				if (!$kanbanPullEventOperationResult->isSuccess())
				{
					return $kanbanPullEventOperationResult;
				}

				$unsetDocumentSmartProcessResult = $this->unsetStoppedSmartB2eProcess();
				if (!$unsetDocumentSmartProcessResult->isSuccess())
				{
					return $unsetDocumentSmartProcessResult;
				}

				$result = $this->myDocumentGridEventService->onDocumentStop($this->document, $this->initiatorMember);
				if (!$result->isSuccess())
				{
					return $result;
				}
			}
			elseif ($this->status === Type\DocumentStatus::DONE && $this->document->isInitiatedByEmployee())
			{
				$signer = $members->findFirstByRole(Type\Member\Role::SIGNER);
				if ($signer)
				{
					$result = $this->myDocumentGridEventService->onMemberStatusChanged($this->document, $signer);
				}
			}
		}
		elseif (
			Type\DocumentScenario::isB2BScenario($this->document->scenario ?? '')
			&& $this->document->chatId !== null
		)
		{
			$sendMessageResult = $this->sendB2bGroupChatMessage($this->document);

			if (!$sendMessageResult->isSuccess())
			{
				return $sendMessageResult;
			}
		}
		$this->collectAnalytics();

		return $result;
	}

	private function sendB2bGroupChatMessage(Item\Document $document): Main\Result
	{
		if (!Type\DocumentScenario::isB2BScenario($document->scenario ?? ''))
		{
			return (new Main\Result())->addError(new Main\Error('Invalid document scenario.'));
		}

		if ($document->chatId === null)
		{
			return (new Main\Result())->addError(new Main\Error('Invalid chatId.'));
		}

		if ($document->createdById === null)
		{
			return (new Main\Result())->addError(new Main\Error('Invalid createById.'));
		}

		$user = $this->userService->getUserById($document->createdById);

		if ($user === null)
		{
			return (new Main\Result())->addError(new Main\Error('User not found.'));
		}

		return match($this->status)
		{
			Type\DocumentStatus::SIGNING => $this->imService->sendGroupChatMessage(
				$document->chatId,
				$document->createdById,
				new SigningStartedMessage($document),
			),
			Type\DocumentStatus::DONE => $this->imService->sendGroupChatMessage(
				$document->chatId,
				$document->createdById,
				new SigningCompletedMessage($document),
			),
			default => new Main\Result(),
		};
	}

	private function unsetStoppedSmartB2eProcess(): Main\Result
	{
		if (!$this->document->isInitiatedByEmployee())
		{
			return new Main\Result();
		}

		$member = $this->memberService->getSigner($this->document);
		if (in_array($member?->status, Type\MemberStatus::getReadyForSigning(), true))
		{
			$unsetDocumentSmartProcessResult = (new UnsetStoppedSmartB2eProcess($this->document))->launch();
			if (!$unsetDocumentSmartProcessResult->isSuccess())
			{
				return $unsetDocumentSmartProcessResult;
			}
		}

		return new Main\Result();
	}

	private function updateDocumentStoppedByValue(?int $initiatorUserId): Main\Result
	{
		$result = new Main\Result();

		if ($initiatorUserId === null)
		{
			return $result;
		}

		if ($this->document->stoppedById === $initiatorUserId)
		{
			return $result;
		}

		$this->document->stoppedById = $initiatorUserId;
		$updateResult = $this->documentRepository->update($this->document);

		if (!$updateResult->isSuccess())
		{
			return $updateResult;
		}

		if (!isset($updateResult->getData()['document']))
		{
			return $result->addError(new Main\Error('Document result is missing after update.'));
		}

		$this->document = $updateResult->getData()['document'] ?? null;

		return $result;
	}

	private function collectAnalytics(): void
	{
		if (Type\DocumentScenario::isB2bScenarioByDocument($this->document) && $this->status === Type\DocumentStatus::DONE)
		{
			$member = $this->memberRepository->getByPartyAndDocumentId($this->document->id, $this->document->parties);

			$analyticsEvent = (new Main\Analytics\AnalyticsEvent(
				'document_signed',
				'sign',
				'documents',
			))
				->setType('b2b')
				->setStatus('success')
				->setP5("docId_{$this->document->id}")
			;
			$this->analyticService->sendEventWithSigningContext(
				$analyticsEvent,
				$member,
			);
		}
	}

	private function processDocumentStatusChange(Item\Document $document, ?string $oldStatus): void
	{
		$statusList = [
			DocumentStatus::SIGNING,
			DocumentStatus::DONE,
			DocumentStatus::STOPPED,
		];

		if ($document->status !== $oldStatus && in_array($document->status, $statusList))
		{
			$company = null;
			if ($document->hcmLinkCompanyId)
			{
				$company = $this->hcmLinkService->getCompanyById($document->hcmLinkCompanyId);
			}

			try {
				$event = new Main\Event(
					'sign',
					EventType::DOCUMENT_STATUS_CHANGED->value,
					[
						'documentUid' => $document->uid,
						'companyUid' => $company?->code,
						'statusCode' => RestDocumentStatus::getDocumentStatusCode($document->status),
						'statusName' => RestDocumentStatus::getDocumentStatusName($document->status, 'en'),
					],
				);
				$event->send();
			}
			catch (Throwable $e)
			{
				Container::instance()->getLogger('Operation')->error(
					"Failed to send event {event} for document {documentUid}: {errorsText}",
					[
						'event' => EventType::DOCUMENT_STATUS_CHANGED->value,
						'documentUid' => $document->uid,
						'errorsText' => $e->getMessage(),
					],
				);
			}
		}
	}
}
