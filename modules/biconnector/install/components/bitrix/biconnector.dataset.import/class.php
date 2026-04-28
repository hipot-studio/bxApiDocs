<?php

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

\Bitrix\Main\Loader::includeModule('biconnector');

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\ExternalSource\Internal\ExternalSourceRestTable;
use Bitrix\BIConnector\ExternalSource\Internal\ExternalSourceTable;
use Bitrix\BIConnector\ExternalSource\SupersetIntegration;
use Bitrix\BIConnector\Integration\Superset\Integrator\Integrator;
use Bitrix\BIConnector\Integration\Superset\SupersetController;
use Bitrix\BIConnector\Integration\Superset\SupersetInitializer;
use Bitrix\Main\Application;
use Bitrix\Main\Localization\Loc;
use Bitrix\BiConnector\Settings;
use Bitrix\BIConnector\ExternalSource;
use Bitrix\BIConnector\ExternalSource\Const;
use Bitrix\BIConnector\ExternalSource\FieldType;
use Bitrix\BIConnector\ExternalSource\DatasetManager;
use Bitrix\BIConnector\ExternalSource\Source;
use Bitrix\BIConnector\Configuration\DataTimezone;
use Bitrix\Main\Type;
use Bitrix\Main\Web\Uri;

class DatasetImportComponent extends CBitrixComponent
{
	private const FIRST_N_ROW = 20;

	public function onPrepareComponentParams($arParams)
	{
		if (!AccessController::getCurrent()->check(ActionDictionary::ACTION_BIC_EXTERNAL_DASHBOARD_CONFIG))
		{
			$this->arResult['ERROR_MESSAGES'][] = Loc::getMessage('BICONNECTOR_CSV_IMPORT_ACCESS_ERROR');
			$this->includeComponentTemplate();
			Application::getInstance()->terminate();
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
			$this->includeComponentTemplate();
			Application::getInstance()->terminate();
		}

		return parent::onPrepareComponentParams($arParams);
	}

	public function executeComponent()
	{
		$this->fillInitialData();
		$this->fillHelpdeskCode();
		if ($this->arParams['datasetId'] > 0)
		{
			$this->loadDataset();
		}

		$this->fillAppParams();

		$this->includeComponentTemplate();
	}

	private function fillInitialData(): void
	{
		$this->arResult['initialData'] = [
			'config' => [
				'fileProperties' => [
					'encoding' => Const\Encoding::UTF8->value,
					'separator' => Const\Delimiter::COMMA->value,
					'firstLineHeader' => true,
				],
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
			$this->arResult['initialData']['config']['connectionProperties'] = $this->arParams['connection'];
		}
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
		if(empty($dataset->getName()) || !SupersetInitializer::isSupersetReady())
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

		$integrator = Integrator::getInstance();
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

		$integrator = Integrator::getInstance();
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
		$loginUrl = (new SupersetController(Integrator::getInstance()))->getLoginUrl();
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
		if (!$dataset)
		{
			$this->arResult['ERROR_MESSAGES'][] = Loc::getMessage('BICONNECTOR_DATASET_NOT_FOUND_MSGVER_1');
			$this->includeComponentTemplate();
			Application::getInstance()->terminate();
		}

		$datasetFields = DatasetManager::getDatasetFieldsById($datasetId);
		$datasetSettings = DatasetManager::getDatasetSettingsById($datasetId);

		$result = [
			'datasetProperties' => $this->internalizeDataset($dataset),
			'fieldsSettings' => $this->internalizeDatasetFields($datasetFields),
			'dataFormats' => $this->internalizeDatasetSettings($datasetSettings),
		];

		if ($dataset->getEnumType() !== ExternalSource\Type::Csv)
		{
			$source = $dataset->getSource();
			if ($source)
			{
				if (!$source->getActive())
				{
					$this->arResult['ERROR_MESSAGES'][] = Loc::getMessage('BICONNECTOR_CONNECTION_NOT_ACTIVE');
					$this->arResult['ERROR_DESCRIPTIONS'][] = Loc::getMessage('BICONNECTOR_CONNECTION_NOT_ACTIVE_DESC_MSGVER_1');
					$this->includeComponentTemplate();
					Application::getInstance()->terminate();
				}

				if ($source->getType() === \Bitrix\BIConnector\ExternalSource\Type::Rest->value)
				{
					$isSupportMapping = ExternalSourceRestTable::getList([
						'select' => [ 'CONNECTOR.SUPPORT_MAPPING'],
						'filter' => ['SOURCE_ID' => $source->getId()],
						'limit' => 1,
					])
						->fetchObject()
						?->getConnector()
						->getSupportMapping()
					;
				}

				$connectionProperties = [
					'connectionId' => $source->getId(),
					'connectionType' => $source->getType(),
					'connectionName' => $source->getTitle(),
					'tableName' => $dataset->getExternalName(),
					'connectionIsSupportMapping' => $isSupportMapping ?? false,
				];

				$externalDatasets = $result['datasetProperties']['externalDatasets'] ?? [];
				$createPhysicalUrl = $this->getPhysicalDatasetCreateUrl($dataset, $externalDatasets);
				if ($createPhysicalUrl !== null)
				{
					$connectionProperties['createPhysicalDatasetUrl'] = $createPhysicalUrl;
				}

				$createVirtualUrl = $this->getVirtualDatasetCreateUrl($dataset);
				if ($createVirtualUrl !== null)
				{
					$connectionProperties['createVirtualDatasetUrl'] = $createVirtualUrl;
				}

				$result['connectionProperties'] = $connectionProperties;
			}
		}

		if ($dataset->getEnumType() === ExternalSource\Type::Csv)
		{
			$externalDatasets = $result['datasetProperties']['externalDatasets'] ?? [];
			$createPhysicalUrl = $this->getPhysicalDatasetCreateUrl($dataset, $externalDatasets);
			if ($createPhysicalUrl !== null)
			{
				$result['datasetProperties']['createPhysicalDatasetUrl'] = $createPhysicalUrl;
			}

			$createVirtualUrl = $this->getVirtualDatasetCreateUrl($dataset);
			if ($createVirtualUrl !== null)
			{
				$result['datasetProperties']['createVirtualDatasetUrl'] = $createVirtualUrl;
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

	private function internalizeDataset(ExternalSource\Internal\ExternalDataset $dataset): array
	{
		return [
			'id' => $dataset->getId(),
			'name' => $dataset->getName() ?? '',
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
				)
			)
			{
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
			'value' => 'Y-m-d\TH:i:s',
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
		$preparedSources= [];

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
				$isSupportMapping = $connector->getConnector()->getSupportMapping();

				$source['IS_SUPPORT_MAPPING'] = $isSupportMapping;
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
				'AVATAR' => 'CONNECTOR.LOGO'
			],
		]);

		while ($source = $sources->fetch())
		{
			$icons[$source['SOURCE_ID']] = $source['AVATAR'];
		}

		return $icons;
	}
}
