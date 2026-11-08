<?php

declare(strict_types=1);

namespace Bitrix\Landing\Infrastructure\Rest\Exception;

use Bitrix\Rest\V3\Exception\SkipWriteToLogException;

/**
 * The free draft a generation is started on could not be taken: the named db lock guarding it gave
 * up while another call of the same user held it, or the draft row could not be prepared. Both are
 * transient, so the call is worth repeating - unlike BindingUnavailableException, which says the
 * draft is occupied by a generation that is already running.
 */
class FreeDraftUnavailableException extends LandingRestException implements SkipWriteToLogException
{
	protected const STATUS = \CRestServer::STATUS_CONFLICT;

	public function getRegistryCode(): string
	{
		return 'LANDING_AI_SITE_FREE_DRAFT_UNAVAILABLE';
	}
}
