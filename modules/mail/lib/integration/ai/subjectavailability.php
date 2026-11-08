<?php

namespace Bitrix\Mail\Integration\AI;

use Bitrix\Intranet;
use Bitrix\Mail\Integration\Crm\Permissions;
use Bitrix\Main\Loader;

/**
 * Tells whether the AI email-subject generation is available for the current
 * portal. The decision is made entirely on the server (module availability, the
 * intranet membership of the user and the mail BitrixGPT tuning switchers); the
 * client must not recompute it.
 *
 * This is the single predicate of the feature: the flag that decides whether the
 * button is drawn and the gate that lets the generation run both ask it, so a
 * button is never shown for a composition the server would refuse (AC-002).
 */
class SubjectAvailability
{
	public function isAvailable(bool $crm = false): bool
	{
		if (!$this->areRequiredModulesAvailable() || !$this->isIntranetUser())
		{
			return false;
		}

		return $crm
			? $this->isCrmSubjectGenerationEnabled() && $this->hasAccessToCrm()
			: $this->isSubjectGenerationEnabled();
	}

	/**
	 * Whether CRM subject generation is available for a specific CRM entity the
	 * message is being composed for. Unlike isAvailable(true), which answers the
	 * UI question "may the button be shown", this confirms the CRM mode against
	 * verifiable data: the entity itself plus the read permission on it. That is
	 * what makes the CRM switcher a server-side decision instead of a
	 * client-supplied flag.
	 */
	public function isAvailableForCrmEntity(string $ownerType, int $ownerId): bool
	{
		if (!$this->areRequiredModulesAvailable() || !$this->isIntranetUser())
		{
			return false;
		}

		return $this->isCrmSubjectGenerationEnabled() && $this->canReadCrmEntity($ownerType, $ownerId);
	}

	protected function areRequiredModulesAvailable(): bool
	{
		return Loader::includeModule('ai');
	}

	/**
	 * The AI quota belongs to the portal, so a user outside the intranet may not spend it. A portal
	 * without the intranet module has no extranet to tell anybody apart from, so everybody there
	 * counts as an intranet user.
	 */
	protected function isIntranetUser(): bool
	{
		return !Loader::includeModule('intranet') || Intranet\Util::isIntranetUser();
	}

	protected function isSubjectGenerationEnabled(): bool
	{
		return Settings::instance()->isMailSubjectCopilotEnabledInGlobalSettings();
	}

	protected function isCrmSubjectGenerationEnabled(): bool
	{
		return Settings::instance()->isMailCrmSubjectCopilotEnabledInGlobalSettings();
	}

	protected function hasAccessToCrm(): bool
	{
		return Permissions::getInstance()->hasAccessToCrm();
	}

	/**
	 * Resolves the client-supplied owner type (either a numeric entity type id or
	 * its symbolic name) and checks the read permission on that entity. An
	 * unknown type resolves to CCrmOwnerType::Undefined, which the permission
	 * check rejects.
	 */
	protected function canReadCrmEntity(string $ownerType, int $ownerId): bool
	{
		if (!Loader::includeModule('crm'))
		{
			return false;
		}

		$entityTypeId = is_numeric($ownerType)
			? (int)$ownerType
			: \CCrmOwnerType::ResolveID($ownerType)
		;

		return Permissions::getInstance()->canReadEntity($entityTypeId, $ownerId);
	}
}
