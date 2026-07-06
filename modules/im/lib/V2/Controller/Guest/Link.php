<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Controller\Guest;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Controller\BaseController;
use Bitrix\Im\V2\Controller\Filter\CheckActionAccess;
use Bitrix\Im\V2\Guest\GuestLinkService;
use Bitrix\Im\V2\Integration\Notifications\NotificationService;
use Bitrix\Im\V2\Permission\Action;
use Bitrix\Im\V2\SharingLink\GuestChatLink;
use Bitrix\Main\Engine\CurrentUser;

/**
 * REST controller for guest chat sharing links.
 *
 * Provides endpoints to generate, regenerate and revoke guest links for chats.
 */
class Link extends BaseController
{
	public function configureActions(): array
	{
		return [
			'generate' => [
				'+prefilters' => [
					new CheckActionAccess(Action::ManageGuestLink),
				],
			],
			'regenerate' => [
				'+prefilters' => [
					new CheckActionAccess(Action::UpdateGuestLink),
				],
			],
			'revoke' => [
				'+prefilters' => [
					new CheckActionAccess(Action::UpdateGuestLink),
				],
			],
			'inviteByEmail' => [
				'+prefilters' => [
					new CheckActionAccess(Action::ManageGuestLink),
				],
			],
			'inviteByPhoneNumber' => [
				'+prefilters' => [
					new CheckActionAccess(Action::ManageGuestLink),
				],
			],
		];
	}

	/**
	 * Generate individual guest link for a chat.
	 *
	 * @restMethod im.v2.Guest.Link.generate
	 */
	public function generateAction(Chat $chat, CurrentUser $currentUser): ?array
	{
		$currentUserId = (int)$currentUser->getId();

		$result = GuestLinkService::getInstance()->getOrCreateLink($chat, $currentUserId);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $this->toRestFormat($result->getResult());
	}

	/**
	 * Regenerate guest link for a chat (revokes old and creates new).
	 *
	 * @restMethod im.v2.Guest.Link.regenerate
	 */
	public function regenerateAction(Chat $chat, CurrentUser $currentUser): ?array
	{
		$currentUserId = (int)$currentUser->getId();

		$result = GuestLinkService::getInstance()->regenerateLink($chat, $currentUserId);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $this->toRestFormat($result->getResult());
	}

	/**
	 * Revoke guest link for a chat.
	 *
	 * @restMethod im.v2.Guest.Link.revoke
	 */
	public function revokeAction(Chat $chat, CurrentUser $currentUser): ?array
	{
		$currentUserId = (int)$currentUser->getId();

		$result = GuestLinkService::getInstance()->revokeLink($chat, $currentUserId);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return ['result' => true];
	}

	/**
	 * Generate personalized guest links and send invitations by email.
	 *
	 * Format: invitations: [['email' => string, 'name' => string], ...]
	 *
	 * @restMethod im.v2.Guest.Link.inviteByEmail
	 */
	public function inviteByEmailAction(Chat $chat, CurrentUser $currentUser, array $invitations): ?array
	{
		$currentUserId = (int)$currentUser->getId();
		$service = GuestLinkService::getInstance();
		$results = [];

		foreach ($invitations as $invitation)
		{
			$email = (string)($invitation['email'] ?? '');
			$name = $invitation['name'] ?? null;

			$inviteResult = $service->inviteByEmail($chat, $currentUserId, $email, $name);

			if (!$inviteResult->isSuccess())
			{
				$error = $inviteResult->getErrors()[0];
				$results[] = [
					'email' => $email,
					'error' => [
						'code' => $error->getCode(),
						'message' => $error->getMessage(),
					],
				];

				continue;
			}

			/** @var GuestChatLink $sharingLink */
			$sharingLink = $inviteResult->getResult();

			$linkData = $this->toRestFormat($sharingLink);
			$linkData['email'] = $email;
			$results[] = $linkData;
		}

		return $results;
	}

	/**
	 * Generate personalized guest links and send invitations by phone number.
	 *
	 * Format: invitations: [['phone' => string, 'name' => string], ...]
	 *
	 * @restMethod im.v2.Guest.Link.inviteByPhoneNumber
	 */
	public function inviteByPhoneNumberAction(Chat $chat, CurrentUser $currentUser, array $invitations): ?array
	{
		$currentUserId = (int)$currentUser->getId();
		$service = GuestLinkService::getInstance();
		$results = [];

		foreach ($invitations as $invitation)
		{
			$phone = (string)($invitation['phone'] ?? '');
			$name = $invitation['name'] ?? null;

			$inviteResult = $service->inviteByPhoneNumber($chat, $currentUserId, $phone, $name);

			if (!$inviteResult->isSuccess())
			{
				$error = $inviteResult->getErrors()[0];
				$results[] = [
					'phone' => $phone,
					'error' => [
						'code' => $error->getCode(),
						'message' => $error->getMessage(),
					],
				];

				continue;
			}

			/** @var GuestChatLink $sharingLink */
			$sharingLink = $inviteResult->getResult();

			$linkData = $this->toRestFormat($sharingLink);
			$linkData['phone'] = NotificationService::formatPhoneE164($phone) ?? $phone;
			$results[] = $linkData;
		}

		return $results;
	}
}
