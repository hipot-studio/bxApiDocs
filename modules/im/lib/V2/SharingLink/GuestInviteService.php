<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\SharingLink;

use Bitrix\Im\Model\SharingLinkTable;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Integration\Notifications\NotificationService;
use Bitrix\Im\V2\Result;
use Bitrix\Im\V2\Service\Locator;
use Bitrix\Im\V2\SharingLink\Dto\CreateDto;
use Bitrix\Im\V2\SharingLink\Entity\LinkEntityType;
use Bitrix\Main\Type\DateTime;

/**
 * Service for sending personalized guest invitations via email or SMS.
 */
class GuestInviteService
{
	private const INVITE_COOLDOWN_MINUTES = 5;
	private const AUTHOR_RATE_LIMIT_PER_HOUR = 10;

	protected static ?self $instance = null;

	private function __construct()
	{
	}

	public static function getInstance(): self
	{
		self::$instance ??= new self();

		return self::$instance;
	}

	/**
	 * Create personalized guest link and send invitation by email.
	 *
	 * @return Result<GuestChatLink>
	 */
	public function inviteByEmail(Chat $chat, int $authorId, string $email, ?string $name = null): Result
	{
		$result = new Result();

		if (!check_email($email))
		{
			return $result->addError(new GuestInviteError(GuestInviteError::INVALID_EMAIL));
		}

		$cooldownResult = $this->checkInviteCooldown($chat->getId(), $authorId, $email);
		if (!$cooldownResult->isSuccess())
		{
			return $result->addErrors($cooldownResult->getErrors());
		}

		$linkResult = $this->createPersonalizedLink($chat, $authorId, $email);
		if (!$linkResult->isSuccess())
		{
			return $result->addErrors($linkResult->getErrors());
		}

		$sharingLink = $linkResult->getResult();

		$emailResult = Locator::getGuestInviteEmailTransport()->sendEmail(
			$email,
			$sharingLink->getInviteUrl($name),
			$name,
			$chat->getTitle(),
		);
		if (!$emailResult->isSuccess())
		{
			$sharingLink->delete();

			return $result->addErrors($emailResult->getErrors());
		}

		return $result->setResult($sharingLink);
	}

	/**
	 * Create personalized guest link and send invitation by phone number.
	 *
	 * @return Result<GuestChatLink>
	 */
	public function inviteByPhoneNumber(Chat $chat, int $authorId, string $phone, ?string $name = null): Result
	{
		$result = new Result();

		$phoneE164 = NotificationService::formatPhoneE164($phone);
		if ($phoneE164 === null)
		{
			return $result->addError(new GuestInviteError(GuestInviteError::INVALID_PHONE));
		}

		$cooldownResult = $this->checkInviteCooldown($chat->getId(), $authorId, $phoneE164);
		if (!$cooldownResult->isSuccess())
		{
			return $result->addErrors($cooldownResult->getErrors());
		}

		$linkResult = $this->createPersonalizedLink($chat, $authorId, $phoneE164);
		if (!$linkResult->isSuccess())
		{
			return $result->addErrors($linkResult->getErrors());
		}

		$sharingLink = $linkResult->getResult();

		$smsResult = Locator::getGuestInviteSmsTransport()->sendSms(
			$phoneE164,
			$sharingLink->getInviteUrl($name),
			$chat->getTitle(),
		);
		if (!$smsResult->isSuccess())
		{
			$sharingLink->delete();

			return $result->addErrors($smsResult->getErrors());
		}

		return $result->setResult($sharingLink);
	}

	/**
	 * Create a Custom type sharing link for a personalized invitation.
	 *
	 * @return Result<GuestChatLink>
	 */
	private function createPersonalizedLink(Chat $chat, int $authorId, string $recipientContact): Result
	{
		$result = new Result();

		$dto = CreateDto::initForGuestChatCustom(
			chatId: (string)$chat->getId(),
			authorId: $authorId,
			name: $recipientContact,
			maxUses: GuestChatLink::PERSONAL_LINK_MAX_USES,
		);

		$linkResult = SharingLinkFactory::getInstance()->createCustomLink($dto);
		if (!$linkResult->isSuccess())
		{
			return $result->addErrors($linkResult->getErrors());
		}

		$link = $linkResult->getResult();
		if (!$link instanceof GuestChatLink)
		{
			return $result->addError(new SharingLinkError(SharingLinkError::WRONG_ENTITY));
		}

		return $result->setResult($link);
	}

	/**
	 * Check if a recent invitation was already sent to the same contact for this chat
	 * and if the author has not exceeded the rate limit.
	 */
	private function checkInviteCooldown(int $chatId, int $authorId, string $contact): Result
	{
		$result = new Result();

		$cooldownThreshold = (new DateTime())->add('-' . self::INVITE_COOLDOWN_MINUTES . ' minutes');

		$existingLink = SharingLinkTable::query()
			->setSelect(['ID'])
			->where('ENTITY_TYPE', LinkEntityType::GuestChat->value)
			->where('ENTITY_ID', (string)$chatId)
			->where('NAME', $contact)
			->where('IS_REVOKED', 'N')
			->where('DATE_CREATE', '>', $cooldownThreshold)
			->setLimit(1)
			->fetch()
		;

		if ($existingLink !== false)
		{
			return $result->addError(new GuestInviteError(GuestInviteError::INVITE_COOLDOWN));
		}

		$rateLimitThreshold = (new DateTime())->add('-1 hour');

		$authorInviteCount = SharingLinkTable::getCount([
			'=ENTITY_TYPE' => LinkEntityType::GuestChat->value,
			'=AUTHOR_ID' => $authorId,
			'>DATE_CREATE' => $rateLimitThreshold,
		]);

		if ($authorInviteCount >= self::AUTHOR_RATE_LIMIT_PER_HOUR)
		{
			return $result->addError(new GuestInviteError(GuestInviteError::AUTHOR_RATE_LIMIT));
		}

		return $result;
	}
}
