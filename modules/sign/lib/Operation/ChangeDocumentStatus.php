<?php

namespace Bitrix\Sign\Operation;

use Bitrix\Sign\Repository\DocumentRepository;
use Bitrix\Sign\Repository\MemberRepository;
use Bitrix\Sign\Service\ChatService;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Service\Counter\B2e\UserToSignDocumentCounterService;
use Bitrix\Sign\Service\Sign\LegalLogService;
use Bitrix\Sign\Type;
use Bitrix\Sign\Contract;
use Bitrix\Sign\Item;
use Bitrix\Main;
use Bitrix\Main\Type\DateTime;
use Bitrix\Sign\Service\Integration\Crm\EventHandlerService;
use Bitrix\Sign\Service\PullService;

final class ChangeDocumentStatus implements Contract\Operation
{
	private DocumentRepository $documentRepository;
	private EventHandlerService $eventHandlerService;
	private PullService $pullService;
	private ChatService $chatService;
	private MemberRepository $memberRepository;
	private LegalLogService $legalLogService;
	private readonly UserToSignDocumentCounterService $b2eUserToSignDocumentCounterService;

	public function __construct(
		private Item\Document $document,
		private string $status,
		private ?DateTime $signDate = null,
		private ?Item\Member $stopInitiatorMember = null,
	)
	{
		$this->documentRepository = Container::instance()->getDocumentRepository();
		$this->eventHandlerService = Container::instance()->getEventHandlerService();
		$this->pullService = Container::instance()->getPullService();
		$this->chatService = Container::instance()->getChatService();
		$this->memberRepository = Container::instance()->getMemberRepository();
		$this->legalLogService = Container::instance()->getLegalLogService();
		$this->b2eUserToSignDocumentCounterService = Container::instance()->getB2eUserToSignDocumentCounterService();
	}

	public function launch(): Main\Result
	{
		$result = new Main\Result();

		if ($this->document->id === null)
		{
			return $result->addError(new Main\Error('Empty document ID.'));
		}

		if (!in_array($this->document->status, Type\DocumentStatus::getAll()))
		{
			return $result->addError(new Main\Error("Unknown document status '{$this->document->status}'"));
		}

		if ($this->document->status === $this->status)
		{
			return $result->addError(new Main\Error('Can\'t update document status.'));
		}

		if ($this->status === Type\DocumentStatus::DONE)
		{
			$signDate = $this->signDate ?? new \DateTime();
			$signDate->setDefaultTimeZone();
			$this->document->dateSign = $signDate;
		}

		$this->document->status = $this->status;
		$updateResult = $this->documentRepository->update($this->document);
		if (!$updateResult->isSuccess())
		{
			return $result->addErrors($updateResult->getErrors());
		}

		if (Type\DocumentScenario::isB2EScenario($this->document->scenario ?? ''))
		{
			$this->legalLogService->registerDocumentChangedStatus($this->document, $this->stopInitiatorMember);
			$sendMessageResult = $this->chatService->handleDocumentStatusChangedMessage($this->document, $this->status, $this->stopInitiatorMember);
			$result->addErrors($sendMessageResult->getErrors());
			$this->eventHandlerService->handleCurrentDocumentStatus($this->document, $this->stopInitiatorMember);
			$members = $this->memberRepository->listByDocumentId($this->document->id);
			foreach ($members->toArray() as $member)
			{
				$this->pullService->sendMemberStatusChanged($this->document, $member);
			}
			if ($this->status === Type\DocumentStatus::STOPPED)
			{
				$this->b2eUserToSignDocumentCounterService->updateByDocument($this->document);
			}
		}

		return $result;
	}
}
