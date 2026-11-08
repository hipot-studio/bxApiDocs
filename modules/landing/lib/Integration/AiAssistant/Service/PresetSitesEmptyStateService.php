<?php

declare(strict_types=1);

namespace Bitrix\Landing\Integration\AiAssistant\Service;

use Bitrix\Bitrix24\Preset\PresetSites;
use Bitrix\Bitrix24\Preset\PresetSitesAI;
use Bitrix\Landing\Integration\AiAssistant\Dto\PresetSitesEmptyStateStatus;
use Bitrix\Landing\Site;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;

/**
 * @internal
 */
class PresetSitesEmptyStateService
{
	public const WINDOW_SECONDS = 300;

	// Checks go from the cheapest cut-off to the most expensive gate: the empty state is a rare
	// state of a freshly created portal, so a regular /sites/ request must leave here as early
	// as possible.
	// $aiAvailable is the result of the same probe as the last gate: a caller that has already
	// asked it in this hit passes it here instead of paying for it twice. Only the raw answer of
	// the probe belongs here - not a flag some caller has already mixed with rights or settings.
	public function getStatus(?bool $aiAvailable = null): PresetSitesEmptyStateStatus
	{
		if (!$this->includeBitrix24Module())
		{
			return PresetSitesEmptyStateStatus::notActual();
		}

		$createdAt = $this->getPortalCreatedAt();
		if ($createdAt <= 0)
		{
			// No creation date - nothing to build the window on, the regular page stays.
			return PresetSitesEmptyStateStatus::notActual();
		}

		$remainingSeconds = $createdAt + self::WINDOW_SECONDS - $this->getCurrentTime();
		if ($remainingSeconds <= 0)
		{
			return PresetSitesEmptyStateStatus::notActual();
		}

		if ($remainingSeconds > self::WINDOW_SECONDS)
		{
			// A remainder longer than the window means the creation date is in the future - the clocks
			// of the controller and of the portal disagree. That is an invalid state, not a lot of time
			// ahead: the empty state would stay actual for the whole skew, and the page would reload
			// into the placeholder again every WINDOW_SECONDS. Fail-open, as for a missing date.
			return PresetSitesEmptyStateStatus::notActual();
		}

		if (!in_array($this->getPortalPresetId(), $this->getSupportedPresetCodes(), true))
		{
			return PresetSitesEmptyStateStatus::notActual();
		}

		if ($this->hasAnyPageSite())
		{
			return PresetSitesEmptyStateStatus::notActual();
		}

		if ($aiAvailable ?? $this->isSitesAiChatPotentiallyAvailable())
		{
			return PresetSitesEmptyStateStatus::notActual(true);
		}

		return PresetSitesEmptyStateStatus::actual($remainingSeconds);
	}

	protected function includeBitrix24Module(): bool
	{
		return Loader::includeModule('bitrix24');
	}

	protected function getPortalCreatedAt(): int
	{
		return (int)Option::get('main', '~controller_date_create', '');
	}

	protected function getCurrentTime(): int
	{
		return time();
	}

	// The preset is written site-scoped by \Bitrix\Bitrix24\Preset\Manager::apply(), so the value is
	// read for the site of the current request; Option::get() falls back to the site-independent
	// value on its own. Without SITE_ID there is no site to ask for, and false leaves the choice to
	// Option::get(), which reads the default site then.
	protected function getPortalPresetId(): string
	{
		$siteId = (defined('SITE_ID') && (string)SITE_ID !== '') ? (string)SITE_ID : false;

		return (string)Option::get('bitrix24', 'preset:id', '', $siteId);
	}

	protected function getSupportedPresetCodes(): array
	{
		return [PresetSites::CODE, PresetSitesAI::CODE];
	}

	protected function hasAnyPageSite(): bool
	{
		$result = Site::getList($this->getPageSiteQuery());

		return (bool)$result->fetch();
	}

	// The query behind hasAnyPageSite(), kept apart so the contract of the filter can be read
	// without a portal behind it.
	protected function getPageSiteQuery(): array
	{
		// Deleted sites count too: the portal is not empty for the user while they are in the
		// recycle bin. The explicit '=DELETED' also keeps the data layer from adding its own
		// '=DELETED' => 'N' default.
		// 'CHECK_PERMISSIONS' => 'N' turns off the access filter SiteTable::setAccessFilter() adds
		// to every other request: the question here is whether the portal holds a PAGE site at all,
		// not whether the current user may see one. Without it a site created in these first five
		// minutes by another user, with object rights closed to everyone else, would stay invisible
		// here, and the empty state would keep the placeholder and the polling of a portal that is
		// no longer empty. The key is read by the access filter and dropped by SiteTable::getList()
		// before the query is built, so it never reaches the ORM.
		// The explicit '=TYPE' holds only while the request has no scope class set: Site::getList()
		// keeps the given type only if it is among \Bitrix\Landing\Site\Type::getFilterType(), and
		// silently replaces it with the types of the scope otherwise. Both callers of the service
		// stay scope-less - the polling endpoint sets no scope, and the setScope('PAGE') of
		// landing.start has no scope class behind it - so a caller from a scoped section (group,
		// knowledge base, vibe) would need its own way of counting the sites.
		// '=SPECIAL' => 'N' repeats the filter of the site list itself (landing.sites/class.php):
		// service sites are of type PAGE too - the CRM Forms site is created by another module
		// behind the user's back - but the list never shows them, so they must not answer the
		// question whether the user sees an empty section.
		return [
			'select' => ['ID'],
			'filter' => [
				'CHECK_PERMISSIONS' => 'N',
				'=DELETED' => ['Y', 'N'],
				'=TYPE' => 'PAGE',
				'=SPECIAL' => 'N',
			],
			'limit' => 1,
		];
	}

	protected function isSitesAiChatPotentiallyAvailable(): bool
	{
		return (new AiSiteChatAvailabilityService())->isSitesAiChatPotentiallyAvailable();
	}
}
