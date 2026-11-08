<?php

namespace Bitrix\Sign\Operation\Member\Reminder;

use Bitrix\Sign\Contract;
use Bitrix\Sign\Repository\MemberRepository;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Service\Integration\Im\NotificationService;
use Bitrix\Sign\Service\Integration\Imbot\HrBot;
use Bitrix\Sign\Service\Sign\DocumentService;
use Bitrix\Sign\Service\Sign\MemberService;
use Bitrix\Sign\Service\Sign\UrlGeneratorService;
use Bitrix\Sign\Service\UserService;
use Bitrix\Sign\Type\DocumentStatus;
use Bitrix\Sign\Type\Im\Notification\NotificationType;
use Bitrix\Sign\Type\Member\Role;
use Bitrix\Sign\Type\MemberStatus;
use Bitrix\Sign\Item;
use Bitrix\Main;
use Psr\Log\LoggerInterface;

class SendManual implements Contract\Operation
{
	private const MEMBER_LIMIT = 200;

	private readonly NotificationService $imNotificationService;
	private readonly MemberRepository $memberRepository;
	private readonly MemberService $memberService;
	private readonly DocumentService $documentService;
	private readonly UserService $userService;
	private readonly UrlGeneratorService $urlgeneratorService;
	private readonly LoggerInterface $logger;
	private readonly HrBot $hrBot;

	public function __construct(
		private readonly Item\Document $document,
		private readonly array $memberUids,
		?NotificationService $imNotificationService = null,
		?MemberRepository $memberRepository = null,
		?MemberService $memberService = null,
		?DocumentService $documentService = null,
		?UserService $userService = null,
		?UrlGeneratorService $urlgeneratorService = null,
		?LoggerInterface $logger = null,
		?HrBot $hrBot = null,
	)
	{
		$this->imNotificationService = $imNotificationService ?? Container::instance()->getImNotificationService();
		$this->memberRepository = $memberRepository ?? Container::instance()->getMemberRepository();
		$this->memberService = $memberService ?? Container::instance()->getMemberService();
		$this->documentService = $documentService ?? Container::instance()->getDocumentService();
		$this->userService = $userService ?? Container::instance()->getUserService();
		$this->urlgeneratorService = $urlgeneratorService ?? Container::instance()->getUrlGeneratorService();
		$this->logger = $logger ?? Container::instance()->getLogger('Operation');
		$this->hrBot = $hrBot ?? (new HrBot());
	}

	public function launch(): Main\Result
	{
		$result = new Main\Result();

		$memberUids = $this->memberUids;
		$memberUids = array_unique(
			array_values(
				array_filter($memberUids)
			)
		);

		if ($memberUids === [])
		{
			$this->logger->warning(
				'Members list is empty.',
			);

			return $result;
		}
		if (count($memberUids) > self::MEMBER_LIMIT)
		{
			return $result->addError(new Main\Error(
				Main\Localization\Loc::getMessage(
					'SIGN_OPERATION_MEMBER_REMINDER_SEND_MANUAL_REMINDER_BATCH_LIMIT_EXCEEDED',
					['#LIMIT#' => self::MEMBER_LIMIT],
				),
				'SIGN_REMINDER_BATCH_LIMIT_EXCEEDED',
				['limit' => self::MEMBER_LIMIT],
			));
		}

		$plannedCount = count($memberUids);

		$document = $this->document;
		if ($document->id === null)
		{
			$this->logger->warning(
				'Document not found.',
			);
			return $result;
		}
		if (!$this->imNotificationService->isAvailable())
		{
			return $result->addError(
				new Main\Error(
					Main\Localization\Loc::getMessage('SIGN_OPERATION_MEMBER_REMINDER_SEND_MANUAL_IM_IS_NOT_AVAILABLE'),
					'SIGN_IM_IS_NOT_AVAILABLE',
				),
			);
		}
		if (DocumentStatus::isFinalByDocument($document))
		{
			return $result->addError(
				new Main\Error(
					Main\Localization\Loc::getMessage('SIGN_OPERATION_MEMBER_REMINDER_SEND_MANUAL_DOCUMENT_IS_FINAL'),
					'SIGN_DOCUMENT_IS_FINAL',
				),
			);
		}

		$members = $this->memberRepository->listByUidsAndDocumentIdAndStatusesAndRole(
			$memberUids,
			$document->id,
			MemberStatus::getReadyForSigning(),
			Role::SIGNER,
			self::MEMBER_LIMIT
		);

		if ($members->count() === 0)
		{
			$this->logger->warning(
				'Members list is empty.',
			);

			return $result;
		}

		$sentCount = 0;
		$documentTitle = $this->documentService->getComposedTitleByDocument($document);

		$botUserId = $this->hrBot->getBotUserId() ?? 0;
		$memberUserIds = $this->memberService->getUserIdsMapForMembers($members, $document);

		if ($memberUserIds === [])
		{
			$this->logger->warning(
				'Member user IDs is empty.',
			);

			return $result;
		}

		$languages = $this->userService->getUserLanguagesMapByUserIds($memberUserIds);

		foreach ($members as $member)
		{
			$userId = $memberUserIds[$member->id] ?? 0;
			$language = $languages[$userId] ?? null;
			$sendReminderResult = $this->sendReminder(
				member: $member,
				memberUserId: $userId,
				documentTitle: $documentTitle,
				language: $language,
				botUserId: $botUserId
			);
			if ($sendReminderResult->isSuccess())
			{
				$sentCount++;
			}
		}

		$result->setData([
			'all' => $plannedCount,
			'sent' => $sentCount,
			'documentTitle' => $documentTitle,
		]);

		return $result;
	}

	private function sendReminder(
		Item\Member $member,
		int $memberUserId,
		string $documentTitle,
		?string $language,
		int $botUserId
	): Main\Result
	{
		if ($memberUserId === 0)
		{
			$this->logger->warning(
				'Member user ID is not set',
			);
			return (new Main\Result())->addError(new Main\Error('Member user ID is not set.'));
		}
		$signingLink = htmlspecialcharsbx($this->urlgeneratorService->makeSigningUrl($member));
		$documentTitle = htmlspecialcharsbx($documentTitle);
		$message = Main\Localization\Loc::getMessage(
			'SIGN_OPERATION_MEMBER_REMINDER_SEND_MANUAL_MESSAGE_TEXT',
			['#DOCUMENT_TITLE_WITH_LINK#' => "<a href=\"$signingLink\">$documentTitle</a>"],
			$language,
		);
		if ($message === null)
		{
			$this->logger->warning(
				'Failed to compose message for member: {memberId}',
				[
					'memberId' => $member->id,
				]
			);
			return (new Main\Result())->addError(new Main\Error("Failed to compose message for member {$member->id}."));
		}

		$result = $this->imNotificationService->sendNotification(
			new Item\Integration\Im\Notification\Message(
				fromUserId: $botUserId,
				toUserId: $memberUserId,
				type: NotificationType::SYSTEM,
				title: null,
				message: $message,
				notifyTag: "SIGN|REMIND|$member->id",
			),
		);

		$this->logErrorsFromResult($result);

		return $result;
	}

	private function logErrorsFromResult(Main\Result $result): void
	{
		$errors = $result->getErrors();
		foreach ($errors as $error)
		{
			$this->logger->warning(
				'{code}: {message}',
				[
					'code' => $error->getCode(),
					'message' => $error->getMessage(),
				]
			);
		}
	}
}
