<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

\Bitrix\Main\Loader::includeModule('biconnector');

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Access\Permission\PermissionDictionary;
use Bitrix\BIConnector\Access\Service\TableGroupMembershipService;
use Bitrix\BIConnector\Access\Service\TableGroupService;
use Bitrix\BIConnector\Access\Table\TableAccessChecker;
use Bitrix\BIConnector\ExternalSource\Internal\ExternalSourceRestTable;
use Bitrix\BIConnector\ExternalSource\Internal\ExternalSourceTable;
use Bitrix\BIConnector\ExternalSource\SupersetIntegration;
use Bitrix\BIConnector\Integration\Superset\Integrator\IntegratorFactory;
use Bitrix\BIConnector\Integration\Superset\SupersetController;
use Bitrix\BIConnector\Integration\Superset\SupersetInitializer;
use Bitrix\Main\Localization\Loc;
use Bitrix\BIConnector\ExternalSource;
use Bitrix\BIConnector\ExternalSource\Const;
use Bitrix\BIConnector\ExternalSource\FieldType;
use Bitrix\BIConnector\ExternalSource\DatasetManager;
use Bitrix\BIConnector\ExternalSource\Source;
use Bitrix\BIConnector\Configuration\DataTimezone;
use Bitrix\BIConnector\DataSource\SystemDatasetProvider;
use Bitrix\Main\Type;
use Bitrix\Main\Web\Uri;

class DatasetImportV2Component extends CBitrixComponent
{
	private const FIRST_N_ROW = 20;
	private const SYSTEM_PREVIEW_ROWS = 30;

	private ?TableAccessChecker $tableAccessChecker = null;

	/** @var array<int, array{id: int, name: string, type: string}>|null */
	private ?array $editableTableGroups = null;

	public function onPrepareComponentParams($arParams)
	{
		if (!AccessController::getCurrent()->check(ActionDictionary::ACTION_BIC_EXTERNAL_DASHBOARD_CONFIG))
		{
			$this->arResult['ERROR_MESSAGES'][] = Loc::getMessage('BICONNECTOR_CSV_IMPORT_ACCESS_ERROR');

			return $arParams;
		}

		$arParams['datasetId'] = (int)($arParams['datasetId'] ?? 0);
		$arParams['sourceId'] = (string)($arParams['sourceId'] ?? '');
		if (!is_array($arParams['connection'] ?? null))
		{
			$arParams['connection'] = [];
		}
		elseif (!empty($arParams['connection']))
		{
			$connection = [
				'connectionId' => (int)($arParams['connection']['connectionId'] ?? 0),
				'connectionType' => (string)($arParams['connection']['connectionType'] ?? ''),
				'tableName' => (string)($arParams['connection']['tableName'] ?? ''),
				'connectionIsSupportMapping' => (($arParams['connection']['connectionIsSupportMapping'] ?? '') === 'true'),
			];
			$arParams['connection'] = $connection;
		}

		if (!is_array($arParams['sectionsConfig'] ?? null))
		{
			$arParams['sectionsConfig'] = [];
		}
		elseif (!empty($arParams['sectionsConfig']))
		{
			foreach ($arParams['sectionsConfig'] as $section => $config)
			{
				foreach ($config as $key => $value)
				{
					$arParams['sectionsConfig'][$section][$key] = $value === 'true';
				}
			}
		}

		if (empty($arParams['sourceId']))
		{
			$this->arResult['ERROR_MESSAGES'][] = Loc::getMessage('BICONNECTOR_APP_NOT_FOUND');
		}
		elseif (
			self::isNewDataset($arParams)
			&& $this->getTableAccessChecker()->isRestricted()
			&& empty($this->getEditableTableGroups())
		)
		{
			$this->arResult['ERROR_MESSAGES'][] = Loc::getMessage('BICONNECTOR_CSV_IMPORT_TABLE_CREATE_ACCESS_ERROR');
		}

		return parent::onPrepareComponentParams($arParams);
	}

	public function executeComponent()
	{
		if (!empty($this->arResult['ERROR_MESSAGES']))
		{
			$this->includeComponentTemplate();

			return;
		}

		$this->fillInitialData();
		$this->fillHelpdeskCode();
		if ($this->arParams['sourceId'] === ExternalSource\Type::System->value)
		{
			$this->fillSystemDataset();
		}
		elseif ($this->arParams['datasetId'] > 0)
		{
			$this->loadDataset();
		}

		if (empty($this->arResult['ERROR_MESSAGES']))
		{
			$this->fillAppParams();
		}

		$this->includeComponentTemplate();
	}

	private function fillInitialData(): void
	{
		$this->arResult['initialData'] = [
			'config' => [
				'fileProperties' => $this->getDefaultFileProperties(),
				'dataFormats' => [
					FieldType::Date->value => Const\Date::Ymd_dash->value,
					FieldType::DateTime->value => Const\DateTime::Ymd_dash_His_colon->value,
					FieldType::Double->value => Const\DoubleDelimiter::DOT->value,
					FieldType::Money->value => Const\MoneyDelimiter::DOT->value,
					FieldType::Timezone->value => DataTimezone::getTimezone(),
				],
				'sectionsConfig' => $this->arParams['sectionsConfig'],
			],
		];

		if (!empty($this->arParams['connection']))
		{
			$connectionProperties = $this->arParams['connection'];
			if ($this->isNewDatasetMode())
			{
				$connectionTitle = $this->getConnectionTitle((int)($connectionProperties['connectionId'] ?? 0));
				if ($connectionTitle !== '')
				{
					$connectionProperties['connectionName'] = $connectionTitle;
				}
			}

			$this->arResult['initialData']['config']['connectionProperties'] = $connectionProperties;
		}

		if ($this->isNewDatasetMode() && $this->getTableAccessChecker()->isRestricted())
		{
			$this->arResult['initialData']['config']['tableGroupIds'] = $this->getDefaultTableGroupIds();
		}
	}

	/**
	 * Single source of the parsing defaults: loadDataset() falls back to the very same values
	 * for datasets saved before the settings were stored, so they must not drift apart.
	 */
	private function getDefaultFileProperties(): array
	{
		return [
			'encoding' => Const\Encoding::UTF8->value,
			'separator' => Const\Delimiter::COMMA->value,
			'firstLineHeader' => true,
		];
	}

	/**
	 * Parsing settings the dataset was actually imported with.
	 *
	 * The whole set is always returned: the config below is merged with a top-level spread, so a
	 * partial array would silently drop the keys it omits. Empty attributes mean the dataset was
	 * saved before the settings were stored — the form then shows the same defaults as before.
	 *
	 * fileToken is deliberately left out: its absence is what keeps the parsing options read-only
	 * until a replacement file is uploaded.
	 */
	private function internalizeFileProperties(ExternalSource\Internal\ExternalDataset $dataset): array
	{
		$defaults = $this->getDefaultFileProperties();

		// The three attributes are written together, so the delimiter alone tells whether the set
		// exists. The headers flag cannot answer that on its own: BooleanField turns a stored NULL
		// into false, making "unknown" indistinguishable from "explicitly off".
		$delimiter = (string)($dataset->getCsvDelimiter() ?? '');
		if ($delimiter === '')
		{
			return $defaults;
		}

		$encoding = (string)($dataset->getCsvEncoding() ?? '');

		return [
			'encoding' => $encoding !== '' ? $encoding : $defaults['encoding'],
			'separator' => $delimiter,
			'firstLineHeader' => (bool)$dataset->getCsvHasHeaders(),
		];
	}

	private function isNewDatasetMode(): bool
	{
		return self::isNewDataset($this->arParams);
	}

	private static function isNewDataset(array $params): bool
	{
		return $params['datasetId'] <= 0
			&& $params['sourceId'] !== ExternalSource\Type::System->value
		;
	}

	private function getTableAccessChecker(): TableAccessChecker
	{
		$this->tableAccessChecker ??= new TableAccessChecker();

		return $this->tableAccessChecker;
	}

	/**
	 * A table the user may not view is answered as a missing one, the way the section does not list it.
	 */
	private function canViewTable(string $tableCode): bool
	{
		return $this->getTableAccessChecker()->canDo(ActionDictionary::ACTION_BIC_TABLE_VIEW, $tableCode);
	}

	/**
	 * Groups a new table may be put in: those where the user has the right to edit tables.
	 *
	 * @return array<int, array{id: int, name: string, type: string}>
	 */
	private function getEditableTableGroups(): array
	{
		if ($this->editableTableGroups !== null)
		{
			return $this->editableTableGroups;
		}

		$this->editableTableGroups = [];
		foreach (TableGroupService::getGroups() as $group)
		{
			$groupId = PermissionDictionary::getTableGroupIdFromPermission($group['id']);
			if (
				$groupId > 0
				&& $this->getTableAccessChecker()->canUseGroup(ActionDictionary::ACTION_BIC_TABLE_EDIT, $groupId)
			)
			{
				$this->editableTableGroups[] = [
					'id' => $groupId,
					'name' => $group['name'],
					'type' => $group['type'],
				];
			}
		}

		return $this->editableTableGroups;
	}

	/**
	 * A new table starts in the user tables group when the user may put it there; the user may change the choice.
	 *
	 * @return int[]
	 */
	private function getDefaultTableGroupIds(): array
	{
		$userTablesGroupId = TableGroupMembershipService::getUserTablesGroupId();
		if ($userTablesGroupId === null)
		{
			return [];
		}

		$editableGroupIds = array_column($this->getEditableTableGroups(), 'id');

		return in_array($userTablesGroupId, $editableGroupIds, true) ? [$userTablesGroupId] : [];
	}

	/**
	 * Groups the table is bound to, limited to those the user may view, as the table list shows them.
	 *
	 * @param string $tableCode
	 *
	 * @return array<int, array{id: int, name: string, type: string}>
	 */
	private function getVisibleTableGroups(string $tableCode): array
	{
		$tableGroupIds = array_flip(TableGroupMembershipService::getGroupIdsByTableCode($tableCode));
		if (empty($tableGroupIds))
		{
			return [];
		}

		$result = [];
		foreach (TableGroupService::getGroups() as $group)
		{
			$groupId = PermissionDictionary::getTableGroupIdFromPermission($group['id']);
			if (
				isset($tableGroupIds[$groupId])
				&& $this->getTableAccessChecker()->canUseGroup(ActionDictionary::ACTION_BIC_TABLE_VIEW, $groupId)
			)
			{
				$result[] = [
					'id' => $groupId,
					'name' => $group['name'],
					'type' => $group['type'],
				];
			}
		}

		return $result;
	}

	private function getConnectionTitle(int $connectionId): string
	{
		if ($connectionId <= 0)
		{
			return '';
		}

		$source = ExternalSourceTable::getList([
			'select' => ['TITLE'],
			'filter' => ['=ID' => $connectionId],
			'limit' => 1,
		])->fetch();

		return (string)($source['TITLE'] ?? '');
	}

	private function fillHelpdeskCode(): void
	{
		$datasetType = $this->arParams['sourceId'];
		if ($datasetType === ExternalSource\Type::Csv->value)
		{
			$this->arResult['helpdeskCode'] = 23378680;
		}
		elseif ($datasetType === ExternalSource\Type::Source1C->value)
		{
			$this->arResult['helpdeskCode'] = 23508958;
		}
	}

	private function fillSystemDataset(): void
	{
		$tableName = (string)($this->arParams['connection']['tableName'] ?? '');
		$provider = new SystemDatasetProvider();
		$tableData = $tableName !== '' ? $provider->getByName($tableName) : null;

		if ($tableData === null || !$this->canViewTable($tableName))
		{
			$this->arResult['ERROR_MESSAGES'][] = Loc::getMessage('BICONNECTOR_DATASET_NOT_FOUND_MSGVER_1');

			return;
		}

		$fullDescription = '';
		if (!is_array($tableData['TABLE_DESCRIPTION_FULL'] ?? null))
		{
			$fullDescription = (string)($tableData['TABLE_DESCRIPTION_FULL'] ?? '');
		}

		$shortDescription = '';
		if (!is_array($tableData['TABLE_DESCRIPTION'] ?? null))
		{
			$shortDescription = (string)($tableData['TABLE_DESCRIPTION'] ?? '');
		}
		$description = $fullDescription !== '' ? $fullDescription : $shortDescription;

		$name = (string)$tableData['NAME'];

		// A human name exists only when the short description differs from the technical name.
		// Without one the name field stays empty, while the slider title falls back to the code.
		$verboseName = '';
		$displayName = $name;
		if ($shortDescription !== '' && $shortDescription !== $name)
		{
			$verboseName = $shortDescription;
			$displayName = $shortDescription;
		}

		unset($this->arResult['initialData']['config']['connectionProperties']);

		$externalDatasets = $this->loadSupersetDatasetsByName($tableName);
		$datasetProperties = [
			'id' => 0,
			'name' => $tableData['NAME'],
			'verboseName' => $verboseName,
			'displayName' => $displayName,
			'description' => $description,
			'isSystem' => true,
			'externalDatasets' => $externalDatasets,
		];
		if ($this->getTableAccessChecker()->isRestricted())
		{
			$datasetProperties['tableGroups'] = $this->getVisibleTableGroups($name);
		}

		$createPhysicalUrl = $this->getSystemDatasetCreateUrl($tableName, false, $externalDatasets);
		if ($createPhysicalUrl !== null)
		{
			$datasetProperties['createPhysicalDatasetUrl'] = $createPhysicalUrl;
		}

		$createVirtualUrl = $this->getSystemDatasetCreateUrl($tableName, true, $externalDatasets);
		if ($createVirtualUrl !== null)
		{
			$datasetProperties['createVirtualDatasetUrl'] = $createVirtualUrl;
		}

		$this->arResult['initialData']['config'] = [
			...$this->arResult['initialData']['config'],
			'datasetProperties' => $datasetProperties,
			'fieldsSettings' => $this->mapSystemFields($tableData['FIELDS']),
		];

		$this->arResult['initialData']['previewData']['rows'] = $provider->getPreviewData(
			$tableName,
			self::SYSTEM_PREVIEW_ROWS,
		);
	}

	private function mapSystemFields(array $fields): array
	{
		$typeMap = [
			'STRING' => FieldType::String->value,
			'ARRAY_STRING' => FieldType::String->value,
			'INT' => FieldType::Int->value,
			'NUMBER' => FieldType::Int->value,
			'DOUBLE' => FieldType::Double->value,
			'DATE' => FieldType::Date->value,
			'YEAR_MONTH_DAY' => FieldType::Date->value,
			'DATETIME' => FieldType::DateTime->value,
			'YEAR_MONTH_DAY_SECOND' => FieldType::DateTime->value,
			'BOOLEAN' => FieldType::Int->value,
		];

		$result = [];
		foreach ($fields as $fieldData)
		{
			$result[] = [
				'name' => $fieldData['ID'] ?? '',
				'type' => $typeMap[$fieldData['TYPE'] ?? ''] ?? FieldType::String->value,
				'visible' => true,
				'originalName' => $fieldData['NAME'] ?? '',
				'externalCode' => $fieldData['NAME'] ?? '',
				'description' => $fieldData['DESCRIPTION'] ?? '',
			];
		}

		return $result;
	}

	private function loadSupersetDatasetsByName(string $tableName): array
	{
		if (!SupersetInitializer::isSupersetReady())
		{
			return [];
		}

		$response = IntegratorFactory::getInstance()->getDatasetListByTableName($tableName);
		if ($response->hasErrors())
		{
			return [];
		}

		return $response->getData();
	}

	private function getSystemDatasetCreateUrl(string $tableName, bool $isVirtual, array $externalDatasets): ?string
	{
		if ($tableName === '' || !SupersetInitializer::isSupersetReady())
		{
			return null;
		}

		if (!$isVirtual)
		{
			foreach ($externalDatasets as $externalDataset)
			{
				if (isset($externalDataset['is_virtual']) && $externalDataset['is_virtual'] === false)
				{
					return null;
				}
			}
		}

		$response = IntegratorFactory::getInstance()->getDatasetCreateUrl($tableName, $isVirtual);
		if ($response->hasErrors())
		{
			return null;
		}

		$responseData = $response->getData();

		return $responseData['url'] ? $this->getSupersetLoginUrl($responseData['url']) : null;
	}

	private function loadSupersetDatasets(ExternalSource\Internal\ExternalDataset $dataset): array
	{
		if (!SupersetInitializer::isSupersetReady())
		{
			return [];
		}

		$supersetIntegration = new SupersetIntegration();
		$datasetsResult = $supersetIntegration->loadSupersetDatasets($dataset);

		if ($datasetsResult->isSuccess())
		{
			return $datasetsResult->getData();
		}

		return [];
	}

	private function getPhysicalDatasetCreateUrl(ExternalSource\Internal\ExternalDataset $dataset, array $externalDatasets): ?string
	{
		if (empty($dataset->getName()) || !SupersetInitializer::isSupersetReady())
		{
			return null;
		}

		$hasPhysicalDataset = false;
		if (!empty($externalDatasets))
		{
			foreach ($externalDatasets as $externalDataset)
			{
				if (isset($externalDataset['is_virtual']) && $externalDataset['is_virtual'] === false)
				{
					$hasPhysicalDataset = true;
					break;
				}
			}
		}

		if ($hasPhysicalDataset)
		{
			return null;
		}

		$integrator = IntegratorFactory::getInstance();
		$response = $integrator->getDatasetCreateUrl($dataset->getName());
		if ($response->hasErrors())
		{
			return null;
		}
		$responseData = $response->getData();

		return $responseData['url'] ? $this->getSupersetLoginUrl($responseData['url']) : null;
	}

	private function getVirtualDatasetCreateUrl(ExternalSource\Internal\ExternalDataset $dataset): ?string
	{
		if (empty($dataset->getName()) || !SupersetInitializer::isSupersetReady())
		{
			return null;
		}

		$integrator = IntegratorFactory::getInstance();
		$response = $integrator->getDatasetCreateUrl($dataset->getName(), true);
		if ($response->hasErrors())
		{
			return null;
		}
		$responseData = $response->getData();

		return $responseData['url'] ? $this->getSupersetLoginUrl($responseData['url']) : null;
	}

	private function getSupersetLoginUrl(string $editUrl): string
	{
		$loginUrl = (new SupersetController(IntegratorFactory::getInstance()))->getLoginUrl();
		if ($loginUrl)
		{
			$url = new Uri($loginUrl);
			$url->addParams([
				'next' => $editUrl,
			]);

			return $url->getLocator();
		}

		return $editUrl;
	}

	private function loadDataset(): void
	{
		$datasetId = $this->arParams['datasetId'];
		$dataset = DatasetManager::getById($datasetId);
		if (!$dataset || !$this->canViewTable((string)$dataset->getName()))
		{
			$this->arResult['ERROR_MESSAGES'][] = Loc::getMessage('BICONNECTOR_DATASET_NOT_FOUND_MSGVER_1');

			return;
		}

		$datasetFields = DatasetManager::getDatasetFieldsById($datasetId);
		$datasetSettings = DatasetManager::getDatasetSettingsById($datasetId);

		$result = [
			'datasetProperties' => $this->internalizeDataset($dataset),
			'fieldsSettings' => $this->internalizeDatasetFields($datasetFields),
			'dataFormats' => $this->internalizeDatasetSettings($datasetSettings),
		];

		$canEdit = $this->getTableAccessChecker()->canDo(ActionDictionary::ACTION_BIC_TABLE_EDIT, $dataset->getName());
		$result['datasetProperties']['canEdit'] = $canEdit;
		$result['datasetProperties']['canExport'] = $this->getTableAccessChecker()->canDo(
			ActionDictionary::ACTION_BIC_TABLE_EXPORT,
			$dataset->getName(),
		);
		if ($this->getTableAccessChecker()->isRestricted())
		{
			$result['datasetProperties']['tableGroups'] = $this->getVisibleTableGroups($dataset->getName());
		}

		if ($dataset->getEnumType() !== ExternalSource\Type::Csv)
		{
			$source = $dataset->getSource();
			if ($source)
			{
				if (!$source->getActive())
				{
					$this->arResult['ERROR_MESSAGES'][] = Loc::getMessage('BICONNECTOR_CONNECTION_NOT_ACTIVE');
					$this->arResult['ERROR_DESCRIPTIONS'][] = Loc::getMessage('BICONNECTOR_CONNECTION_NOT_ACTIVE_DESC_MSGVER_1');

					return;
				}

				if ($source->getType() === \Bitrix\BIConnector\ExternalSource\Type::Rest->value)
				{
					$isSupportMapping = ExternalSourceRestTable::getList([
						'select' => ['CONNECTOR.SUPPORT_MAPPING'],
						'filter' => ['SOURCE_ID' => $source->getId()],
						'limit' => 1,
					])
						->fetchObject()
						?->getConnector()
						?->getSupportMapping()
					;
				}

				$connectionProperties = [
					'connectionId' => $source->getId(),
					'connectionType' => $source->getType(),
					'connectionName' => $source->getTitle(),
					'tableName' => $dataset->getExternalName(),
					'connectionIsSupportMapping' => $isSupportMapping ?? false,
				];

				if ($canEdit)
				{
					$connectionProperties = [
						...$connectionProperties,
						...$this->getDatasetCreateUrls($dataset, $result['datasetProperties']['externalDatasets'] ?? []),
					];
				}

				$result['connectionProperties'] = $connectionProperties;
			}
		}

		if ($dataset->getEnumType() === ExternalSource\Type::Csv)
		{
			$result['fileProperties'] = $this->internalizeFileProperties($dataset);

			if ($canEdit)
			{
				$result['datasetProperties'] = [
					...$result['datasetProperties'],
					...$this->getDatasetCreateUrls($dataset, $result['datasetProperties']['externalDatasets'] ?? []),
				];
			}
		}

		$this->arResult['initialData']['config'] = [
			...$this->arResult['initialData']['config'],
			...$result,
		];

		if ($dataset->getEnumType() === ExternalSource\Type::Csv)
		{
			$this->arResult['initialData']['previewData']['rows'] = $this->loadPreviewData($dataset);
		}
	}

	/**
	 * Creating a dataset in Superset belongs to editing the table, so without the right no link is offered.
	 *
	 * @return array{createPhysicalDatasetUrl?: string, createVirtualDatasetUrl?: string}
	 */
	private function getDatasetCreateUrls(ExternalSource\Internal\ExternalDataset $dataset, array $externalDatasets): array
	{
		$urls = [];

		$createPhysicalUrl = $this->getPhysicalDatasetCreateUrl($dataset, $externalDatasets);
		if ($createPhysicalUrl !== null)
		{
			$urls['createPhysicalDatasetUrl'] = $createPhysicalUrl;
		}

		$createVirtualUrl = $this->getVirtualDatasetCreateUrl($dataset);
		if ($createVirtualUrl !== null)
		{
			$urls['createVirtualDatasetUrl'] = $createVirtualUrl;
		}

		return $urls;
	}

	private function internalizeDataset(ExternalSource\Internal\ExternalDataset $dataset): array
	{
		return [
			'id' => $dataset->getId(),
			'name' => $dataset->getName() ?? '',
			'verboseName' => $dataset->getVerboseName() ?? '',
			'description' => $dataset->getDescription() ?? '',
			'externalCode' => $dataset->getExternalCode() ?? '',
			'externalName' => $dataset->getExternalName() ?? '',
			'externalDatasets' => $this->loadSupersetDatasets($dataset),
		];
	}

	private function internalizeDatasetFields(ExternalSource\Internal\ExternalDatasetFieldCollection $datasetFields): array
	{
		$result = [];

		foreach ($datasetFields as $field)
		{
			$result[] = [
				'id' => $field->getId() ?? 0,
				'visible' => $field->getVisible() ?? true,
				'type' => $field->getType() ?? '',
				'name' => $field->getName() ?? '',
				'originalName' => $field->getExternalCode() ?? '',
				'externalCode' => $field->getExternalCode() ?? '',
				'description' => $field->getDescription() ?? '',
			];
		}

		return $result;
	}

	private function internalizeDatasetSettings(ExternalSource\Internal\ExternalDatasetFieldFormatCollection $datasetSettings): array
	{
		$result = [
			FieldType::Money->value => '',
			FieldType::Date->value => '',
			FieldType::DateTime->value => '',
			FieldType::Double->value => '',
			FieldType::Timezone->value => '',
		];

		foreach ($datasetSettings as $setting)
		{
			$format = $setting->getFormat();
			if (
				(
					$setting->getType() === FieldType::Date->value
					&& Const\Date::tryFrom($format) === null
				)
				||
				(
					$setting->getType() === FieldType::DateTime->value
					&& Const\DateTime::tryFrom($format) === null
					&& $format !== Const\DateTime::ISO_8601
				)
			)
			{
				// Custom formats are passed to the frontend in ISO 8601 notation, predefined ones as is.
				$format = Const\DateTimeFormatConverter::phpToIso8601($format);
			}

			$result[mb_strtolower($setting->getType())] = $format;
		}

		return $result;
	}

	private function loadPreviewData(ExternalSource\Internal\ExternalDataset $dataset): array
	{
		$type = $dataset->getEnumType();

		$dataSource = Source\Factory::getSource($type, $dataset->getSourceId() ?? 0, $dataset->getId());
		$data = $dataSource->getFirstNData($dataset->getName(), self::FIRST_N_ROW);

		$result = [];
		$fields = DatasetManager::getDatasetFieldsById($dataset->getId());
		$codeList = $fields->getNameList();

		foreach ($data as $row)
		{
			$resultRow = [];
			foreach ($codeList as $code)
			{
				if (array_key_exists($code, $row))
				{
					$resultRow[$code] = $row[$code];
				}
			}
			$result[] = $this->preparePreviewRow($resultRow);
		}

		return $result;
	}

	private function preparePreviewRow(array $row): array
	{
		$result = [];

		foreach (array_values($row) as $index => $value)
		{
			if ($value instanceof Type\DateTime)
			{
				$value->setTimeZone(DataTimezone::getConfigTimezone());
				$result[] = $value->format('Y-m-d H:i:s');
			}
			elseif ($value instanceof Type\Date)
			{
				$result[] = $value->format('Y-m-d');
			}
			elseif ($this->arResult['initialData']['config']['fieldsSettings'][$index]['type'] === FieldType::Money->value)
			{
				$result[] = number_format($value, 2, '.', '');
			}
			else
			{
				$result[] = $value;
			}
		}

		return $result;
	}

	private function fillAppParams(): void
	{
		$dateFormat[] = [
			'type' => 'custom',
			'value' => '',
		];
		foreach (array_column(Const\Date::cases(), 'value') as $date)
		{
			$dateFormat[] = [
				'title' => Const\DateTimeFormatConverter::phpToIso8601($date),
				'type' => 'value',
				'value' => $date,
			];
		}

		$dateTimeFormat[] = [
			'type' => 'custom',
			'value' => '',
		];
		foreach (array_column(Const\DateTime::cases(), 'value') as $dateTime)
		{
			$dateTimeFormat[] = [
				'title' => Const\DateTimeFormatConverter::phpToIso8601($dateTime),
				'type' => 'value',
				'value' => $dateTime,
			];
		}

		$dateTimeFormat[] = [
			'title' => 'YYYY-MM-DDThh:mm:ss (ISO 8601)',
			'type' => 'value',
			'value' => Const\DateTime::ISO_8601,
		];

		$this->arResult['appParams'] = [
			'dataFormatTemplates' => [
				FieldType::Date->value => $dateFormat,
				FieldType::DateTime->value => $dateTimeFormat,
				FieldType::Double->value => [
					[
						'title' => '1,23',
						'type' => 'value',
						'value' => Const\DoubleDelimiter::COMMA->value,
					],
					[
						'title' => '1.23',
						'type' => 'value',
						'value' => Const\DoubleDelimiter::DOT->value,
					],
				],
				FieldType::Money->value => [
					[
						'title' => '12345,67',
						'type' => 'value',
						'value' => Const\MoneyDelimiter::COMMA->value,
					],
					[
						'title' => '12345.67',
						'type' => 'value',
						'value' => Const\MoneyDelimiter::DOT->value,
					],
				],
				FieldType::Timezone->value => $this->getTimezones(),
			],
			'encodings' => [
				[
					'value' => Const\Encoding::UTF8->value,
					'title' => Loc::getMessage('BICONNECTOR_CSV_IMPORT_UTF8'),
				],
				[
					'value' => Const\Encoding::WINDOWS_1251->value,
					'title' => Loc::getMessage('BICONNECTOR_CSV_IMPORT_WIN1251'),
				],
			],
			'separators' => [
				[
					'value' => Const\Delimiter::SEMICOLON->value,
					'title' => Loc::getMessage('BICONNECTOR_CSV_IMPORT_SEPARATOR_SEMICOLON'),
				],
				[
					'value' => Const\Delimiter::COLON->value,
					'title' => Loc::getMessage('BICONNECTOR_CSV_IMPORT_SEPARATOR_COLON'),
				],
				[
					'value' => Const\Delimiter::COMMA->value,
					'title' => Loc::getMessage('BICONNECTOR_CSV_IMPORT_SEPARATOR_COMMA'),
				],
			],
			'reservedNames' => ExternalSource\SupersetServiceIntegration::getTableList(),
			'connections' => $this->getExternalConnections(),
			'isSupersetReady' => SupersetInitializer::isSupersetReady(),
		];

		if ($this->isNewDatasetMode())
		{
			$isTablePermissionsEnabled = $this->getTableAccessChecker()->isRestricted();
			$this->arResult['appParams']['isTablePermissionsEnabled'] = $isTablePermissionsEnabled;
			$this->arResult['appParams']['tableGroups'] = $isTablePermissionsEnabled ? $this->getEditableTableGroups() : [];
		}
	}

	private function getTimezones(): array
	{
		$timezones = [];
		foreach (\CTimeZone::GetZones() as $code => $name)
		{
			$timezones[] = [
				'value' => $code,
				'title' => $name,
				'type' => 'value',
			];
		}

		return $timezones;
	}

	private function getExternalConnections(): array
	{
		$preparedSources = [];

		$restIcons = $this->loadRestAvatars();

		$externalSources = ExternalSourceTable::getList([
			'select' => ['ID', 'TYPE', 'TITLE'],
			'filter' => ['ACTIVE' => 'Y'],
		]);

		while ($source = $externalSources->fetch())
		{
			$source['AVATAR'] = '';
			if ($source['TYPE'] === ExternalSource\Type::Rest->value)
			{
				if (!empty($restIcons[$source['ID']]))
				{
					$source['AVATAR'] = $restIcons[$source['ID']];
				}
				$connector = ExternalSourceRestTable::getList([
					'select' => ['CONNECTOR.SUPPORT_MAPPING'],
					'filter' => [
						'SOURCE_ID' => $source['ID'],
					],
					'limit' => 1,
				])
					->fetchObject()
				;

				$source['IS_SUPPORT_MAPPING'] = $connector?->getConnector()?->getSupportMapping() ?? false;
			}
			else
			{
				$source['AVATAR'] = "/bitrix/images/biconnector/database-connections/{$source['TYPE']}.svg";
			}

			$preparedSources[] = $source;
		}

		return $preparedSources;
	}

	private function loadRestAvatars(): array
	{
		$icons = [];
		$sources = ExternalSourceRestTable::getList([
			'select' => [
				'SOURCE_ID',
				'AVATAR' => 'CONNECTOR.LOGO',
			],
		]);

		while ($source = $sources->fetch())
		{
			$icons[$source['SOURCE_ID']] = $source['AVATAR'];
		}

		return $icons;
	}
}
