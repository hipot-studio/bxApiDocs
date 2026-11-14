<?php

declare(strict_types=1);

use Bitrix\Disk\Internal\Service\Quota\QuotaTableSettings;
use Bitrix\Disk\Public\Enum\RestrictionPolicy;
use Bitrix\Disk\Public\Provider\RestrictionsAvailabilityProvider;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

Loc::loadMessages(__FILE__);

/**
 * Page shell of the Disk restrictions section: it decides which sections may be shown and hands
 * the page over to the extension.
 *
 * The section is portal-wide administration, so it repeats the check of the API it talks to -
 * a full administrator of the portal and a restriction applied here. A page opened by its
 * address alone answers the same way the menu behaves.
 *
 * Every restriction is a section of its own, and the availability of each is decided before the
 * page is drawn: the composition travels with the page rather than being asked for afterwards.
 * The page refuses to open only when no section is left to show.
 */
class DiskRestrictionsComponent extends CBitrixComponent
{
	private const EXTENSION = 'disk.restrictions-settings';
	private const CONTAINER_ID = 'disk-restrictions-settings';

	/** Restrictions the section holds, in the order the page shows them. */
	private const SECTION_POLICIES = [
		RestrictionPolicy::Quota,
		RestrictionPolicy::Formats,
	];

	/**
	 * @param array<string, mixed> $params Parameters the page shell is included with.
	 * @return array<string, mixed>
	 */
	public function onPrepareComponentParams($params): array
	{
		$params['TITLE_DRAWN_BY_LAYOUT'] = (bool)($params['TITLE_DRAWN_BY_LAYOUT'] ?? false);

		return $params;
	}

	public function executeComponent(): void
	{
		if (!Loader::includeModule('disk'))
		{
			ShowError(Loc::getMessage('DISK_RESTRICTIONS_MODULE_NOT_INSTALLED'));

			return;
		}

		$this->arResult = $this->buildResult();

		$this->setPageTitle();

		$this->includeComponentTemplate();
	}

	private function buildResult(): array
	{
		$isAdmin = CurrentUser::get()->isAdmin();
		$sections = $isAdmin ? $this->collectAvailableSections() : [];

		return [
			'EXTENSION' => self::EXTENSION,
			'CONTAINER_ID' => self::CONTAINER_ID,
			'SECTIONS' => $sections,
			'INITIAL_SECTION' => $sections[0] ?? null,
			'QUOTA_TABLE_SETTINGS' => $this->resolveQuotaTableSettings($sections),
			// The layout of a standalone page draws the title of the section out of setTitle(), so
			// the rail below it would name the same page a second time.
			'SHOW_RAIL_TITLE' => !$this->arParams['TITLE_DRAWN_BY_LAYOUT'],
			'ERROR' => $this->resolveError($isAdmin, $sections),
		];
	}

	/**
	 * Personal settings of the limits table the interface is initialised with.
	 *
	 * They travel with the page rather than being asked for afterwards: a column the administrator
	 * hid must not manage to appear before the answer arrives. A page that shows no limits carries
	 * no settings of their table.
	 *
	 * @param string[] $sections Sections the administrator may open.
	 * @return array{visibleColumns: string[]}|null
	 */
	private function resolveQuotaTableSettings(array $sections): ?array
	{
		if (!in_array(RestrictionPolicy::Quota->value, $sections, true))
		{
			return null;
		}

		return [
			'visibleColumns' => QuotaTableSettings::getVisibleColumns((int)CurrentUser::get()->getId()),
		];
	}

	/**
	 * @return string[] Sections the portal applies, named by their policies.
	 */
	private function collectAvailableSections(): array
	{
		$provider = $this->getAvailabilityProvider();
		$sections = [];

		foreach (self::SECTION_POLICIES as $policy)
		{
			if ($provider->isApplied($policy))
			{
				$sections[] = $policy->value;
			}
		}

		return $sections;
	}

	/**
	 * @param bool $isAdmin Whether the section is opened by a full administrator of the portal.
	 * @param string[] $sections Sections the administrator may open.
	 * @return string|null
	 */
	private function resolveError(bool $isAdmin, array $sections): ?string
	{
		if (!$isAdmin)
		{
			return (string)Loc::getMessage('DISK_RESTRICTIONS_ACCESS_DENIED');
		}

		if (empty($sections))
		{
			return (string)Loc::getMessage('DISK_RESTRICTIONS_UNAVAILABLE');
		}

		return null;
	}

	private function getAvailabilityProvider(): RestrictionsAvailabilityProvider
	{
		return ServiceLocator::getInstance()->get('disk.restrictionsAvailabilityProvider');
	}

	private function setPageTitle(): void
	{
		global $APPLICATION;

		$APPLICATION->setTitle(Loc::getMessage('DISK_RESTRICTIONS_PAGE_TITLE'));
	}
}
