<?php

declare(strict_types=1);

namespace Bitrix\Call\Controller\ActionFilter;

use Bitrix\Call\Service\GuestCallPolicy;
use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;

/**
 * Keeps an action out of a guest's hands whatever transport it is called by.
 *
 * The guest allow-list of the old REST API ({@see \Bitrix\Im\V2\Guest\Auth\GuestRestAuth}) is bound to
 * rest:onRestCheckAuth, so it only ever sees calls to /rest/. The very same controller actions answer a
 * guest over /bitrix/services/main/ajax.php, where nothing asks who the caller is - so a list that reads
 * as an access boundary is one only for a single transport. What must not be reachable by a guest has to
 * say so at the action.
 */
class DenyGuest extends Base
{
	public const ERROR_ACCESS_DENIED = 'guest_access_denied';

	private GuestCallPolicy $guestCallPolicy;

	public function __construct(?GuestCallPolicy $guestCallPolicy = null)
	{
		parent::__construct();

		$this->guestCallPolicy = $guestCallPolicy ?? new GuestCallPolicy();
	}

	public function onBeforeAction(Event $event): ?EventResult
	{
		$userId = (int)(CurrentUser::get()->getId() ?? 0);
		if ($userId <= 0)
		{
			return null;
		}

		if (!$this->shouldDeny($userId))
		{
			return null;
		}

		$this->addError(new Error($this->getDenialMessage(), self::ERROR_ACCESS_DENIED));

		return new EventResult(EventResult::ERROR, null, null, $this);
	}

	protected function shouldDeny(int $userId): bool
	{
		return $this->guestCallPolicy->isGuest($userId);
	}

	/**
	 * What the refusal says. An heir whose denial is conditional has to say so: the same code with the same
	 * text would leave "guests never may" and "the portal has it switched off" indistinguishable in the log.
	 */
	protected function getDenialMessage(): string
	{
		return 'Action is not available for guest users';
	}
}
