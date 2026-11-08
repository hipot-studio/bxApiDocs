<?php

declare(strict_types=1);

namespace Bitrix\Calendar\Core\Queue\Invitation;

use Bitrix\Calendar\ICal\MailInvitation\InvitationInfo;
use Bitrix\Calendar\ICal\MailInvitation\SenderInvitation;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\SiteTable;

class EmailInvitationDelivery
{
	/**
	 * @param string[] $changedFieldKeys
	 */
	public function deliver(
		int $eventId,
		int $addresserId,
		int $receiverId,
		string $type,
		array $changedFieldKeys = [],
	): EmailInvitationDeliveryStatus
	{
		$senderInvitation = $this->createSenderInvitation(
			$eventId,
			$addresserId,
			$receiverId,
			$type,
			$changedFieldKeys,
		);
		if ($senderInvitation === null)
		{
			return EmailInvitationDeliveryStatus::NotAssembled;
		}

		$this->setLanguageId();

		if (!$senderInvitation->send())
		{
			return EmailInvitationDeliveryStatus::Refused;
		}

		$senderInvitation->executeAfterSuccessfulInvitation();

		return EmailInvitationDeliveryStatus::Delivered;
	}

	/**
	 * @param string[] $changedFieldKeys
	 */
	protected function createSenderInvitation(
		int $eventId,
		int $addresserId,
		int $receiverId,
		string $type,
		array $changedFieldKeys,
	): ?SenderInvitation
	{
		$invitation = new InvitationInfo(
			$eventId,
			$addresserId,
			$receiverId,
			$type,
			array_map(
				static fn(string $fieldKey): array => ['fieldKey' => $fieldKey],
				$changedFieldKeys,
			),
			0,
		);

		return $invitation->getSenderInvitation();
	}

	protected function setLanguageId(): void
	{
		$siteDb = SiteTable::getById(SITE_ID);
		if ($site = $siteDb->fetchObject())
		{
			Loc::setCurrentLang($site->getLanguageId());
		}
	}
}
