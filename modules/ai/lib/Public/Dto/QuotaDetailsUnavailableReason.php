<?php

declare(strict_types=1);

namespace Bitrix\AI\Public\Dto;

/**
 * Why the limit details of an employee or of the portal cannot be shown on the limit wall.
 */
enum QuotaDetailsUnavailableReason: string
{
	/** The vibecodeconnector module or its AiLimitsService is not available. */
	case NoModule = 'no_module';
	/** AiLimitsService gave no answer or failed. */
	case NoAnswer = 'no_answer';
	/** The portal is not linked to the platform for this employee. */
	case Unlinked = 'unlinked';
	/** The answer does not have the expected shape. */
	case Malformed = 'malformed';
	/** No reset time can be derived from the answer. */
	case NoResetAt = 'no_reset_at';
}
