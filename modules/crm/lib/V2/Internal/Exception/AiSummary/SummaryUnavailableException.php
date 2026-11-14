<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Exception\AiSummary;

use Bitrix\Main\AccessDeniedException;

/**
 * The client summary is not available to this user at all: the feature is off for the portal, the
 * plan does not carry it, or the copilot is not there to answer in.
 *
 * Told apart from {@see ElementNotReadableException} because the two are two different answers to the
 * person - one says the feature is not for them, the other that this very client is not (ERR-009).
 *
 * @internal
 */
final class SummaryUnavailableException extends AccessDeniedException
{
	public function __construct()
	{
		parent::__construct('The client summary is not available to this user');
	}
}
