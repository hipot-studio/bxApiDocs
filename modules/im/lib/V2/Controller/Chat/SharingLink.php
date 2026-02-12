<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Controller\Chat;

use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Controller\BaseController;
use Bitrix\Im\V2\Controller\Filter\CheckActionAccess;
use Bitrix\Im\V2\Permission\Action;
use Bitrix\Im\V2\SharingLink\ChatLink;
use Bitrix\Im\V2\SharingLink\SharingLinkError;
use Bitrix\Im\V2\SharingLink\SharingLinkFactory;
use Bitrix\Im\V2\SharingLink\Type;
use Bitrix\Main\Engine\AutoWire\ExactParameter;
use Bitrix\Main\Engine\CurrentUser;

class SharingLink extends BaseController
{
	public function configureActions()
	{
		return [
			'getPrimary' => [
				'+prefilters' => [
					new CheckActionAccess(Action::ManageSharingLinks),
				],
			],
			'getIndividual' => [
				'+prefilters' => [
					new CheckActionAccess(Action::Extend),
				],
			],
			'regenerateIndividual' => [
				'+prefilters' => [
					new CheckActionAccess(Action::Extend),
				],
			],
			'revoke' => [
				'+prefilters' => [
					new CheckActionAccess(Action::UpdateSharingLink),
				],
			],
		];
	}

	public function getAutoWiredParameters()
	{
		return array_merge(
			parent::getAutoWiredParameters(),
			[
				new ExactParameter(
					Chat::class,
					'chat',
					function ($className, ChatLink $sharingLink) {
						return $sharingLink->getEntity();
					}
				),
			]
		);
	}

	/**
	 * @restMethod im.v2.Chat.SharingLink.getPrimary
	 */
	public function getPrimaryAction(
		Chat $chat,
		CurrentUser $currentUser,
		string $generateIfNotExists = 'Y'
	): ?array
	{
		$currentUserId = (int)$currentUser->getId();

		$result =
			SharingLinkFactory::getInstance()
				->getOrCreateActivePrimaryLink(
					$chat,
					$currentUserId,
					$this->convertCharToBool($generateIfNotExists, true)
				)
		;

		$sharingLink = $result->getResult();
		if (!$result->isSuccess() || !isset($sharingLink))
		{
			$this->addErrors($result->getErrors());
			return null;
		}

		return $this->toRestFormat($sharingLink);
	}

	/**
	 * @restMethod im.v2.Chat.SharingLink.getIndividual
	 */
	public function getIndividualAction(
		Chat $chat,
		CurrentUser $currentUser,
		string $generateIfNotExists = 'Y'
	): ?array
	{
		$currentUserId = (int)$currentUser->getId();

		$result =
			SharingLinkFactory::getInstance()
				->getOrCreateActiveIndividualLink(
					$chat,
					$currentUserId,
					$this->convertCharToBool($generateIfNotExists, true)
				)
		;

		$sharingLink = $result->getResult();
		if (!$result->isSuccess() || !isset($sharingLink))
		{
			$this->addErrors($result->getErrors());
			return null;
		}

		return $this->toRestFormat($sharingLink);
	}

	/**
	 * @restMethod im.v2.Chat.SharingLink.regenerateIndividual
	 */
	public function regenerateIndividualAction(Chat $chat, CurrentUser $currentUser): ?array
	{
		$currentUserId = (int)$currentUser->getId();

		$result =
			SharingLinkFactory::getInstance()
				->regenerateActiveIndividualLink(
					$chat,
					$currentUserId,
				)
		;

		$sharingLink = $result->getResult();
		if (!$result->isSuccess() || !isset($sharingLink))
		{
			$this->addErrors($result->getErrors());
			return null;
		}

		return $this->toRestFormat($sharingLink);
	}

	/**
	 * @restMethod im.v2.Chat.SharingLink.revoke
	 */
	public function revokeAction(ChatLink $sharingLink): ?array
	{
		// In first iteration we can only use individual links
		if ($sharingLink->getType() !== Type::Individual)
		{
			$this->addError(new SharingLinkError(SharingLinkError::WRONG_PARAMS));

			return null;
		}

		$revocationResult = $sharingLink->revoke();

		if (!$revocationResult->isSuccess())
		{
			$this->addErrors($revocationResult->getErrors());

			return null;
		}

		return ['result' => true];
	}
}