<?php

namespace Bitrix\Im\V2\Controller\Filter;

use Bitrix\Im\V2\Application\Features;
use Bitrix\Im\V2\Chat\ChatError;
use Bitrix\Im\V2\Controller;
use Bitrix\Im\V2\Entity\User\User;
use Bitrix\Im\V2\Guest\Auth\AuthenticationService;
use Bitrix\Im\V2\Guest\Auth\Token;
use Bitrix\Im\V2\Service\Locator;
use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;

class AuthorizationPrefilter extends Base
{
	protected static ?array $allowedGuestControllers = null;

	public function onBeforeAction(Event $event): ?EventResult
	{
		global $USER;
		if (!$USER->IsAuthorized())
		{
			$USER->LoginByCookies();
		}

		if (!$USER->IsAuthorized())
		{
			$this->tryAuthenticateGuest();
		}

		if (!$USER->IsAuthorized())
		{
			return null;
		}

		return $this->checkGuestAccess();
	}

	protected function tryAuthenticateGuest(): void
	{
		if (!Features::isChatWithGuestsAvailable())
		{
			return;
		}

		$token = Token::createFromRequest();
		if ($token !== null)
		{
			AuthenticationService::getInstance()->authenticate($token);
		}
	}

	protected function checkGuestAccess(): ?EventResult
	{
		$globalUser = Locator::getContext()->getCUser();
		if ($globalUser === null)
		{
			return null;
		}

		$user = User::getInstance((int)$globalUser->GetID());
		if (!$user->isGuest())
		{
			return null;
		}

		if (!$this->isGuestControllerAllowed())
		{
			$this->addError(new ChatError(ChatError::ACCESS_DENIED));

			return new EventResult(EventResult::ERROR, null, null, $this);
		}

		return null;
	}

	protected function isGuestControllerAllowed(): bool
	{
		$controllerClass = $this->getAction()->getController()::class;

		return isset(static::getAllowedGuestControllers()[$controllerClass]);
	}

	protected static function getAllowedGuestControllers(): array
	{
		if (self::$allowedGuestControllers !== null)
		{
			return self::$allowedGuestControllers;
		}

		self::$allowedGuestControllers = [
			// Chat
			Controller\Chat::class => true,
			Controller\Chat\Anchor::class => true,
			Controller\Chat\InputAction::class => true,
			Controller\Chat\Member::class => true,
			Controller\Chat\MemberEntities::class => true,
			Controller\Chat\Mention::class => true,
			Controller\Chat\Message::class => true,
			Controller\Chat\Message\CommentInfo::class => true,
			Controller\Chat\Message\Reaction::class => true,
			Controller\Chat\Pin::class => true,
			Controller\Chat\User::class => true,
			// Recent & counters
			Controller\Counter::class => true,
			Controller\Recent::class => true,
			// Notifications
			Controller\Notify::class => true,
			// Sync
			Controller\Sync::class => true,
			// Stickers
			Controller\Sticker::class => true,
			Controller\Sticker\Pack::class => true,
			Controller\Sticker\Recent::class => true,
			// Files
			Controller\Disk\File::class => true,
			// Guest
			Controller\Guest::class => true,
			// System
			Controller\Access::class => true,
			Controller\Anchor::class => true,
			Controller\UpdateState::class => true,
			Controller\Tariff\Restriction::class => true,
			// Settings
			Controller\Settings\General::class => true,
			Controller\Settings\Notify::class => true,
			Controller\Settings\Status::class => true,
		];

		return self::$allowedGuestControllers;
	}
}
