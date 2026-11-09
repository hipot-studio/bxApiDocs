<?php

namespace Bitrix\Sign\Service\Integration\Crm;

use Bitrix\Crm\Automation\Trigger\Sign\B2e\CompletedTrigger;
use Bitrix\Crm\Automation\Trigger\Sign\B2e\CoordinationAndFillingTrigger;
use Bitrix\Crm\Automation\Trigger\Sign\B2e\CoordinationTrigger;
use Bitrix\Crm\Automation\Trigger\Sign\B2e\FillingTrigger;
use Bitrix\Crm\Automation\Trigger\Sign\B2e\SigningDoneTrigger;
use Bitrix\Crm\Automation\Trigger\Sign\B2e\SigningStartedTrigger;
use Bitrix\Crm\Automation\Trigger\Sign\B2e\SigningStoppedTrigger;
use Bitrix\Crm\Automation\Trigger\Sign\B2e\SigningTrigger;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Timeline\SignB2eDocument\B2eController;
use Bitrix\Crm\Timeline\SignDocument\DocumentData;
use Bitrix\Crm\Timeline\SignDocument\MessageData;
use Bitrix\Main\Application;
use Bitrix\Main\Loader;
use Bitrix\Main\SystemException;
use Bitrix\Sign\Config\Feature;
use Bitrix\Sign\Debug\Logger;
use Bitrix\Sign\Integration\CRM\Model\EventData;
use Bitrix\Sign\Internal\MemberTable;
use Bitrix\Sign\Item\Document;
use Bitrix\Sign\Item\Member;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Service\MemberValidationCrmDispatchGuard;
use Bitrix\Sign\Service\Sign\MemberService;
use Bitrix\Sign\Type\Document\InitiatedByType;
use Bitrix\Sign\Type\Member\Role;

final class B2eEventCreator
{
	public function __construct(private ?Logger $logger = null)
	{
	}

	public function createEvent(
		string $eventType,
		ItemIdentifier $itemIdentifier,
		EventData $eventData,
		DocumentData $documentData,
		?MessageData $messageData,
	)
	{
		if (!Loader::includeModule('crm'))
		{
			return;
		}

		$crmController = B2eController::getInstance();

		switch ($eventType)
		{
			case EventData::TYPE_ON_REGISTER:
			case EventData::TYPE_ON_CREATE:
				$crmController->onCreate($itemIdentifier, $documentData);
				break;
			case EventData::TYPE_ON_SEND:
				$crmController->onMessageSent($itemIdentifier, $documentData, $messageData);
				$this->notifyActivityChange($crmController, $itemIdentifier);
				break;
			case EventData::TYPE_ON_STOPPED:
				$crmController->onSignStopped($itemIdentifier, $documentData);
				SigningStoppedTrigger::executeBySmartDocumentId($itemIdentifier->getEntityId());
				CompletedTrigger::executeBySmartDocumentId(
					$itemIdentifier->getEntityId(),
					$this->buildTriggerInputData($eventType, $eventData, $documentData),
				);
				$this->completeActivity($crmController, $itemIdentifier);
				$this->sendEvent($documentData, $eventType);
				break;
			case EventData::TYPE_ON_CANCELED_BY_RESPONSIBILITY_PERSON:
				$crmController->onCancelByResponsiblePerson($itemIdentifier, $documentData);
				SigningStoppedTrigger::executeBySmartDocumentId($itemIdentifier->getEntityId());
				CompletedTrigger::executeBySmartDocumentId(
					$itemIdentifier->getEntityId(),
					$this->buildTriggerInputData($eventType, $eventData, $documentData),
				);
				$this->completeActivity($crmController, $itemIdentifier);
				$this->sendEvent($documentData, $eventType);
				break;
			case EventData::TYPE_ON_CANCELED_BY_EMPLOYEE:
				$crmController->onCancelByEmployee($itemIdentifier, $documentData);
				$this->notifyActivityChange($crmController, $itemIdentifier);
				break;
			case EventData::TYPE_ON_CANCELED_BY_REVIEWER:
				$crmController->onCancelByReviewer($itemIdentifier, $documentData);
				SigningStoppedTrigger::executeBySmartDocumentId($itemIdentifier->getEntityId());
				CompletedTrigger::executeBySmartDocumentId(
					$itemIdentifier->getEntityId(),
					$this->buildTriggerInputData($eventType, $eventData, $documentData),
				);
				$this->completeActivity($crmController, $itemIdentifier);
				$this->sendEvent($documentData, $eventType);
				break;
			case EventData::TYPE_ON_CANCELED_BY_EDITOR:
				$crmController->onCancelByEditor($itemIdentifier, $documentData);
				SigningStoppedTrigger::executeBySmartDocumentId($itemIdentifier->getEntityId());
				CompletedTrigger::executeBySmartDocumentId(
					$itemIdentifier->getEntityId(),
					$this->buildTriggerInputData($eventType, $eventData, $documentData),
				);
				$this->completeActivity($crmController, $itemIdentifier);
				$this->sendEvent($documentData, $eventType);
				break;
			case EventData::TYPE_ON_SIGNED_BY_EMPLOYEE:
				$crmController->onSignedByEmployee($itemIdentifier, $documentData, $messageData);
				$this->notifyActivityChange($crmController, $itemIdentifier);
				$this->executeTriggerOnSignedByEmployee($documentData, $itemIdentifier, $eventType, $eventData);
				break;
			case EventData::TYPE_ON_SIGNED_BY_RESPONSIBILITY_PERSON:
				$crmController->onSignedByResponsiblePerson($itemIdentifier, $documentData, $messageData);
				$this->notifyActivityChange($crmController, $itemIdentifier);
				break;
			case EventData::TYPE_ON_SIGNED_BY_REVIEWER:
			case EventData::TYPE_ON_SIGNED_BY_EDITOR:
				$this->createValidationEvent(
					$crmController, $documentData, $itemIdentifier, $eventType, $eventData, $messageData,
				);
				break;
			case EventData::TYPE_ON_DONE:
				$crmController->onSignCompleted($itemIdentifier, $documentData);
				SigningDoneTrigger::executeBySmartDocumentId($itemIdentifier->getEntityId());
				CompletedTrigger::executeBySmartDocumentId(
					$itemIdentifier->getEntityId(),
					$this->buildTriggerInputData($eventType, $eventData, $documentData),
				);
				$this->completeActivity($crmController, $itemIdentifier);
				$this->sendEvent($documentData, $eventType);
				break;
			case EventData::TYPE_ON_STARTED:
				$crmController->onSignStarted($itemIdentifier, $documentData);
				SigningStartedTrigger::executeBySmartDocumentId($itemIdentifier->getEntityId());
				$this->executeTriggerOnStart($documentData, $itemIdentifier, $eventType, $eventData);
				$this->notifyActivityChange($crmController, $itemIdentifier);
				break;
			case EventData::TYPE_ON_READY_BY_REVIEWER_OR_EDITOR:
				$crmController->onSignStarted($itemIdentifier, $documentData);
				CoordinationAndFillingTrigger::executeBySmartDocumentId($itemIdentifier->getEntityId());
				break;
			case EventData::TYPE_ON_READY_BY_REVIEWER:
				$crmController->onSignStarted($itemIdentifier, $documentData);
				CoordinationTrigger::executeBySmartDocumentId(
					$itemIdentifier->getEntityId(),
					$this->buildTriggerInputData($eventType, $eventData, $documentData),
				);
				break;
			case EventData::TYPE_ON_READY_BY_EDITOR:
				$crmController->onSignStarted($itemIdentifier, $documentData);
				FillingTrigger::executeBySmartDocumentId(
					$itemIdentifier->getEntityId(),
					$this->buildTriggerInputData($eventType, $eventData, $documentData),
				);
				break;
			case EventData::TYPE_ON_DELIVERED:
				$crmController->onMessageDelivered($itemIdentifier, $documentData, $messageData);
				$this->notifyActivityChange($crmController, $itemIdentifier);
				break;
			case EventData::TYPE_ON_DELIVERY_ERROR:
				$crmController->onDeliveryError($itemIdentifier, $documentData, $messageData);
				break;
			case EventData::TYPE_ON_ERROR_SIGNING_EXPIRED:
				$crmController->onMemberSigningExpiredError($itemIdentifier, $documentData, $messageData);
				$this->notifyActivityChange($crmController, $itemIdentifier);
				break;
			case EventData::TYPE_ON_ERROR_SNILS_NOT_FOUND:
				$crmController->onMemberSnilsError($itemIdentifier, $documentData, $messageData);
				$this->notifyActivityChange($crmController, $itemIdentifier);
				break;
			case EventData::TYPE_ON_ERROR_REQUEST_ERROR:
				$crmController->onMemberSigningError($itemIdentifier, $documentData, $messageData);
				$this->notifyActivityChange($crmController, $itemIdentifier);
				break;
			case EventData::TYPE_ON_ERROR_DOCUMENT_PREPARATION_FAILED:
				if (method_exists($crmController, 'onMemberDocumentPreparationFailed'))
				{
					$crmController->onMemberDocumentPreparationFailed($itemIdentifier, $documentData, $messageData);
				}
				$this->notifyActivityChange($crmController, $itemIdentifier);
				break;
			case EventData::TYPE_ON_MEMBER_STOPPED_BY_ASSIGNEE:
				if (method_exists($crmController, 'onMemberStoppedByAssignee'))
				{
					$crmController->onMemberStoppedByAssignee($itemIdentifier, $documentData, $messageData);
				}
				$this->notifyActivityChange($crmController, $itemIdentifier);
				break;
			case EventData::TYPE_ON_MEMBER_STOPPED_BY_REVIEWER:
				if (method_exists($crmController, 'onMemberStoppedByReviewer'))
				{
					$crmController->onMemberStoppedByReviewer($itemIdentifier, $documentData, $messageData);
				}
				$this->notifyActivityChange($crmController, $itemIdentifier);
				break;
			case EventData::TYPE_ON_MEMBER_STOPPED_BY_EDITOR:
				if (method_exists($crmController, 'onMemberStoppedByEditor'))
				{
					$crmController->onMemberStoppedByEditor($itemIdentifier, $documentData, $messageData);
				}
				$this->notifyActivityChange($crmController, $itemIdentifier);
				break;
			case EventData::TYPE_ON_MEMBER_SIGNED_DELIVERED:
				if (method_exists($crmController, 'onSignedDocumentDelivered'))
				{
					$crmController->onSignedDocumentDelivered($itemIdentifier, $documentData);
				}
				break;
			case EventData::TYPE_ON_ANNULLED:
				if (method_exists($crmController, 'onAnnulled'))
				{
					$crmController->onAnnulled($itemIdentifier, $documentData);
				}
				$this->notifyActivityChange($crmController, $itemIdentifier);
				break;
			case EventData::TYPE_ON_ANNULMENT_CANCELED:
				if (method_exists($crmController, 'onAnnulmentCanceled'))
				{
					$crmController->onAnnulmentCanceled($itemIdentifier, $documentData);
				}
				$this->notifyActivityChange($crmController, $itemIdentifier);
				break;
			case EventData::TYPE_ON_CONFIGURATION_ERROR:
				if (method_exists($crmController, 'onSignConfigureError'))
				{
					$crmController->onSignConfigureError($itemIdentifier, $documentData, $messageData);
				}
				break;
			case EventData::TYPE_ON_SENDING:
			case EventData::TYPE_ON_ASSIGNEE_DONE:
				$this->notifyActivityChange($crmController, $itemIdentifier);
				break;
		}
	}

	private function getLogger(): Logger
	{
		return $this->logger ??= Container::instance()->getLogger();
	}

	private function buildTriggerInputData(string $eventType, EventData $eventData, DocumentData $documentData): array
	{
		$inputData = [
			'eventType' => $eventType,
			'signDocumentId' => $documentData->getDocumentId(),
			'signInitiatedByType' => $documentData->getInitiatedByType(),
		];

		$memberService = Container::instance()->getMemberService();
		$documentItem = $eventData->getDocumentItem();
		$memberItem = $this->resolveMemberItem($eventType, $eventData, $documentItem, $memberService);
		$signerUserId = null;
		if ($memberItem)
		{
			$inputData['signMemberRole'] = (string)$memberItem->role;

			$signerUserId = $memberService->getUserIdForMember($memberItem, $documentItem);
			if ($signerUserId)
			{
				$inputData['signerUserId'] = $signerUserId;
			}

			$signerName = $memberService->getMemberRepresentedNameForTriggerPayload(
				$memberItem,
				$documentItem,
				$signerUserId,
			);
			if ($signerName)
			{
				$inputData['signerName'] = $signerName;
			}
		}

		// the event names an initiator only when a member was changed by someone else (a member stop);
		// on its own status change the member who acted is the member itself
		$initiatorUserId = $documentData->getInitiatorUserId() ?? $signerUserId;
		if ($initiatorUserId)
		{
			$inputData['initiatorUserId'] = $initiatorUserId;
		}

		return $inputData;
	}

	private function resolveMemberItem(
		string $eventType,
		EventData $eventData,
		?Document $documentItem,
		MemberService $memberService,
	): ?Member
	{
		$memberItem = $eventData->getMemberItem();
		if ($memberItem)
		{
			return $memberItem;
		}

		if (!$documentItem)
		{
			return null;
		}

		$documentId = $documentItem->id;

		return match ($eventType)
		{
			EventData::TYPE_ON_SIGNED_BY_EMPLOYEE,
			EventData::TYPE_ON_SIGNED_BY_RESPONSIBILITY_PERSON,
			EventData::TYPE_ON_DONE => $memberService->getSigner($documentItem),
			EventData::TYPE_ON_READY_BY_REVIEWER => $memberService->getByDocumentIdWithRole($documentId, Role::REVIEWER),
			EventData::TYPE_ON_READY_BY_EDITOR => $memberService->getByDocumentIdWithRole($documentId, Role::EDITOR),
			default => null,
		};
	}

	private function createValidationEvent(
		B2eController $crmController,
		DocumentData $documentData,
		ItemIdentifier $itemIdentifier,
		string $eventType,
		EventData $eventData,
		?MessageData $messageData,
	): void
	{
		$dispatch = function (EventData $data, bool $eligible) use (
			$crmController, $documentData, $itemIdentifier, $eventType, $messageData,
		): void {
			if ($eventType === EventData::TYPE_ON_SIGNED_BY_REVIEWER)
			{
				$crmController->onSignedByReviewer($itemIdentifier, $documentData, $messageData);
			}
			else
			{
				$crmController->onSignedByEditor($itemIdentifier, $documentData, $messageData);
			}
			if ($eligible)
			{
				$this->dispatchValidationTrigger($documentData, $itemIdentifier, $eventType, $data);
			}
			$this->notifyActivityChange($crmController, $itemIdentifier);
		};
		$memberId = $eventData->getMemberItem()?->id;
		if ($memberId === null)
		{
			$dispatch($eventData, $eventData->isMemberStatusChanged()
				&& $this->isSigningAnnouncedByMemberOfEvent($eventData, $documentData->getDocumentId()));

			return;
		}

		(new MemberValidationCrmDispatchGuard())->run(
			MemberTable::getEntity()->getConnection(),
			$documentData->getDocumentId(),
			$memberId,
			function () use ($eventData, $documentData, $memberId, $dispatch): void {
				[$freshEvent, $eligible] = $this->readValidationEligibility(
					$eventData, $documentData->getDocumentId(), $memberId,
				);
				$dispatch($freshEvent, $eligible);
			},
		);
	}

	private function readValidationEligibility(EventData $eventData, int $documentId, int $memberId): array
	{
		$pool = Application::getInstance()->getConnectionPool();
		$pool->useMasterOnly(true);
		try
		{
			$container = Container::instance();
			$result = $container->getMemberRepository()->getValidationCrmDispatchState($memberId, $documentId);
			$document = $container->getDocumentRepository()->getById($documentId);
			if (!$result->isSuccess() || $document === null)
			{
				throw new \RuntimeException();
			}
			$state = $result->getData();
			$member = $state['member'];
			$fresh = (clone $eventData)->setMemberItem($member)->setDocumentItem($document);
			if (!\Bitrix\Sign\Type\DocumentScenario::isB2eScenarioByDocument($document)
				|| !in_array($member->role, [Role::REVIEWER, Role::EDITOR], true)
				|| $member->status !== \Bitrix\Sign\Type\MemberStatus::DONE)
			{
				return [$fresh, false];
			}
			$last = $state['validators']->getFirst();
			$eligible = $last?->id === $member->id
				&& $state['marker'] !== 'handled'
				&& ($eventData->isMemberStatusChanged()
					|| (!Feature::instance()->isKedoStageReorderEnabled() && $state['marker'] === 'pending'));

			return [$fresh, $eligible];
		}
		catch (\Throwable)
		{
			$this->getLogger()->error('Validation CRM guard failed.', [
				'code' => 'SIGN_VALIDATION_CRM_GUARD_STATE_FAILED', 'phase' => 'state', 'reason' => 'read_failed',
			]);

			throw new SystemException('Validation CRM guard failed.', 25967006);
		}
		finally
		{
			$pool->useMasterOnly(false);
		}
	}

	private function dispatchValidationTrigger(
		DocumentData $documentData,
		ItemIdentifier $itemIdentifier,
		string $eventType,
		EventData $eventData,
	): void
	{
		$member = $eventData->getMemberItem();
		$documentId = $documentData->getDocumentId();
		$memberRepository = Container::instance()->getMemberRepository();

		SigningTrigger::executeBySmartDocumentId(
			$itemIdentifier->getEntityId(),
			$this->buildTriggerInputData($eventType, $eventData, $documentData),
		);
		if ($member?->id === null || !in_array($member->role, [Role::REVIEWER, Role::EDITOR], true))
		{
			return;
		}

		try
		{
			$result = $memberRepository->markValidationCrmDispatchHandled($member->id, $documentId);
			if ($result->isSuccess())
			{
				return;
			}
		}
		catch (\Throwable)
		{
			// Only marker persistence failures are translated; dispatch exceptions propagate.
		}

		$this->getLogger()->error('Validation CRM marker persistence failed.', [
			'code' => 'SIGN_VALIDATION_CRM_MARKER_HANDLED_FAILED',
			'phase' => 'handled',
		]);

		throw new SystemException('Validation CRM marker persistence failed.', 25967002);
	}

	/**
	 * An event that names no member of its own, and a document whose composition names no validation
	 * member, are both left announcing as they did before the member was asked about.
	 */
	private function isSigningAnnouncedByMemberOfEvent(EventData $eventData, int $documentId): bool
	{
		$eventMemberId = $eventData->getMemberItem()?->id;
		$lastValidationMemberId = Container::instance()
			->getMemberRepository()
			->getLastValidationMemberId($documentId)
		;

		if ($eventMemberId === null || $lastValidationMemberId === null)
		{
			return true;
		}

		return $eventMemberId === $lastValidationMemberId;
	}

	private function executeTriggerOnStart(
		DocumentData $documentData,
		ItemIdentifier $itemIdentifier,
		string $eventType,
		EventData $eventData,
	): void
	{
		$initiatedByType = InitiatedByType::tryFrom($documentData->getInitiatedByType());
		if ($initiatedByType !== InitiatedByType::COMPANY)
		{
			return;
		}

		$hasReviewer = Container::instance()
			->getMemberRepository()
			->isDocumentHasReviewer($documentData->getDocumentId())
		;

		$hasEditor = Container::instance()
			->getMemberRepository()
			->isDocumentHasEditor($documentData->getDocumentId())
		;

		if (!$hasReviewer && !$hasEditor)
		{
			SigningTrigger::executeBySmartDocumentId(
				$itemIdentifier->getEntityId(),
				$this->buildTriggerInputData($eventType, $eventData, $documentData),
			);
		}
	}

	private function executeTriggerOnSignedByEmployee(
		DocumentData $documentData,
		ItemIdentifier $itemIdentifier,
		string $eventType,
		EventData $eventData,
	): void
	{
		$initiatedByType = InitiatedByType::tryFrom($documentData->getInitiatedByType());
		if ($initiatedByType !== InitiatedByType::EMPLOYEE)
		{
			return;
		}

		$hasReviewer = Container::instance()
			->getMemberRepository()
			->isDocumentHasReviewer($documentData->getDocumentId())
		;

		if (!$hasReviewer)
		{
			SigningTrigger::executeBySmartDocumentId(
				$itemIdentifier->getEntityId(),
				$this->buildTriggerInputData($eventType, $eventData, $documentData),
			);
		}
	}

	private function completeActivity(B2eController $b2eController, ItemIdentifier $itemIdentifier): void
	{
		if (method_exists($b2eController, 'completeActivity'))
		{
			$b2eController->completeActivity($itemIdentifier);
		}
	}

	private function notifyActivityChange(B2eController $b2eController, ItemIdentifier $itemIdentifier): void
	{
		if (method_exists($b2eController, 'notifyAboutActivityChange'))
		{
			$b2eController->notifyAboutActivityChange($itemIdentifier);
		}
	}

	private function sendEvent(DocumentData $documentData, string $eventName): void
	{
		foreach (GetModuleEvents('sign', $eventName, true) as $arEvent)
		{
			ExecuteModuleEventEx($arEvent, [$documentData->getDocumentId()]);
		}
	}
}
