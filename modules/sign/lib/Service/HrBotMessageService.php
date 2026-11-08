<?php

namespace Bitrix\Sign\Service;

use Bitrix\Main\Application;
use Bitrix\Main\Error;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Main\Result;
use Bitrix\Main\Type\DateTime;
use Bitrix\Sign\Config;
use Bitrix\Sign\Contract\Chat\Message;
use Bitrix\Sign\Item\Document;
use Bitrix\Sign\Item\Member;
use Bitrix\Sign\Repository\MemberRepository;
use Bitrix\Sign\Service\Integration\Imbot\HrBot;
use Bitrix\Sign\Service\Sign\UrlGeneratorService;
use Bitrix\Sign\Type;
use Bitrix\Sign\Service\Integration\Im\ImService;
use Bitrix\Sign\Item\Integration\Im;
use Bitrix\Sign\Service\B2e\ReceiptRecipientResolver;
use Bitrix\Sign\Service\Sign\MemberService;
use Bitrix\Sign\Type\Member\Role;
use Bitrix\Sign\Type\ProviderCode;

class HrBotMessageService
{
	private ImService $imService;
	private MemberService $memberService;
	private MemberRepository $memberRepository;
	private Config\Storage $config;
	private UrlGeneratorService $urlGenerator;
	private UserService $userService;

	public function __construct(
		?ImService $imService = null,
		?MemberService $memberService = null,
		?MemberRepository $memberRepository = null,
		?Config\Storage $config = null,
		?UrlGeneratorService $urlGenerator = null,
		?UserService $userService = null,
	)
	{
		$this->imService = $imService ?? Container::instance()->getImService();
		$this->memberService = $memberService ?? Container::instance()->getMemberService();
		$this->memberRepository = $memberRepository ?? Container::instance()->getMemberRepository();
		$this->urlGenerator = $urlGenerator ?? Container::instance()->getUrlGeneratorService();
		$this->userService = $userService ?? Container::instance()->getUserService();
		$this->config = $config ?? Config\Storage::instance();
	}

	/**
	 * @throws ObjectNotFoundException
	 */
	public function sendInviteMessage(Document $document, Member $member, string $providerCode): Result
	{
		$message = $this->isByEmployee($document)
			? $this->createByEmployeeInviteMessage($document, $member)
			: $this->createByCompanyInviteMessage($document, $member, $providerCode)
		;

		if ($message !== null)
		{
			$message->setLang($this->userService->getUserLanguage($message->getUserTo()));
			return $this->imService->sendMessage($message);
		}

		return new Result();
	}

	/**
	 * SC-002: sends the company-side invitation of an employee-initiated document when the caller registers
	 * the receipt mark right after it. The mark states that the company received the document, so it follows
	 * a really sent invitation and replaces the "signed by employee" message suppressed on the
	 * SIGNER -> DONE transition. When the invitation cannot be built, no mark follows it, so that suppressed
	 * message is delivered here and the failure is rethrown to be handled as any invitation failure.
	 *
	 * @throws ObjectNotFoundException
	 */
	public function sendInviteMessageExpectingCompanyReceipt(
		Document $document,
		Member $assignee,
		string $providerCode,
	): Result
	{
		try
		{
			return $this->sendInviteMessage($document, $assignee, $providerCode);
		}
		catch (ObjectNotFoundException $exception)
		{
			$this->sendByEmployeeSignedFallbackMessage($document);

			throw $exception;
		}
	}

	public function handleDocumentStatusChangedMessage(Document $document, string $newStatus, ?int $initiatorUserId = null): Result
	{
		if ($this->isByEmployee($document))
		{
			switch ($newStatus)
			{
				case Type\DocumentStatus::STOPPED:
					return $this->handleByEmployeeDocumentStoppedStatus($document, $initiatorUserId);
				case Type\DocumentStatus::DONE:
					$result = $this->byEmployeeSendDoneMessageToEmployee($document);
					$result->addErrors(
						$this->byEmployeeSendDoneMessageToCompany($document)->getErrors()
					);
					return $result;
			}

			return new Result();
		}

		switch ($newStatus)
		{
			case Type\DocumentStatus::STOPPED:
				return $this->handleByCompanyDocumentStoppedStatus($document, $initiatorUserId);

			case Type\DocumentStatus::DONE:
				$signedDone = $this->memberService->countSuccessfulSigners($document->id);
				if ($signedDone)
				{
					$userFrom = $this->getBotUserId() ?? $document->representativeId;
					$userTo = $document->createdById;
					return $this->sendDoneMessageToCompany($userFrom, $userTo, $document);
				}
				return new Result();
		}

		return new Result();
	}

	public function handleMemberStatusChangedMessage(Document $document, Member $member): Result
	{
		if ($this->isByEmployee($document))
		{
			switch (true)
			{
				case $member->role === Role::SIGNER && $member->status === Type\MemberStatus::DONE:
					// SC-002: employee is notified when the company receives the document
					// (on the assignee invitation), not on the "sent" event.
					if ($this->isReceiptMarkExpectedForCompanySide($document))
					{
						return new Result();
					}

					$userIdFrom = $this->getBotUserId() ?? $document->representativeId;
					$userIdTo = $this->memberService->getUserIdForMember($member);
					return $this->byEmployeeSendEmployeeSignedMessageToEmployee($userIdFrom, $userIdTo, $document, $member);
			}

			return new Result();
		}

		$memberUserId = $this->memberService->getUserIdForMember($member);

		switch ($member->role)
		{
			case Type\Member\Role::SIGNER:
				return match ($member->status) {
					Type\MemberStatus::STOPPED => $this->handleEmployeeStoppedStatus($document, $member),
					Type\MemberStatus::REFUSED => $memberUserId !== $document->createdById
						? $this->sendRefusedMessage($memberUserId, $document->createdById, $document)
						: new Result()
					,
					// SC-001: the "document signed" message is sent on the result-file save (receipt) event
					// instead of on this status change.
					Type\MemberStatus::DONE => new Result(),
					default => new Result(),
				};
		}

		return new Result();
	}

	/**
	 * Impersonal notice about a document that can no longer be prepared for signing.
	 * Recipients do not depend on the failed member role: preparation fails for the whole document.
	 */
	public function notifyDocumentPreparationFailed(Document $document): Result
	{
		if (!HrBot::isAvailable())
		{
			return (new Result())->addError(new Error('Chat is not available'));
		}

		$userIdFrom = $this->getBotUserId() ?? $document->createdById;
		if (!$userIdFrom)
		{
			return (new Result())->addError(new Error('Sender of the document preparation failed message not found'));
		}

		$result = new Result();
		$link = $this->urlGenerator->getSigningProcessLink($document);

		foreach ($this->getDocumentPreparationFailedRecipients($document) as $userIdTo)
		{
			$result->addErrors(
				$this->sendDocumentPreparationFailedMessage($userIdFrom, $userIdTo, $document, $link)->getErrors()
			);
		}

		return $result;
	}

	public function repeatSigningOnErrors(Document $document, Member $assignee): Result
	{
		$result = new Result();

		$assigneeUserId = $this->memberService->getUserIdForMember($assignee);
		$botUserId = $this->getBotUserId();

		// invite assignee to repeat signing
		$userIdFrom = $botUserId ?? $document->createdById;
		$result->addErrors(
			($this->sendErrorMessageToAssignee($userIdFrom, $assigneeUserId, $assignee, $document))->getErrors()
		);

		// notify initiator if it is another user
		if ($document->createdById !== $assigneeUserId)
		{
			$userIdFrom = $botUserId ?? $assigneeUserId;
			$result->addErrors(
				($this->sendErrorMessageToInitiator($userIdFrom, $document->createdById, $assignee, $document))->getErrors()
			);
		}

		return $result;
	}

	/**
	 * Resolves the recipients of the annulment/un-annulment HR-bot card for one
	 * signer member and sends the card to each of them (ALG-02).
	 *
	 * Two independent recipients, each actor-gated: the signing employee and the
	 * document initiator (createdById - always a live person: the manual initiator
	 * or the Responsible of a robot/activity/1C launch). The actor is always
	 * excluded. When the employee equals the initiator, only the initiator card is
	 * sent: the two would be full duplicates - same recipient, same single signing -
	 * and the initiator card is the one that keeps the link to the signing process,
	 * which this recipient can open on their own document. All arguments are passed
	 * explicitly; the method never reads "who is in the request".
	 */
	public function handleMemberAnnulled(Document $document, Member $signer, bool $annul, int $actorUserId): Result
	{
		$result = new Result();

		$employeeUserId = $this->memberService->getUserIdForMember($signer, $document);
		$employeeIsInitiator = $employeeUserId !== null && $employeeUserId === $document->createdById;

		if (!$employeeIsInitiator)
		{
			$result->addErrors(
				$this->sendMemberAnnulledToEmployee($document, $signer, $annul, $actorUserId)->getErrors()
			);
		}

		$result->addErrors(
			$this->sendMemberAnnulledToInitiator($document, $annul, $actorUserId)->getErrors()
		);

		return $result;
	}

	/**
	 * Same as handleMemberAnnulled() for a set of signer records of one document,
	 * changed by one action: a card per employee plus a single aggregated card to
	 * the initiator, never one per record. The initiator card reports how many
	 * signings the action changed. When the two would duplicate each other - one
	 * changed signing whose single recipient is the initiator - the employee card
	 * is the one dropped, so the recipient keeps the card with the link to the
	 * signing process of their own document. That recipient is known only after the
	 * loop, so their employee card waits for the decision instead of going out.
	 *
	 * The employee fan-out is inherent - every employee has their own chat with the
	 * bot, so N changed records mean N cards - and it is what makes this delivery
	 * worth deferring (see scheduleMembersAnnulled()). Delivery is best-effort per
	 * recipient: a failing or throwing send is collected into the result and never
	 * stops the remaining cards.
	 *
	 * @param iterable<Member> $signers Records actually changed by the action.
	 * @param int|null $annulledCount Signings the action changed, for the initiator
	 *        card. Pass it when the exact number is known from the write itself (a
	 *        conditional UPDATE reports it); otherwise the size of the set is used.
	 */
	public function handleMembersAnnulled(
		Document $document,
		iterable $signers,
		bool $annul,
		int $actorUserId,
		?int $annulledCount = null,
	): Result
	{
		$result = new Result();

		// Invariants of the whole action: the same actor and the same document for
		// every card. Resolved on the first recipient and reused, so the cost does
		// not grow with the fan-out and an empty set resolves nothing; kept inside
		// the per-recipient try, so a failing resolve is collected like a failing
		// send instead of escaping the deferred job.
		$actorName = null;
		$link = null;
		$signerCount = 0;
		$recipients = [];
		$initiatorOwnSigners = [];

		foreach ($signers as $signer)
		{
			// Counts the changed records, not the delivered cards: the actor's own
			// signing is annulled just like the rest, it only gets no card.
			$signerCount++;

			try
			{
				$actorName ??= $this->memberService->getUserRepresentedName($actorUserId);
				$link ??= $this->urlGenerator->getSigningProcessLink($document);

				// Collected before the delivery attempt: a failing send must not
				// shrink the set and switch the deduplication below on.
				$recipientId = $this->memberService->getUserIdForMember($signer, $document);
				if ($recipientId !== null)
				{
					$recipients[$recipientId] = true;
				}

				if ($recipientId !== null && $recipientId === $document->createdById)
				{
					// Held back: whether these cards duplicate the aggregated one is
					// decided below, once the whole set is known. Every record is kept,
					// not just the first one: one user can hold several signings of the
					// same document, and each of them is its own card.
					$initiatorOwnSigners[] = $signer;

					continue;
				}

				$result->addErrors(
					$this
						->sendMemberAnnulledToEmployee($document, $signer, $annul, $actorUserId, $actorName, $link)
						->getErrors()
				);
			}
			catch (\Throwable $e)
			{
				$result->addError(new Error($e->getMessage()));
			}
		}

		$annulledCount ??= $signerCount;
		if ($annulledCount <= 0)
		{
			// Nothing changed, so there is nothing to report to the initiator.
			return $result;
		}

		$soleRecipientId = count($recipients) === 1 ? array_key_first($recipients) : null;
		$heldCardDuplicatesAggregated =
			$annulledCount === 1
			&& $soleRecipientId !== null
			&& $document->createdById !== null
			&& $soleRecipientId === $document->createdById
		;

		if (!$heldCardDuplicatesAggregated)
		{
			// The aggregated card reports other signings too, so the initiator still
			// needs the personal cards about their own ones.
			foreach ($initiatorOwnSigners as $heldSigner)
			{
				try
				{
					$result->addErrors(
						$this
							->sendMemberAnnulledToEmployee(
								$document,
								$heldSigner,
								$annul,
								$actorUserId,
								$actorName,
								$link,
							)
							->getErrors()
					);
				}
				catch (\Throwable $e)
				{
					$result->addError(new Error($e->getMessage()));
				}
			}
		}

		try
		{
			$result->addErrors(
				$this
					->sendMemberAnnulledToInitiator($document, $annul, $actorUserId, $annulledCount, $actorName, $link)
					->getErrors()
			);
		}
		catch (\Throwable $e)
		{
			$result->addError(new Error($e->getMessage()));
		}

		return $result;
	}

	/**
	 * Defers handleMembersAnnulled() to a background job of the current request, so
	 * a mass action returns without waiting for its chat fan-out.
	 *
	 * The job runs after the response is flushed, in the same process, on the record
	 * set the action itself resolved - nothing is persisted in between, so delivery
	 * stays at-most-once: a process that dies before the job drops the cards instead
	 * of duplicating them. Consistent with the rest of the annulment side effects, a
	 * failed delivery never rolls back the persisted mark; it is only logged.
	 *
	 * @param iterable<Member> $signers Records actually changed by the action.
	 * @param int|null $annulledCount Signings the action changed, see
	 *        handleMembersAnnulled().
	 */
	public function scheduleMembersAnnulled(
		Document $document,
		iterable $signers,
		bool $annul,
		int $actorUserId,
		?int $annulledCount = null,
	): void
	{
		Application::getInstance()->addBackgroundJob(
			function () use ($document, $signers, $annul, $actorUserId, $annulledCount): void
			{
				$result = $this->handleMembersAnnulled($document, $signers, $annul, $actorUserId, $annulledCount);
				if (!$result->isSuccess())
				{
					Container::instance()->getLogger('Service')->error(
						'Annulment cards for document {documentId} partially failed: {errorsText}',
						[
							'documentId' => $document->id,
							'errorsText' => implode('|| ', $result->getErrorMessages()),
						],
					);
				}
			},
		);
	}

	/**
	 * Sends the annulment/un-annulment card to the signing employee, unless the
	 * employee is the actor. Building block of ALG-02, reused by the inline single
	 * path and by the deferred batch fan-out (per-member delivery).
	 *
	 * $actorName and $link are the same for every card of one action, so a fan-out
	 * resolves them once and passes them in; a single-card caller may omit them.
	 */
	public function sendMemberAnnulledToEmployee(
		Document $document,
		Member $signer,
		bool $annul,
		int $actorUserId,
		?string $actorName = null,
		?string $link = null,
	): Result
	{
		$signerUserId = $this->memberService->getUserIdForMember($signer, $document);
		if ($signerUserId === null || $signerUserId === $actorUserId)
		{
			return new Result();
		}

		return $this->sendMemberAnnulledMessage(
			$document,
			$signerUserId,
			$annul,
			$actorUserId,
			toInitiator: false,
			actorName: $actorName,
			link: $link,
		);
	}

	/**
	 * Sends the annulment/un-annulment card to the document initiator
	 * (createdById), unless the initiator is the actor. Building block of ALG-02;
	 * a batch calls it once per document (one aggregated card).
	 *
	 * @param int|null $annulledCount Signings changed by the action. Above one the
	 *        card reports the number, so an aggregated card is not read as a single
	 *        annulment; null or one keeps the single-signing wording.
	 */
	public function sendMemberAnnulledToInitiator(
		Document $document,
		bool $annul,
		int $actorUserId,
		?int $annulledCount = null,
		?string $actorName = null,
		?string $link = null,
	): Result
	{
		$initiatorUserId = $document->createdById;
		if ($initiatorUserId === null || $initiatorUserId === $actorUserId)
		{
			return new Result();
		}

		return $this->sendMemberAnnulledMessage(
			$document,
			$initiatorUserId,
			$annul,
			$actorUserId,
			toInitiator: true,
			actorName: $actorName,
			link: $link,
			annulledCount: $annulledCount,
		);
	}

	private function sendMemberAnnulledMessage(
		Document $document,
		int $userIdTo,
		bool $annul,
		int $actorUserId,
		bool $toInitiator,
		?string $actorName = null,
		?string $link = null,
		?int $annulledCount = null,
	): Result
	{
		$userIdFrom = $this->getBotUserId() ?? $actorUserId;
		$actorName ??= $this->memberService->getUserRepresentedName($actorUserId);
		$link ??= $this->urlGenerator->getSigningProcessLink($document);

		$message = $annul
			? new Im\Messages\Failure\DocumentAnnulled(
				fromUser: $userIdFrom,
				toUser: $userIdTo,
				initiatorUserId: $actorUserId,
				initiatorName: $actorName,
				document: $document,
				link: $link,
				toInitiator: $toInitiator,
				annulledCount: $annulledCount,
			)
			: new Im\Messages\Failure\AnnulmentCanceled(
				fromUser: $userIdFrom,
				toUser: $userIdTo,
				initiatorUserId: $actorUserId,
				initiatorName: $actorName,
				document: $document,
				link: $link,
				toInitiator: $toInitiator,
				annulledCount: $annulledCount,
			)
		;

		return $this->imService->sendMessage(
			$message->setLang($this->userService->getUserLanguage($userIdTo))
		);
	}

	/**
	 * SC-002: notifies the employee that the company received the document initiated by the employee.
	 * The message is addressed to the employee ($document->createdById); the name shown in the card
	 * is the company side that received the document, resolved from the invited assignee.
	 */
	public function handleCompanyReceivedByEmployeeDocument(Document $document, Member $assignee): Result
	{
		$recipient = (new ReceiptRecipientResolver($this->memberService))->resolveRecipient(
			$document,
			$assignee,
			Type\B2e\ReceiptScenario::EmployeeInitiated,
		);

		if ($recipient === null)
		{
			// N7 fallback: the company-side recipient could not be resolved (e.g. empty display name), but
			// the old "signed by employee" message was already suppressed on the SIGNER -> DONE transition.
			// Deliver that previous message (without a receipt line) so the employee is never left silent.
			return $this->sendByEmployeeSignedFallbackMessage($document);
		}

		$userFrom = $this->getBotUserId() ?? $document->representativeId;
		$userTo = $document->createdById;

		if (!$userFrom || !$userTo)
		{
			return new Result();
		}

		// The employee opens the document by their own signing link, as in the replaced "signed by employee"
		// message, which is not sent either when the signer does not resolve.
		$signer = $this->memberService->getSigner($document);
		if ($signer === null)
		{
			return (new Result())->addError(new Error('Signer not found'));
		}

		return $this->imService->sendMessage(
			(new Im\Messages\ByEmployee\ReceivedByCompany(
				fromUser: $userFrom,
				toUser: $userTo,
				recipientUserId: $recipient->id,
				recipientName: $recipient->name,
				document: $document,
				link: $this->urlGenerator->makeSigningUrl($signer),
			))->setLang($this->userService->getUserLanguage($userTo))
		);
	}

	/**
	 * SC-001: notifies the employee signer that the company-initiated document is signed and that the
	 * company side received the signed result file. The message is addressed to the employee signer;
	 * the name shown in the card is the company side ($document->createdById) that received the document.
	 */
	public function handleCompanyReceivedSignedByCompanyDocument(Document $document, Member $signerMember): Result
	{
		$recipient = (new ReceiptRecipientResolver($this->memberService))->resolveRecipient(
			$document,
			$signerMember,
			Type\B2e\ReceiptScenario::CompanyInitiated,
		);

		if ($recipient === null)
		{
			// N7 fallback: the receiver could not be resolved (e.g. empty display name), but the old
			// "document signed" message was already suppressed on the SIGNER -> DONE transition. Deliver
			// that previous message (without a receipt line) so the employee signer is never left silent.
			$userFrom = $this->getBotUserId() ?? $document->createdById;
			if (!$userFrom)
			{
				return new Result();
			}

			return $this->sendDoneMessageToEmployee($userFrom, $signerMember, $document);
		}

		$userFrom = $this->getBotUserId() ?? $document->createdById;
		$userTo = $this->memberService->getUserIdForMember($signerMember);

		if (!$userFrom || !$userTo)
		{
			return new Result();
		}

		return $this->imService->sendMessage(
			(new Im\Messages\ByCompany\ReceivedSignedByCompany(
				fromUser: $userFrom,
				toUser: $userTo,
				recipientUserId: $recipient->id,
				recipientName: $recipient->name,
				document: $document,
				link: $this->urlGenerator->makeSigningUrl($signerMember),
			))->setLang($this->userService->getUserLanguage($userTo))
		);
	}

	/**
	 * @throws ObjectNotFoundException
	 */
	private function createByCompanyInviteMessage(Document $document, Member $member, string $providerCode): ?Message
	{
		$userIdFrom = $this->getBotUserId() ?? $document->createdById;
		$userIdTo = $this->memberService->getUserIdForMember($member);
		$signingLink = $this->urlGenerator->makeSigningUrl($member);

		if (!$userIdTo || !$userIdFrom)
		{
			throw new ObjectNotFoundException('hrbot: no such user');
		}

		$initiatorUserId = $document->createdById;
		$initiatorName = $this->memberService->getUserRepresentedName($initiatorUserId);

		$message = match ($member->role)
		{
			Role::ASSIGNEE => $userIdTo !== $document->createdById
				? new Im\Messages\InviteToSign\CompanyWithInitiator($userIdFrom, $userIdTo, $initiatorUserId, $initiatorName, $document, $signingLink)
				: new Im\Messages\InviteToSign\Company($userIdFrom, $userIdTo, $document, $signingLink)
		,
			Role::REVIEWER => $userIdTo !== $document->createdById
				? new Im\Messages\InviteToSign\ReviewerWithInitiator($userIdFrom, $userIdTo, $initiatorUserId, $initiatorName, $document, $signingLink)
				: new Im\Messages\InviteToSign\Reviewer($userIdFrom, $userIdTo, $document, $signingLink)
		,
			Role::EDITOR => $userIdTo !== $document->createdById
				? new Im\Messages\InviteToSign\EditorWithInitiator($userIdFrom, $userIdTo, $initiatorUserId, $initiatorName, $document, $signingLink)
				: new Im\Messages\InviteToSign\Editor($userIdFrom, $userIdTo, $document, $signingLink)
		,
			Role::SIGNER => match ($providerCode)
			{
				ProviderCode::GOS_KEY => new Im\Messages\InviteToSign\Goskey($userIdFrom, $userIdTo, $initiatorUserId, $initiatorName, $document, $signingLink, $document->inviteMessage),
				default => new Im\Messages\InviteToSign\EmployeeSes($userIdFrom, $userIdTo, $initiatorUserId, $initiatorName, $document, $signingLink, $document->inviteMessage),
			},
			default => null,
		};

		// The card carries the member record it is addressed to, so that the signing domain can find
		// it again by that key and repaint it once this participant is no longer expected to act.
		return $message?->setMember($member);
	}

	/**
	 * @throws ObjectNotFoundException
	 */
	private function createByEmployeeInviteMessage(Document $document, Member $member): ?Message
	{
		$userIdFrom = $this->getBotUserId() ?? $document->createdById;
		$userIdTo = $this->memberService->getUserIdForMember($member);

		$link = $this->urlGenerator->makeSigningUrl($member);

		if (!$userIdTo || !$userIdFrom)
		{
			throw new ObjectNotFoundException('hrbot: no such user');
		}

		$initiatorUserId = $document->createdById;
		$initiatorName = $this->memberService->getUserRepresentedName($initiatorUserId);

		return match ($member->role)
		{
			Role::ASSIGNEE => new Im\Messages\ByEmployee\InviteCompany($userIdFrom, $userIdTo, $initiatorUserId, $initiatorName, $document, $link),
			Role::REVIEWER => new Im\Messages\ByEmployee\InviteReviewer($userIdFrom, $userIdTo, $initiatorUserId, $initiatorName, $document, $link),
			Role::SIGNER => new Im\Messages\ByEmployee\InviteEmployee($userIdFrom, $userIdTo, $document, $link),
			default => null,
		};
	}

	private function handleByEmployeeDocumentStoppedStatus(Document $document, ?int $stopInitiatorUserId): Result
	{
		$result = new Result();

		$assigneeUserId = $this->getAssigneeUserId($document);

		$userFrom =
			$this->getBotUserId()
			?? $stopInitiatorUserId
			?? $assigneeUserId
		;

		if (!$userFrom)
		{
			return (new Result())->addError(new Error('Sender of the document stop message not found'));
		}

		$employeeUser = $this->getEmployeeUserIdFromDocumentByEmployee($document);

		if (!$employeeUser)
		{
			return (new Result())->addError(new Error('Employee user ID not found'));
		}

		$isExpired = $this->isDocumentExpired($document);

		// message to employee
		if ($isExpired)
		{
			$result->addErrors($this->sendByEmployeeDocumentExpiredToEmployeeMessage(
				$userFrom,
				$employeeUser,
				$document,
				$assigneeUserId ?? $userFrom,
			)->getErrors());
		}
		elseif ($stopInitiatorUserId !== $document->createdById)
		{
			// the initiator is passed as is: the card names whoever stopped the document, and substituting
			// the assignee here told the employee that their own document was refused by a colleague
			$result->addErrors($this->sendByEmployeeStoppedToEmployeeMessage(
				$userFrom,
				$employeeUser,
				$document,
				$stopInitiatorUserId,
			)->getErrors());
		}

		// message to active member from company side
		$memberFromCompanySide = $this->memberService->getCurrentParticipantFromCompanySide($document);
		if ($memberFromCompanySide && $this->isMemberStillDoingHisJob($memberFromCompanySide, $document))
		{
			$userTo = $this->getActiveReviewerOrAssigneeUserId($document, $memberFromCompanySide);
			if ($userTo && $userTo !== $employeeUser)
			{
				if ($isExpired)
				{
					$result->addErrors($this->sendExpiredMessageToCompany($userFrom, $userTo, $document)->getErrors());
				}
				elseif ($userTo !== $stopInitiatorUserId)
				{
					$result->addErrors(
						$this->sendStoppedMessageToCompany($userFrom, $userTo, $document, $stopInitiatorUserId, $memberFromCompanySide?->role)->getErrors(),
					);
				}
			}
		}

		return $result;
	}

	private function handleByCompanyDocumentStoppedStatus(Document $document, ?int $stopInitiatorUserId = null): Result
	{
		$result = new Result();

		$userFrom =
			$this->getBotUserId()
			?? $stopInitiatorUserId
			?? $document->createdById
		;

		// message to initiator
		if ($this->isDocumentExpired($document))
		{
			$result->addErrors($this->sendExpiredMessageToCompany($userFrom, $document->createdById, $document)->getErrors());
		}
		elseif ($document->createdById !== $stopInitiatorUserId)
		{
			$result->addErrors(
				$this->sendStoppedMessageToCompany(
					$userFrom,
					$document->createdById,
					$document,
					$stopInitiatorUserId,
					null
				)->getErrors()
			);
		}

		// message to active member from company side
		$memberFromCompanySide = $this->memberService->getCurrentParticipantFromCompanySide($document);
		if ($memberFromCompanySide && $this->isMemberStillDoingHisJob($memberFromCompanySide, $document))
		{
			$userTo = $this->getActiveReviewerOrAssigneeUserId($document, $memberFromCompanySide);
			if ($userTo && $userTo !== $stopInitiatorUserId && $userTo !== $document->createdById)
			{
				$result->addErrors(
					$this->sendStoppedMessageToCompany($userFrom, $userTo, $document, $stopInitiatorUserId, $memberFromCompanySide?->role)->getErrors(),
				);
			}
		}

		// message for employees
		$signers = $this->memberRepository->listByDocumentIdAndRoleAndStatus(
			$document->id,
			Role::SIGNER,
			0,
			[Type\MemberStatus::STOPPABLE_READY],
		);
		foreach ($signers as $member)
		{
			$userTo = $this->memberService->getUserIdForMember($member);

			if ($userTo === $stopInitiatorUserId)
			{
				continue;
			}

			$result->addErrors(
				$this->sendStoppedToEmployeeMessage(
					$userFrom,
					$userTo,
					$document,
					$stopInitiatorUserId,
				)->getErrors()
			);
		}

		return $result;
	}

	private function isMemberStillDoingHisJob(Member $member, Document $document): bool
	{
		// logic for asignee with goskey
		if (
			$document->providerCode === ProviderCode::GOS_KEY
			&& $member->role === Role::ASSIGNEE
			&& $member->status === Type\MemberStatus::READY
		)
		{
			return $this->memberService->countWaitingSigners($document->id) > 0;
		}

		return in_array(
			$member->status,
			Type\MemberStatus::getStatusesNotFinished(),
			true
		);
	}

	private function sendErrorMessageToInitiator(int $userIdFrom, int $userIdTo, Member $assignee, Document $document): Result
	{
		return $this->imService->sendMessage(
			(new Im\Messages\Failure\SigningError(
				fromUser: $userIdFrom,
				toUser: $userIdTo,
				document: $document,
				member: $assignee,
				link: $this->urlGenerator->getSigningProcessLink($document),
			))->setLang($this->userService->getUserLanguage($userIdTo))
		);
	}

	/**
	 * invite to re-sign
	 */
	private function sendErrorMessageToAssignee(int $userIdFrom, int $userIdTo, Member $assignee, Document $document): Result
	{
		return $this->imService->sendMessage(
			(new Im\Messages\Failure\RepeatSigning(
				fromUser: $userIdFrom,
				toUser: $userIdTo,
				document: $document,
				link: $this->urlGenerator->makeSigningUrl($assignee),
			))->setLang($this->userService->getUserLanguage($userIdTo))
		);
	}

	/**
	 * Company side only: the notice tells about a document that needs nothing from the employee
	 * and links to a company page. For a by-employee document the creator is the employee himself.
	 *
	 * @return list<int>
	 */
	private function getDocumentPreparationFailedRecipients(Document $document): array
	{
		$recipients = [];

		if (!$this->isByEmployee($document) && $document->createdById)
		{
			$recipients[] = $document->createdById;
		}

		$companySideUserId = $this->getActiveReviewerOrAssigneeUserId($document);
		if ($companySideUserId && !in_array($companySideUserId, $recipients, true))
		{
			$recipients[] = $companySideUserId;
		}

		return $recipients;
	}

	private function sendDocumentPreparationFailedMessage(
		int $userIdFrom,
		int $userIdTo,
		Document $document,
		string $link,
	): Result
	{
		return $this->imService->sendMessage(
			(new Im\Messages\Failure\DocumentPreparationFailed(
				fromUser: $userIdFrom,
				toUser: $userIdTo,
				document: $document,
				link: $link,
			))->setLang($this->userService->getUserLanguage($userIdTo))
		);
	}

	private function sendExpiredMessageToCompany(int $userIdFrom, int $userIdTo, Document $document): Result
	{
		$message = new Im\Messages\Failure\DocumentExpiredToCompany(
			fromUser: $userIdFrom,
			toUser: $userIdTo,
			document: $document,
			link: $this->urlGenerator->getSigningProcessLink($document),
		);

		return $this->imService->sendMessage(
			$message->setLang($this->userService->getUserLanguage($userIdTo))
		);
	}

	private function sendStoppedMessageToCompany(int $userIdFrom, int $userIdTo, Document $document, ?int $whoStoppedUserId, ?string $role): Result
	{
		$message = $whoStoppedUserId === null
			? new Im\Messages\Failure\DocumentCancelled(
				fromUser: $userIdFrom,
				toUser: $userIdTo,
				document: $document,
				link: $this->urlGenerator->getSigningProcessLink($document),
			)
			: new Im\Messages\Failure\DocumentStopped(
				fromUser: $userIdFrom,
				toUser: $userIdTo,
				initiatorUserId: $whoStoppedUserId,
				initiatorName: $this->memberService->getUserRepresentedName($whoStoppedUserId),
				initiatorGender: $this->userService->getGender($whoStoppedUserId),
				document: $document,
				link: $this->urlGenerator->getSigningProcessLink($document),
				role: $role,
			)
		;

		return $this->imService->sendMessage(
			$message->setLang($this->userService->getUserLanguage($userIdTo))
		);
	}

	private function sendDoneMessageToEmployee(int $userIdFrom, Member $memberTo, Document $document): Result
	{
		// TODO Im\Messages\Done\ToEmployeeGoskey for goskey
		$userIdTo = $this->memberService->getUserIdForMember($memberTo);

		if (!$userIdTo)
		{
			return new Result();
		}

		return $this->imService->sendMessage(
			(new Im\Messages\Done\ToEmployee(
				fromUser: $userIdFrom,
				toUser: $userIdTo,
				document: $document,
				link: $this->urlGenerator->makeSigningUrl($memberTo)
			))->setLang($this->userService->getUserLanguage($userIdTo))
		);
	}

	private function byEmployeeSendEmployeeSignedMessageToEmployee(
		int $userIdFrom,
		int $userIdTo,
		Document $document,
		Member $employee,
	): Result
	{
		return $this->imService->sendMessage(
			(new Im\Messages\ByEmployee\SignedByEmployee(
				fromUser: $userIdFrom,
				toUser: $userIdTo,
				document: $document,
				link: $this->urlGenerator->makeSigningUrl($employee),
			))->setLang($this->userService->getUserLanguage($userIdTo))
		);
	}

	/**
	 * SC-002 N7 fallback: sends the previous "signed by employee" message to the employee signer when the
	 * receipt mark cannot be delivered -- its recipient did not resolve, or the invitation it follows could
	 * not be built. Mirrors the send path that runs on the SIGNER -> DONE transition when no mark is expected.
	 */
	private function sendByEmployeeSignedFallbackMessage(Document $document): Result
	{
		$signer = $this->memberService->getSigner($document);
		if ($signer === null)
		{
			return (new Result())->addError(new Error('Signer not found'));
		}

		$userIdFrom = $this->getBotUserId() ?? $document->representativeId;
		$userIdTo = $this->memberService->getUserIdForMember($signer);

		if (!$userIdFrom || !$userIdTo)
		{
			return new Result();
		}

		return $this->byEmployeeSendEmployeeSignedMessageToEmployee($userIdFrom, $userIdTo, $document, $signer);
	}

	private function byEmployeeSendDoneMessageToEmployee(Document $document): Result
	{
		$assignee = $this->memberService->getAssignee($document);

		if (!$assignee)
		{
			return (new Result())->addError(new Error('Assignee not found'));
		}

		$employee = $this->memberService->getSigner($document);

		if (!$employee)
		{
			return (new Result())->addError(new Error('Employee not found'));
		}

		$userFrom = $this->getBotUserId() ?? $document->representativeId ?? $this->memberService->getUserIdForMember($assignee);
		$userTo = $document->createdById ?? $this->memberService->getUserIdForMember($employee);

		return $this->imService->sendMessage(
			(new Im\Messages\ByEmployee\DoneEmployee(
				fromUser: $userFrom,
				toUser: $userTo,
				initiatorUserId: $document->representativeId,
				initiatorName: $this->memberService->getUserRepresentedName($document->representativeId),
				initiatorGender: $this->userService->getGender($document->representativeId),
				document: $document,
				link: $this->urlGenerator->makeSigningUrl($employee),
			))->setLang($this->userService->getUserLanguage($userTo))
		);
	}

	private function byEmployeeSendDoneMessageToCompany(Document $document): Result
	{
		$userFrom = $this->getBotUserId() ?? $document->representativeId ?? $this->memberService->getUserIdForMember(
			$this->memberService->getAssignee($document)
		);
		$userTo = $document->representativeId ?? $this->memberService->getUserIdForMember(
			$this->memberService->getAssignee($document)
		);

		return $this->imService->sendMessage(
			(new Im\Messages\ByEmployee\DoneCompany(
				fromUser: $userFrom,
				toUser: $userTo,
				initiatorUserId: $document->createdById,
				initiatorName: $this->memberService->getUserRepresentedName($document->createdById),
				document: $document,
			))->setLang($this->userService->getUserLanguage($userTo))
		);
	}

	private function sendDoneMessageToCompany(int $userIdFrom, int $userIdTo, Document $document): Result
	{
		return $this->imService->sendMessage(
			(new Im\Messages\Done\AllSignedToCompany(
				fromUser: $userIdFrom,
				toUser: $userIdTo,
				document: $document,
				link: $this->config->getB2eMySafeUrl(),
			))->setLang($this->userService->getUserLanguage($userIdTo))
		);
	}

	private function sendStoppedToEmployeeMessage(int $userIdFrom, int $userIdTo, Document $document, ?int $whoStoppedUserId): Result
	{
		if ($whoStoppedUserId === null)
		{
			return new Result();
		}

		return $this->imService->sendMessage(
			(new Im\Messages\Failure\StoppedToEmployee(
				fromUser: $userIdFrom,
				toUser: $userIdTo,
				initiatorUserId: $whoStoppedUserId,
				initiatorName: $this->memberService->getUserRepresentedName($whoStoppedUserId),
				initiatorGender: $this->userService->getGender($whoStoppedUserId),
				document: $document,
			))->setLang($this->userService->getUserLanguage($userIdTo))
		);
	}

	/**
	 * @param int|null $whoStoppedUserId the message names the one who stopped the document, so an unknown
	 *   initiator leaves nothing to say: the stop came from the signing service or from the employee
	 *   themselves, and naming a bystander would assert a refusal they never made. Same rule as in
	 *   sendStoppedToEmployeeMessage().
	 */
	private function sendByEmployeeStoppedToEmployeeMessage(
		int $userIdFrom,
		int $userIdTo,
		Document $document,
		?int $whoStoppedUserId,
	): Result
	{
		if ($whoStoppedUserId === null)
		{
			return new Result();
		}

		$signer = $this->memberService->getSigner($document);

		if (!$signer)
		{
			return (new Result())->addError(new Error('Signer not found'));
		}

		return $this->imService->sendMessage(
			(new Im\Messages\ByEmployee\StoppedToEmployee(
				fromUser: $userIdFrom,
				toUser: $userIdTo,
				initiatorUserId: $whoStoppedUserId,
				initiatorName: $this->memberService->getUserRepresentedName($whoStoppedUserId),
				initiatorGender: $this->userService->getGender($whoStoppedUserId),
				document: $document,
				link: $this->urlGenerator->makeSigningUrl($signer),
			))->setLang($this->userService->getUserLanguage($userIdTo))
		);
	}

	private function sendByEmployeeDocumentExpiredToEmployeeMessage(int $userIdFrom, int $userIdTo, Document $document, int $assigneeUserId): Result
	{
		return $this->imService->sendMessage(
			(new Im\Messages\ByEmployee\ExpiredToEmployee(
				fromUser: $userIdFrom,
				toUser: $userIdTo,
				initiatorUserId: $assigneeUserId,
				initiatorName: $this->memberService->getUserRepresentedName($assigneeUserId),
				initiatorGender: $this->userService->getGender($assigneeUserId),
				document: $document,
				link: $this->urlGenerator->makeSigningUrl($this->memberService->getSigner($document)),
			))->setLang($this->userService->getUserLanguage($userIdTo))
		);
	}

	private function sendEmployeeStoppedMessage(int $userIdFrom, int $userIdTo, Document $document, Member $memberSigner, int $whoStoppedUserId): Result
	{
		return $this->imService->sendMessage(
			(new Im\Messages\Failure\EmployeeStoppedToCompany(
				fromUser: $userIdFrom,
				toUser: $userIdTo,
				initiatorUserId: $whoStoppedUserId,
				initiatorName: $this->memberService->getUserRepresentedName($whoStoppedUserId),
				initiatorGender: $this->userService->getGender($whoStoppedUserId),
				document: $document,
				member: $memberSigner,
				link: $this->urlGenerator->getSigningProcessLink($document),
			))->setLang($this->userService->getUserLanguage($userIdTo))
		);
	}

	private function sendRefusedMessage(int $userIdFrom, int $userIdTo, Document $document): Result
	{
		return $this->imService->sendMessage(
			(new Im\Messages\Failure\RefusedToCompany(
				fromUser: $userIdFrom,
				toUser: $userIdTo,
				document: $document,
				initiatorUserId: $userIdFrom,
				initiatorName: $this->memberService->getUserRepresentedName($userIdFrom),
				initiatorGender: $this->userService->getGender($userIdFrom),
				link: $this->urlGenerator->getSigningProcessLink($document),
			))->setLang($this->userService->getUserLanguage($userIdTo))
		);
	}

	private function handleEmployeeStoppedStatus(Document $document, Member $member): Result
	{
		$whoStoppedUserId = $this->getActiveReviewerOrAssigneeUserId($document);

		if ($whoStoppedUserId === null)
		{
			return (new Result())->addError(new Error('Initiator of employee_stopped message not found'));
		}

		$userTo = $document->createdById;

		if ($whoStoppedUserId !== $userTo)
		{
			$userFrom = $this->getBotUserId() ?? $whoStoppedUserId;

			return $this->sendEmployeeStoppedMessage(
				userIdFrom: $userFrom,
				userIdTo: $userTo,
				document: $document,
				memberSigner: $member,
				whoStoppedUserId: $whoStoppedUserId,
			);
		}

		return new Result();
	}

	private function getActiveReviewerOrAssigneeUserId(Document $document, ?Member $member = null): ?int
	{
		if ($member === null)
		{
			$member = $this->memberService->getCurrentParticipantFromCompanySide($document);
		}

		if ($member && $userId = $this->memberService->getUserIdForMember($member))
		{
			return $userId;
		}

		return $document->representativeId;
	}

	private function getBotUserId(): ?int
	{
		return (new HrBot())->getBotUserId();
	}

	private function isByEmployee(Document $document): bool
	{
		return $document->initiatedByType === Type\Document\InitiatedByType::EMPLOYEE;
	}

	/**
	 * Whether the employee will receive an SC-002 receipt mark instead of the "document sent" message.
	 * True only when a company assignee will actually be invited to sign (assignee exists and the chat
	 * invitation is not skipped, e.g. not a self-send). Otherwise the employee keeps the previous
	 * "document sent" message and is never left without a notification.
	 */
	private function isReceiptMarkExpectedForCompanySide(Document $document): bool
	{
		$assignee = $this->memberService->getAssignee($document);
		if ($assignee === null)
		{
			return false;
		}

		return !$this->memberService->skipChatInvitationForMember($assignee, $document);
	}

	/**
	 * @todo move to callback
	 */
	private function isDocumentExpired(Document $document): bool
	{
		return
			$document->dateSignUntil !== null
			&& $document->dateSignUntil->getTimestamp() <= (new DateTime())->getTimestamp()
		;
	}

	private function getEmployeeUserIdFromDocumentByEmployee(Document $document): ?int
	{
		if ($document->createdById)
		{
			return $document->createdById;
		}

		$signer = $this->memberService->getSigner($document);

		if ($signer)
		{
			return $this->memberService->getUserIdForMember($signer);
		}

		return null;
	}

	private function getAssigneeUserId(Document $document): ?int
	{
		$assignee = $this->memberService->getAssignee($document);

		if ($assignee)
		{
			return $this->memberService->getUserIdForMember($assignee);
		}

		return $document->representativeId;
	}
}
