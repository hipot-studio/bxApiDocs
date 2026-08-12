<?php

namespace Bitrix\Im\V2\Controller\Filter;

use Bitrix\Im\V2\Application\Features;
use Bitrix\Im\V2\Chat\ChatError;
use Bitrix\Im\V2\Controller;
use Bitrix\Im\V2\Entity\User\User;
use Bitrix\Im\V2\Entity\User\UserGuest;
use Bitrix\Im\V2\Guest\Auth\AuthenticationService;
use Bitrix\Im\V2\Guest\Auth\AuthError;
use Bitrix\Im\V2\Guest\Auth\AuthorizationService;
use Bitrix\Im\V2\Guest\Auth\Token;
use Bitrix\Im\V2\Guest\GuestService;
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

		if ($this->shouldTerminateGuestSession())
		{
			// Non-rotating invalidation: a real Logout() here would call regenerateId() on every
			// request of the messenger's concurrent burst and race the file-backed session start
			// ("Could not start session by PHP"). We drop the cookies, notify the client (redirect),
			// and reject the action; the session is torn down cleanly on the single redirect to '/'.
			AuthorizationService::getInstance()->invalidateCurrentGuestSession();

			$this->addError(new AuthError(AuthError::GUEST_SESSION_TERMINATED));

			return new EventResult(EventResult::ERROR, null, null, $this);
		}

		return $this->checkGuestAccess();
	}

	protected function shouldTerminateGuestSession(): bool
	{
		$globalUser = Locator::getContext()->getCUser();
		if ($globalUser === null)
		{
			return false;
		}

		if ($globalUser->GetParam('EXTERNAL_AUTH_ID') !== UserGuest::AUTH_ID)
		{
			return false;
		}

		return !GuestService::getInstance()->isCurrentGuestSessionValid();
	}

	protected function tryAuthenticateGuest(): void
	{
		if (!Features::isChatWithGuestsAvailable(GuestService::getInstance()->getCurrentInviterId()))
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

		\CUser::SetLastActivityDate((int)$globalUser->GetID(), true);

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
