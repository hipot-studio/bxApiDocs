<?php

declare(strict_types=1);

namespace Bitrix\Disk\Controller\Restrictions;

use Bitrix\Disk\Public\Enum\RestrictionPolicy;
use Bitrix\Disk\Public\Enum\RestrictionsAvailabilityState;
use Bitrix\Disk\Public\Provider\RestrictionsAvailabilityProvider;
use Bitrix\Main\Context;
use Bitrix\Main\Engine\Action;
use Bitrix\Main\Error;

/**
 * Access to the restrictions section, shared by the controllers behind it. The section is a
 * portal-wide setting, so every action of it belongs to a full administrator of the portal;
 * rights on the objects of Disk say nothing here.
 *
 * A restriction the portal does not apply saves nothing either: the write node applies no policy
 * there, so a stored setting would be a promise the portal does not keep. The refusal carries the
 * state itself, so the interface tells a purchase apart from a section that is simply not there.
 *
 * The refusals carry the codes and the state, while the text of each is printed by the controller
 * that answers with it: Loc reads the lang file lying beside the file that asks for a phrase, and
 * a section names itself in its own words.
 */
trait SectionAccessTrait
{
	public const ERROR_ACCESS_DENIED = 'ACCESS_DENIED';
	public const ERROR_RESTRICTIONS_UNAVAILABLE = 'RESTRICTIONS_UNAVAILABLE';

	/**
	 * @return string Text of the refusal shown when the section is opened by a regular user.
	 */
	abstract protected function getAccessDeniedMessage(): string;

	/**
	 * @return string Text of the refusal shown when the portal does not apply the restriction.
	 */
	abstract protected function getRestrictionsUnavailableMessage(): string;

	/**
	 * Tells whether the portal applies this restriction and refuses the action when it does not.
	 *
	 * The feature flag of a policy is read by the provider itself, so no check of it stands beside
	 * this one: two places answering one question answer it differently one day.
	 *
	 * @param RestrictionPolicy $policy Restriction the action reads or writes.
	 * @param RestrictionsAvailabilityProvider $availabilityProvider
	 * @return bool
	 */
	protected function checkPolicyIsApplied(
		RestrictionPolicy $policy,
		RestrictionsAvailabilityProvider $availabilityProvider,
	): bool
	{
		$state = $availabilityProvider->getState($policy);
		if ($state === RestrictionsAvailabilityState::Applied)
		{
			return true;
		}

		$this->addError(new Error(
			$this->getRestrictionsUnavailableMessage(),
			self::ERROR_RESTRICTIONS_UNAVAILABLE,
			['availability' => $state->value],
		));

		return false;
	}

	/**
	 * {@inheritDoc}
	 */
	protected function processBeforeAction(Action $action): bool
	{
		if (!$this->isFullAdmin())
		{
			Context::getCurrent()?->getResponse()?->setStatus(403);
			$this->addError(new Error(
				$this->getAccessDeniedMessage(),
				self::ERROR_ACCESS_DENIED,
			));

			return false;
		}

		return parent::processBeforeAction($action);
	}

	protected function isFullAdmin(): bool
	{
		return (bool)$this->getCurrentUser()?->isAdmin();
	}
}
