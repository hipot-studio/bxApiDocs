<?php

namespace Bitrix\Intranet\User\Filter;

use Bitrix\Intranet\CurrentUser;
use Bitrix\Intranet\User\Filter\Presets\FilterPresetManager;
use Bitrix\Main\DB\SqlExpression;
use Bitrix\Main\Filter\DataProvider;
use Bitrix\Main\Filter\Filter;
use Bitrix\Main\Loader;
use Bitrix\Main\UI\Filter\Options;
use Bitrix\Main\PhoneNumber;
use Bitrix\Socialnetwork\UserToGroupTable;

class UserFilter extends Filter
{
	private Options $filterOptions;
	private array $filterPresets;
	private ?IntranetUserSettings $filterSettings = null;
	protected $uiFilterServiceFields = [
		'FIRED',
		'ADMIN',
		'EXTRANET',
		'VISITOR',
		'INVITED',
		'INTEGRATOR',
		'TAGS',
		'DEPARTMENT',
		'GENDER',
		'BIRTHDAY',
		'PHONE_MOBILE',
		'PHONE',
		'POSITION',
		'COMPANY',
		'FULL_NAME',
		'WAIT_CONFIRMATION',
		'IN_COMPANY',
		'PHONE_APPS',
		'DESKTOP_APPS',
	];

	public function __construct(
		$ID,
		DataProvider $entityDataProvider,
		array $extraDataProviders = null,
		array $params = null,
		array $additionalPresets = [],
	)
	{
		parent::__construct($ID, $entityDataProvider, $extraDataProviders, $params);

		$fields = $this->getFields();

		$defaultFilterIds = $this->getDefaultFieldIDs();
		$defaultFieldsValues = [];

		foreach ($defaultFilterIds as $fieldId)
		{
			$value = match ($fields[$fieldId]) {
				'dest_selector' => false,
				default => '',
			};
			$defaultFieldsValues[$fieldId] = $value;
		}

		if (isset($params['FILTER_SETTINGS']) && $params['FILTER_SETTINGS'] instanceof IntranetUserSettings)
		{
			$this->filterSettings = $params['FILTER_SETTINGS'];
		}

		$presetManager = new FilterPresetManager($this->filterSettings, $additionalPresets);
		$this->filterPresets = $presetManager->getPresets();

		$this->filterOptions = new Options(
			$this->getId(),
			$presetManager->getPresetsArrayData($defaultFieldsValues)
		);

		foreach ($presetManager->getDisabledPresets() as $preset)
		{
			$this->filterOptions->deleteFilter($preset->getId(), false);
		}

		$this->filterOptions->save();
	}

	public function getFilterSettings(): ?IntranetUserSettings
	{
		return $this->filterSettings;
	}

	/**
	 * @return array of default and saved presets
	 */
	public function getFilterPresets(): array
	{
		return array_merge(
			$this->filterOptions->getPresets(),
			$this->filterOptions->getDefaultPresets()
		);
	}

	public function getDefaultFilterPresets(): array
	{
		return $this->filterPresets;
	}

	public function removeServiceUiFilterFields(array &$filter): void
	{
		parent::removeServiceUiFilterFields($filter);

		foreach ($filter as $fieldId => $fieldValue)
		{
			if (in_array($fieldId, $this->uiFilterServiceFields, true))
			{
				unset($filter[$fieldId]);
			}
		}
	}

	public function getValue(?array $rawValue = null): array
	{
		if (!isset($rawValue))
		{
			$rawValue =
				$this->filterOptions->getFilter()
				+ $this->filterOptions->getFilterLogic($this->getFieldArrays())
			;
		}

		if (!empty($rawValue['FIND']))
		{
			$searchString = $rawValue['FIND'];
		}
		else
		{
			$searchString = $this->filterOptions->getSearchString();
		}

		$result = $rawValue;
		$this->removeNotUiFilterFields($result);
		$this->prepareListFilterParams($result);
		$this->prepareFilterValue($result);
		$this->removeServiceUiFilterFields($result);
		$this->addSearchFilter($result, $searchString);

		if (
			Loader::includeModule('extranet')
			&& !\CExtranet::isExtranetAdmin()
			&& (
				(
					isset($result['=UF_DEPARTMENT'])
					&& $result['=UF_DEPARTMENT']
				)
				|| !\CExtranet::isIntranetUser()
				|| !isset($result['=UF_DEPARTMENT'])
			)
			&& (
				!isset($result['!UF_DEPARTMENT'])
				|| $result['!UF_DEPARTMENT'] !== false
				|| !\CExtranet::isIntranetUser()
			)
			&& Loader::includeModule('socialnetwork')
		)
		{
			$workgroupIdList = [];
			$res = UserToGroupTable::getList([
				'filter' => [
					'=USER_ID' => CurrentUser::get()->getId(),
					'@ROLE' => UserToGroupTable::getRolesMember(),
					'=GROUP.ACTIVE' => 'Y'
				],
				'select' => [ 'GROUP_ID' ]
			]);

			while ($userToGroupFields = $res->fetch())
			{
				$workgroupIdList[] = $userToGroupFields['GROUP_ID'];
			}
			$workgroupIdList = array_unique($workgroupIdList);

			if (
				!isset($filter['UF_DEPARTMENT'])
				&& \CExtranet::isIntranetUser()
			)
			{
				if (!empty($workgroupIdList))
				{
					$subQuery = new \Bitrix\Main\Entity\Query(UserToGroupTable::getEntity());
					$subQuery->addSelect('USER_ID');
					$subQuery->addFilter('@ROLE', [UserToGroupTable::ROLE_REQUEST, UserToGroupTable::ROLE_USER]);
					$subQuery->addFilter('@GROUP_ID', $workgroupIdList);
					$subQuery->addGroup('USER_ID');

					$result[] = [
						'LOGIC' => 'OR',
						[
							'!UF_DEPARTMENT' => false
						],
						[
							'@ID' => new SqlExpression($subQuery->getQuery())
						],
					];
				}
				else
				{
					$result[] = ['!UF_DEPARTMENT' => false];
				}
			}
			else
			{
				$res = \Bitrix\Main\UserTable::getList([
					'filter' => [
						'!UF_DEPARTMENT' => false,
						'=UF_PUBLIC' => true,
					],
					'select' => [ 'ID' ]
				]);

				$publicUserIdList = [];
				while($userFields = $res->fetch())
				{
					$publicUserIdList[] = (int)$userFields['ID'];
				}

				if (
					empty($workgroupIdList)
					&& empty($publicUserIdList)
				)
				{
					$result[] = ['ID' => CurrentUser::get()->getId()];
				}
				else if (!empty($workgroupIdList))
				{
					if (!empty($publicUserIdList))
					{
						$result[] = [
							'LOGIC' => 'OR',
							[
								'<=UG.ROLE' => UserToGroupTable::ROLE_USER,
								'@UG.GROUP_ID' => $workgroupIdList
							],
							[
								'@ID' => $publicUserIdList
							],
						];
					}
					else
					{
						$result[] = ['<=UG.ROLE' => UserToGroupTable::ROLE_USER];
						$result[] = ['@UG.GROUP_ID' => $workgroupIdList];
					}
				}
				else
				{
					$result[] = ['@ID' => $publicUserIdList];
				}
			}
		}

		if (isset($result['=UF_DEPARTMENT']))
		{
			$subDepartments = \CIntranetUtils::getSubStructure($result['=UF_DEPARTMENT']);

			if (!empty($subDepartments))
			{
				$subDepartmentsIds = [
					$result['=UF_DEPARTMENT'],
					...array_keys($subDepartments['DATA']),
				];
				$result['@UF_DEPARTMENT'] = $subDepartmentsIds;
				unset($result['=UF_DEPARTMENT']);
			}
		}

		return $result;
	}

	private function addSearchFilter(&$result, string $searchString): void
	{
		if ($searchString !== '')
		{
			$matchesPhones = [];
			$phoneParserManager = PhoneNumber\Parser::getInstance();
			preg_match_all('/'.$phoneParserManager->getValidNumberPattern().'/i', $searchString, $matchesPhones);

			if (
				!empty($matchesPhones)
				&& !empty($matchesPhones[0])
			)
			{
				foreach ($matchesPhones[0] as $phone)
				{
					$convertedPhone = PhoneNumber\Parser::getInstance()
						->parse($phone)
						->format(PhoneNumber\Format::E164);
					$searchString = str_replace($phone, $convertedPhone, $searchString);
				}
			}

			$findFilter = \Bitrix\Main\UserUtils::getAdminSearchFilter([
				'FIND' => $searchString
			]);

			if (!empty($findFilter))
			{
				$result = array_merge($result, $findFilter);
			}
		}
	}
}