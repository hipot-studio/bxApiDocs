<?php

namespace Bitrix\Sign\Operation;

use Bitrix\Sign\Callback\Messages\Member\MemberStatusChanged;
use Bitrix\Sign\Integration\CRM\Model\EventData;
use Bitrix\Sign\Repository\MemberRepository;
use Bitrix\Sign\Service\ChatService;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Service\Sign\LegalLogService;
use Bitrix\Sign\Type;
use Bitrix\Sign\Contract;
use Bitrix\Sign\Item;
use Bitrix\Main;
use Bitrix\Sign\Service\Integration\Crm\EventHandlerService;
use Bitrix\Sign\Service\PullService;

final class ChangeMemberStatus implements Contract\Operation
{
	private EventHandlerService $eventHandlerService;
	private PullService $pullService;
	private ChatService $chatService;
	private LegalLogService $legalLogService;
	private MemberRepository $memberRepository;
	private ?MemberStatusChanged $message = null;

	public function __construct(
		private readonly Item\Member $member,
		private readonly Item\Document $document,
		private readonly string $status,
	)
	{
		$this->eventHandlerService = Container::instance()->getEventHandlerService();
		$this->pullService = Container::instance()->getPullService();
		$this->chatService = Container::instance()->getChatService();
		$this->memberRepository = Container::instance()->getMemberRepository();
		$this->legalLogService = Container::instance()->getLegalLogService();
	}

	public function setMessage(MemberStatusChanged $message): self
	{
		$this->message = $message;

		return $this;
	}

	public function launch(): Main\Result
	{
		$result = new Main\Result();

		if ($this->member->id === null)
		{
			return $result->addError(new Main\Error('Empty member ID.'));
		}

		if ($this->member->documentId !== $this->document->id)
		{
			return $result->addError(new Main\Error('Wrong document.'));
		}

		if (!in_array($this->member->status, Type\MemberStatus::getAll()))
		{
			return $result->addError(new Main\Error("Unknown member status '{$this->member->status}'"));
		}

		if (
			$this->message === null
			&& $this->member->status === $this->status
		)
		{
			return $result->addError(new Main\Error('You should not update member status.'));
		}

		$this->member->status = $this->status;

		if ($this->member->dateSigned === null && $this->status === Type\MemberStatus::DONE)
		{
			$this->member->dateSigned = new Main\Type\DateTime();
		}

		$updateResult = $this->memberRepository->update($this->member);
		if (!$updateResult->isSuccess())
		{
			return $result->addErrors($updateResult->getErrors());
		}

		if (
			$this->message !== null
			&& Type\DocumentScenario::isB2EScenario($this->document->scenario ?? '')
		)
		{
			$this->legalLogService->registerFromMemberStatusChanged($this->document, $this->member, $this->message);

			$this->eventHandlerService->handleCurrentMemberStatus($this->document, $this->member, $this->message);

			$sendMessageResult = $this->chatService->handleMemberStatusChangedMessage($this->document, $this->member);
			$result->addErrors($sendMessageResult->getErrors());

			if (
				$sendMessageResult->isSuccess()
				&& $this->member->status !== Type\MemberStatus::PROCESSING
			)
			{
				$this->onStatusChangedMessageTimelineEvent();
			}

			$this->pullService->sendMemberStatusChanged($this->document, $this->member);
		}

		return $result;
	}

	private function onStatusChangedMessageTimelineEvent(): void
	{
		$isSignerDone = $this->member->role === Type\Member\Role::SIGNER
			&& $this->member->status === Type\MemberStatus::DONE
		;
		switch(true)
		{
			case $isSignerDone:
			{
				/**
				 * A signed copy of the document was received by the employee
				 * @see \Bitrix\Crm\Timeline\SignB2eDocument\B2eController::onSignedDocumentDelivered
				 */
				$eventType = EventData::TYPE_ON_MEMBER_SIGNED_DELIVERED;
				$eventData = new EventData();
				$eventData->setEventType($eventType)
					->setDocumentItem($this->document)
					->setMemberItem($this->member);
				$this->eventHandlerService->createTimelineEvent($eventData);
				break;
			}
		}
	}
}


