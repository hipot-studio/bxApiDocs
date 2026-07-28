<?php

namespace Bitrix\Intranet\User;

use Bitrix\Intranet\Internal\Integration\Humanresources\UserDepartmentProvider;
use Bitrix\Intranet\Internal\Integration\Humanresources\UserQueryModifier;
use Bitrix\Intranet\User\Filter\ExtranetUserSettings;
use Bitrix\Intranet\User\Filter\IntranetUserSettings;
use Bitrix\Intranet\User\Filter\Presets\FilterPreset;
use Bitrix\Intranet\User\Filter\Provider\IntegerUserDataProvider;
use Bitrix\Intranet\User\Filter\Provider\IntranetUserDataProvider;
use Bitrix\Intranet\User\Filter\Provider\PhoneUserDataProvider;
use Bitrix\Intranet\User\Filter\UserFilter;
use Bitrix\Intranet\UserTable;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Filter\UserDataProvider;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\SystemException;

final class UserManager
{
	public const SORT_STRUCTURE = ['STRUCTURE_SORT' => 'DESC'];
	public const SORT_INVITATION = ['INVITATION_DATE_SORT' => 'DESC'];
	public const SORT_APH = ['NAME' => 'ASC'];
	public const SORT_INVITED = ['INVITED_SORT' => 'ASC'];
	public const SORT_WAITING_CONFIRMATION = ['WAITING_CONFIRMATION_SORT' => 'ASC'];

	private UserFilter $filter;

	/**
	 * @param string $filterId ID of filter for getting last saved filter preset
	 */
	public function __construct(string $filterId, array $additionalPresets = [])
	{
		$filterParams = ['ID' => $filterId];
		$filterSettings = ModuleManager::isModuleInstalled('extranet')
			? new ExtranetUserSettings($filterParams)
			: new IntranetUserSettings($filterParams);

		$extraProviders = [
			new IntranetUserDataProvider($filterSettings),
			new IntegerUserDataProvider($filterSettings),
			new PhoneUserDataProvider($filterSettings),
		];

		if (ModuleManager::isModuleInstalled('extranet'))
		{
			$extraProviders[] = new \Bitrix\Intranet\User\Filter\Provider\ExtranetUserDataProvider($filterSettings);
		}

		$this->filter = new UserFilter(
			$filterId,
			new UserDataProvider($filterSettings),
			$extraProviders,
			[
				'FILTER_SETTINGS' => $filterSettings,
			],
			$additionalPresets,
		);
	}

	/**
	 * @param array $params Array of getList params
	 * @return array
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function getList(array $params = [], ?string $presetId = null, ?string $search = null): array
	{
		$result = [];

		if (
			!array_key_exists('select', $params)
			|| !is_array($params['select'])
			|| !array_key_exists('ID', $params['select'])
		)
		{
			$params['select'][] = 'ID';
		}

		$presets = $this->filter->getDefaultFilterPresets();
		$currPreset = null;

		foreach ($presets as $preset)
		{
			if ($preset->getId() === $presetId)
			{
				$currPreset = $preset;

				break;
			}
		}

		$params['filter'] ??= [];

		if ($currPreset !== null)
		{
			$params['filter'] = array_merge($params['filter'], $currPreset->getFilterFields());
		}

		if (!empty($search))
		{
			$params['filter']['FIND'] = $search;
		}

		if (isset($params['select']) && is_array($params['select']))
		{
			$params['select'] = array_values(array_filter(
				$params['select'],
				static fn(mixed $field): bool => $field !== 'UF_DEPARTMENT',
			));
		}

		$params['filter'] = $this->filter->getValue($params['filter'] ?? null);

		$query = UserTable::query();
		$query->setSelect($params['select'])
			->where('REAL_USER', 'expr', true)
			->setFilter($params['filter'])
			->setDistinct(true);

		if (isset($params['order']))
		{
			$userQueryModifier = new UserQueryModifier();
			$useStructureSort = array_key_exists('STRUCTURE_SORT', $params['order']);

			if ($useStructureSort)
			{
				$userQueryModifier->injectStructureSort(
					$query,
					$this->filter->getFilterSettings()?->getCurrentUserId() ?? 0
				);
				unset($params['order']['STRUCTURE_SORT']);
			}

			foreach ($params['order'] as $field => $direction)
			{
				$query->addOrder($field, $direction);
			}
		}

		if (isset($params['limit']))
		{
			$query->setLimit($params['limit']);
		}

		if (isset($params['offset']))
		{
			$query->setOffset($params['offset']);
		}

		$userList = $query->fetchAll();
		$userDepartmentMap = (new UserDepartmentProvider())->getMapByUserIds(
			array_map('intval', array_column($userList, 'ID')),
		);

		foreach ($userList as $user)
		{
			$user['UF_DEPARTMENT'] = [];
			foreach ($userDepartmentMap[(int)$user['ID']] ?? [] as $department)
			{
				$departmentId = $department->getId();
				if ($departmentId === null)
				{
					continue;
				}

				$user['UF_DEPARTMENT'][$departmentId] = htmlspecialcharsbx($department->getName());
			}

			$userId = $user['ID'];
			$result[$userId]['data'] = $user;
			$result[$userId]['actions'] = [];
		}

		return $result;
	}

	/**
	 * @return FilterPreset[]
	 */
	public function getDefaultFilterPresets(): array
	{
		return $this->filter->getDefaultFilterPresets();
	}

	public function getFilterPresets(): array
	{
		return $this->filter->getFilterPresets();
	}

	/**
	 * @param string $presetId
	 * @return FilterPreset|null
	 */
	public function getDefaultFilterPresetById(string $presetId): null|FilterPreset
	{
		return $this->getDefaultFilterPresets()[$presetId] ?? null;
	}
}
