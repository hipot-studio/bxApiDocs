<?php

namespace Bitrix\Mobile\Internal\Onboarding\Audience;

/**
 * Who the campaign is allowed to walk at all.
 *
 * This is not a check of a step and not the eligibility of an action: those answer about one
 * user and one deed, while this answers who the new engine is being run for in the first
 * place. It is a limit of the rollout, asked once per user of a pass and before every read the
 * pass would otherwise spend on him.
 *
 * Lifting the limit is replacing the implementation the pipeline is built with
 * (`PortalOwnerAudience`) - nothing else in the campaign knows the rule.
 */
interface CampaignAudience
{
	public function includes(int $userId): bool;
}
