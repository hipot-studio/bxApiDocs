<?php

namespace Bitrix\StaffTrack\Controller;

use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\ActionFilter\Attribute\Rule\HttpMethod;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\StaffTrack\Public\Provider\CheckInHandoffProvider;

/**
 * The check-in of the person calling. The intent is consumed rather than read - the answer is given once
 * and the record is gone with it - so the action is restricted to POST by an attribute: it is appended to
 * the prefilters of the base controller (Authentication, Csrf), while an override would replace them.
 *
 * The owner of the intent is the current user and never a parameter: the answer carries the chat of that
 * person along with its name.
 */
class CheckIn extends Controller
{
	#[HttpMethod([ActionFilter\HttpMethod::METHOD_POST])]
	public function consumeHandoffIntentAction(): array
	{
		return (new CheckInHandoffProvider())->consumeIntent(
			(int)CurrentUser::get()->getId()
		);
	}
}
