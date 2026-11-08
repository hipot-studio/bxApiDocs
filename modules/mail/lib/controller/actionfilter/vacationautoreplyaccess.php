<?php

declare(strict_types=1);

namespace Bitrix\Mail\Controller\ActionFilter;

use Bitrix\Mail\Controller\VacationAutoReply;
use Bitrix\Mail\Helper\Config\Feature;
use Bitrix\Main\Context;
use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;

/**
 * Gate of the vacation auto-reply: while the flag of the feature is off, none of the actions of
 * mail.api.vacationautoreply.* runs.
 *
 * The check belongs to the chain of prefilters and not to the controller: a request that carries no
 * session must be answered by the authentication ahead of it, and only a caller who got that far learns
 * whether the feature is switched on. Access to the mailbox itself stays with the actions - it is
 * decided per mailbox of the request and not per portal.
 *
 * Standing last in the chain is not enough by itself: EventManager::send() runs every handler of the
 * event in a flat loop, with neither a break nor a check of the results collected so far, so this filter
 * is called even after the authentication or the CSRF check has already refused the request. That is why
 * the gate reads the results the event carries before it answers anything of its own.
 */
final class VacationAutoReplyAccess extends Base
{
	public const ERROR_FEATURE_DISABLED = VacationAutoReply::ERROR_FEATURE_DISABLED;

	public function onBeforeAction(Event $event)
	{
		if ($this->isRequestAlreadyRefused($event))
		{
			return null;
		}

		if (!Feature::isVacationAutoReplyAvailable())
		{
			Context::getCurrent()->getResponse()->setStatus(403);
			$this->addError(VacationAutoReply::featureDisabledError());

			return new EventResult(EventResult::ERROR, null, null, $this);
		}

		return null;
	}

	/**
	 * A filter ahead of this one has refused the request already. The results are read the way
	 * Controller::triggerOnBeforeAction() reads them: any type but SUCCESS keeps the action from running,
	 * and a filter that let the request through returns null and leaves no result at all.
	 */
	private function isRequestAlreadyRefused(Event $event): bool
	{
		foreach ($event->getResults() as $result)
		{
			if ((int)$result->getType() !== EventResult::SUCCESS)
			{
				return true;
			}
		}

		return false;
	}
}
