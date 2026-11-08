<?php

namespace Bitrix\HumanResources\Integration\UI\EntitySelector;

use Bitrix\HumanResources\Integration\UI\StructureRoleIcon;
use Bitrix\Main\Access\AccessCode;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\UI\Selector\UserGroups;
use Bitrix\UI\EntitySelector\BaseProvider;
use Bitrix\UI\EntitySelector\Dialog;
use Bitrix\UI\EntitySelector\Item;
use Bitrix\UI\EntitySelector\Tab;

class UserGroupProvider extends BaseProvider
{
	private const IMAGE_COMPANY = '/bitrix/js/humanresources/entity-selector/src/images/company.svg';

	public function __construct()
	{
		parent::__construct();
	}

	public function isAvailable(): bool
	{
		return true;
	}

	/**
	 * @param array<int> $ids
	 *
	 * @return Item[]
	 */
	public function getItems(array $ids): array
	{
		$result = [];

		$userGroupsItems = $this->getItemsData();

		$itemsToProcess = !empty($ids)
			? array_intersect_key($userGroupsItems, array_flip($ids))
			: $userGroupsItems
		;

		foreach ($itemsToProcess as $id => $groupData)
		{
			$item = new Item(
				[
					'id' => $id,
					'entityId' => 'user-groups',
					'tabs' => ['user-groups'],
					'title' => $this->buildTitle($id, $groupData['name']),
				],
			);

			$icon = $this->getItemIcon((string)$id);
			$item->setAvatar($icon);
			$item->getNodeOptions()->set('avatar', $icon);
			$item->getTagOptions()->set('avatar', $this->getTagIcon((string)$id));

			$result[] = $item;
		}

		return $result;
	}

	public function fillDialog(Dialog $dialog): void
	{
		parent::fillDialog($dialog);

		$dialog->addTab(
			new Tab(
				[
					'id' => 'user-groups',
					'title' => Loc::getMessage('HUMANRESOURCES_UI_ENTITYSELECTOR_USERGROUPPROVIDER_TAB_TITLE'),
					'icon' => [
						'default' => 'o-company',
						'selected' => 's-company',
					],
				],
			),
		);

		$dialog->addItems($this->getItems([]));
	}

	private function buildTitle(string $id, ?string $userGroupOriginalName): string
	{
		return match ($id)
		{
			AccessCode::ACCESS_DIRECTOR . '0' => Loc::getMessage('HUMANRESOURCES_UI_ENTITYSELECTOR_USERGROUPPROVIDER_TITLE_DIRECTOR_MSGVER_1'),
			AccessCode::ACCESS_EMPLOYEE . '0' => Loc::getMessage('HUMANRESOURCES_UI_ENTITYSELECTOR_USERGROUPPROVIDER_TITLE_EMPLOYEE_MSGVER_1'),
			AccessCode::ACCESS_DEPUTY . '0' => Loc::getMessage('HUMANRESOURCES_UI_ENTITYSELECTOR_USERGROUPPROVIDER_TITLE_DEPUTY_MSGVER_1'),
			default => $userGroupOriginalName ?? '',
		};
	}

	/**
	 * Selected state is drawn on a colored tag background, so roles use the light icon variant
	 * the same way StructureRoleProvider does.
	 */
	private function getTagIcon(string $id): string
	{
		return match ($this->getItemIcon($id))
		{
			StructureRoleIcon::TEAM => StructureRoleIcon::TEAM_SELECTED,
			StructureRoleIcon::DEPARTMENT => StructureRoleIcon::DEPARTMENT_SELECTED,
			default => self::IMAGE_COMPANY,
		};
	}

	private function getItemIcon(string $id): string
	{
		return match ((new AccessCode($id))->getEntityType())
		{
			AccessCode::TYPE_ACCESS_TEAM_DIRECTOR,
			AccessCode::TYPE_ACCESS_TEAM_EMPLOYEE,
			AccessCode::TYPE_ACCESS_TEAM_DEPUTY => StructureRoleIcon::TEAM,
			AccessCode::TYPE_ACCESS_DIRECTOR,
			AccessCode::TYPE_ACCESS_EMPLOYEE,
			AccessCode::TYPE_ACCESS_DEPUTY => StructureRoleIcon::DEPARTMENT,
			default => self::IMAGE_COMPANY,
		};
	}

	/**
	 * @return array<string, array{id: int, entityId: 0, name: string, desc: string}>
	 */
	private function getItemsData(): array
	{
		return [
			...((new UserGroups())->getData()['ITEMS'] ?? []),
			...$this->getTeamItems(),
		];
	}

	/**
	 * @return array<string, array{id: int, entityId: 0, name: string, desc: string}>
	 */
	private function getTeamItems(): array
	{
		return [
			AccessCode::ACCESS_TEAM_DIRECTOR . '0' => [
				'id' => AccessCode::ACCESS_TEAM_DIRECTOR . '0',
				'entityId' => 0,
				'name' => Loc::getMessage('HUMANRESOURCES_UI_ENTITYSELECTOR_USERGROUPPROVIDER_TITLE_TEAM_DIRECTOR'),
				'desc' => '',
			],
			AccessCode::ACCESS_TEAM_EMPLOYEE. '0' => [
				'id' => AccessCode::ACCESS_EMPLOYEE . '0',
				'entityId' => 0,
				'name' => Loc::getMessage('HUMANRESOURCES_UI_ENTITYSELECTOR_USERGROUPPROVIDER_TITLE_TEAM_MEMBER'),
				'desc' => '',
			],
			AccessCode::ACCESS_TEAM_DEPUTY . '0' => [
				'id' => AccessCode::ACCESS_TEAM_DEPUTY . '0',
				'entityId' => 0,
				'name' => Loc::getMessage('HUMANRESOURCES_UI_ENTITYSELECTOR_USERGROUPPROVIDER_TITLE_TEAM_DEPUTY'),
				'desc' => '',
			],
		];
	}
}
