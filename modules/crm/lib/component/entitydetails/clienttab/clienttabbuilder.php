<?php

namespace Bitrix\Crm\Component\EntityDetails\ClientTab;

use Bitrix\Crm\Binding\OwnerBindingFilter;
use Bitrix\Crm\Component\EntityList\ClientTab\ClientTabContext;
use Bitrix\Crm\Component\EntityList\Grid\Panel\GroupActionsParameter;
use Bitrix\Crm\Feature;
use Bitrix\Crm\Feature\ClientTabs;
use Bitrix\Main\Localization\Loc;
use CCrmOwnerType;

/**
 * Builds the "Contacts" ("Companies") tab of an item card: the contact (company) list in the client tab mode.
 *
 * The tab has no counter. The list is admitted by the signed CLIENT_TAB parameter only,
 * see {@see ClientTabContext}. My company is not a client: the company card does not ask for the tab.
 * The tab is shown only with the feature flag {@see ClientTabs}.
 */
final class ClientTabBuilder
{
	public const TAB_ID_CONTACTS = 'tab_client_contacts';
	public const TAB_ID_COMPANIES = 'tab_client_companies';

	private const PRODUCTS_TAB_ID = 'tab_products';

	/**
	 * @param int $ownerEntityTypeId Type of the card.
	 * @param int $ownerEntityId ID of the card, 0 for a new card.
	 * @param bool $isNew The card is not saved yet (a new card or a copy): the tab is shown disabled.
	 *
	 * @return array|null The tab, or null when the card type has no client tab or the feature is off.
	 */
	public function build(int $ownerEntityTypeId, int $ownerEntityId, bool $isNew = false): ?array
	{
		if (!Feature::enabled(ClientTabs::class))
		{
			return null;
		}

		$childEntityTypeId = self::resolveChildEntityTypeId($ownerEntityTypeId);
		if ($childEntityTypeId === null)
		{
			return null;
		}

		$tabId = $childEntityTypeId === CCrmOwnerType::Company ? self::TAB_ID_COMPANIES : self::TAB_ID_CONTACTS;
		$tab = [
			'id' => $tabId,
			'name' => Loc::getMessage(
				$childEntityTypeId === CCrmOwnerType::Company
					? 'CRM_CLIENT_TAB_BUILDER_TAB_NAME_COMPANIES'
					: 'CRM_CLIENT_TAB_BUILDER_TAB_NAME_CONTACTS'
			),
		];

		if ($isNew || $ownerEntityId <= 0)
		{
			$tab['enabled'] = false;

			return $tab;
		}

		$componentName = $childEntityTypeId === CCrmOwnerType::Company ? 'crm.company.list' : 'crm.contact.list';
		$params = [
			ClientTabContext::PARAM_NAME => [
				'OWNER_ENTITY_TYPE_ID' => $ownerEntityTypeId,
				'OWNER_ENTITY_ID' => $ownerEntityId,
			],
			'ENABLE_TOOLBAR' => true,
			'PRESERVE_HISTORY' => true,
			'TAB_ID' => $tabId,
		] + GroupActionsParameter::forListComponent('bitrix:' . $componentName);

		$tab['loader'] = [
			'serviceUrl' => '/bitrix/components/bitrix/' . $componentName . '/lazyload.ajax.php?&site='
				. SITE_ID
				. '&'
				. bitrix_sessid_get(),
			'componentData' => [
				'template' => '',
				'signedParameters' => \CCrmInstantEditorHelper::signComponentParams($params, $componentName),
			],
		];
		$tab['enabled'] = true;

		return $tab;
	}

	/**
	 * Puts the tab right after "Products". Without "Products" the tab goes first in TABS,
	 * that is second in the card: the template puts "General" in front of TABS.
	 */
	public static function insert(array $tabs, array $tab): array
	{
		$position = 0;
		foreach (array_values($tabs) as $index => $existingTab)
		{
			if (($existingTab['id'] ?? null) === self::PRODUCTS_TAB_ID)
			{
				$position = $index + 1;
				break;
			}
		}

		$tabs = array_values($tabs);
		array_splice($tabs, $position, 0, [$tab]);

		return $tabs;
	}

	/**
	 * A contact card lists companies, the other supported cards list contacts.
	 */
	private static function resolveChildEntityTypeId(int $ownerEntityTypeId): ?int
	{
		$childEntityTypeId = $ownerEntityTypeId === CCrmOwnerType::Contact
			? CCrmOwnerType::Company
			: CCrmOwnerType::Contact
		;

		return OwnerBindingFilter::isSupported($childEntityTypeId, $ownerEntityTypeId) ? $childEntityTypeId : null;
	}
}
