<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Enum;

/**
 * Why an entry into the agent contour was allowed or refused.
 *
 * Four outcomes rather than a boolean, because the caller has to tell them apart: the editor turns them
 * into its own errors, and the REST entry answers each with a different V3 exception. That difference is
 * what lets a caller of the contour distinguish "the portal has the feature switched off" from "this user
 * may not touch this template" without the contour publishing a machine code of its own.
 *
 * The order of the checks is fixed and meaningful: the feature gate answers before anything is looked up,
 * so a portal without the feature never reveals whether a template id exists.
 */
enum AgentEntryVerdict
{
	case Allowed;

	/** The external AI agent feature is not accessible: rollout option off, no AI tariff, or wrong region. */
	case FeatureUnavailable;

	/** The feature is on, but no template of that id is readable for this user. */
	case TemplateNotFound;

	/** The template exists and the user may not author on its document type. */
	case AccessDenied;
}
