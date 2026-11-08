<?php

namespace Bitrix\Landing\Controller;

use Bitrix\Landing\Integration\AiAssistant\Dto\PresetSitesEmptyStateStatus;
use Bitrix\Landing\Integration\AiAssistant\Service\PresetSitesEmptyStateService;
use Bitrix\Landing\Manager;
use Bitrix\Landing\Rights;
use Bitrix\Main\Engine;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;

class PresetEmptyState extends Controller
{
	// The base set of the engine is kept and only extended: replacing it would drop the csrf check
	// and the method gate for every action added here later, and each of them would have to remember
	// to name both in configureActions() to get them back.
	public function getDefaultPreFilters(): array
	{
		return array_merge(parent::getDefaultPreFilters(), [
			new ActionFilter\Extranet(),
		]);
	}

	public function configureActions(): array
	{
		return [
			'status' => [
				// The base gate allows GET too, and a csrf check is worth nothing on a GET.
				'-prefilters' => [
					Engine\ActionFilter\HttpMethod::class,
				],
				'+prefilters' => [
					new Engine\ActionFilter\HttpMethod([
						Engine\ActionFilter\HttpMethod::METHOD_POST,
					]),
					// The action only reads, so the session lock held through it buys nothing and costs the
					// rest of the tab: polled every five seconds for up to five minutes, it would serialize
					// every parallel request of the same user behind that lock, down to the most expensive
					// check of the action. The filter stays the last of the list because the csrf check of
					// the base set is the one gate that still writes to the session - bitrix_sessid() stores
					// a missing token id - while everything the action itself reaches (rights, options, the
					// status service) only reads it.
					new Engine\ActionFilter\CloseSession(),
				],
			],
		];
	}

	/**
	 * Returns actual state of the preset sites empty state for the current portal.
	 * @return array|null
	 */
	public function statusAction(): ?array
	{
		if (
			!$this->hasAdditionalRight(Rights::ADDITIONAL_RIGHTS['menu24'])
			|| $this->isCutOffByAdminsOnlyOption()
		)
		{
			$this->addError(new Error('Insufficient permissions to read sites section.', 'ACCESS_DENIED'));

			return null;
		}

		$status = $this->getEmptyStateStatus();

		return [
			'actual' => $status->isActual(),
			'aiAvailable' => $status->isAiAvailable(),
			'remainingSeconds' => $status->getRemainingSeconds(),
		];
	}

	// The three seams below carry the only calls of the action to the outside: the gates of the
	// section stay the first statement of it, and the service is asked after them, never before.
	// The section gate has one more statement, Role::checkRequiredRoles(), that is deliberately not
	// among them: it installs the missing default rights of the roles of the current scope instead
	// of checking anything (it refuses nobody and returns void), so it neither narrows nor widens
	// the circle answered here - and its writes have no place in a request repeated every five
	// seconds. The ajax gateway of the module, PublicAction::actionProcessing(), leaves it out of
	// the same pair of checks for the same reason.
	protected function hasAdditionalRight(string $right): bool
	{
		// The same circle the section itself lets in (landing.start/component.php): extra rights
		// included, so a user who is let into /sites/ by them - and therefore sees the empty state -
		// is answered here too. Only the order of the two is different. The section asks
		// Rights::hasAdditionalRight() once with the extra rights on, and that call starts with
		// them: it loads crm and reads the crm permissions of the user before it ever looks at the
		// plain right, even for a portal admin. The answer is the OR of the two, so asking the plain
		// right first gives the same one and reaches the extra rights only when it refuses - which
		// is what a point polled every five seconds for five minutes needs.
		// The scope argument stays null in both calls, as it is in the section: 'PAGE' has no scope
		// class, so the checked right code is the same one everywhere.
		return $this->checkAdditionalRight($right, false)
			|| $this->checkAdditionalRight($right, true);
	}

	// The single call of the right of the module, kept apart from the order above so the tests can
	// observe what the order asks for.
	protected function checkAdditionalRight(string $right, bool $checkExtraRights): bool
	{
		return Rights::hasAdditionalRight($right, null, $checkExtraRights);
	}

	// The other cut-off of the section (landing.start/component.php): while the option is on, the
	// section belongs to portal admins only, and everyone else is sent to the auth form. Without it
	// the polling point would answer a user who cannot open the page the empty state lives on. The
	// section asks it before the right; here it stays after, because the two are ANDed either way
	// and the right gate is pinned as the first statement of the action. The module check is the one
	// of the section too: on box there is neither the option nor \CBitrix24 behind it.
	protected function isCutOffByAdminsOnlyOption(): bool
	{
		if (!Loader::includeModule('bitrix24'))
		{
			return false;
		}

		return (bool)Manager::getOption('temp_permission_admin_only')
			&& !\CBitrix24::isPortalAdmin(Manager::getUserId());
	}

	protected function getEmptyStateStatus(): PresetSitesEmptyStateStatus
	{
		return (new PresetSitesEmptyStateService())->getStatus();
	}
}
