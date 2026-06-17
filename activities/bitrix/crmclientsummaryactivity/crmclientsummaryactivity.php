<?php

declare(strict_types=1);

if (!defined('B_PROLOG_INCLUDED') || B_PROLOG_INCLUDED !== true)
{
	die();
}

use Bitrix\Bizproc\Activity\BaseActivity;
use Bitrix\Bizproc\Activity\PropertiesDialog;
use Bitrix\Bizproc\FieldType;
use Bitrix\Crm\RepeatSale\DataCollector\Activity\ActivityType;
use Bitrix\Crm\RepeatSale\DataCollector\ClientDataCollector;
use Bitrix\Crm\RepeatSale\DataCollector\EntityDataCollector;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Web\Json;

final class CBPCrmClientSummaryActivity extends BaseActivity implements IBPConfigurableActivity
{
	private const PARAM_ENTITY_TYPE_ID = 'entityTypeId';
	private const PARAM_ENTITY_ID = 'entityId';

	private const RETURN_PARAM_SUMMARY_JSON = 'summaryJson';

	public function __construct(string $name)
	{
		parent::__construct($name);

		$this->arProperties = [
			'Title' => '',

			self::PARAM_ENTITY_TYPE_ID => null,
			self::PARAM_ENTITY_ID => null,

			self::RETURN_PARAM_SUMMARY_JSON => null,
		];

		$this->setPropertiesTypes([
			self::RETURN_PARAM_SUMMARY_JSON => [
				'Type' => FieldType::JSON,
			],
		]);
	}

	public static function getPropertiesDialogMap(?PropertiesDialog $dialog = null): array
	{
		return self::getPropertiesMap([]);
	}

	protected static function getPropertiesMap(array $documentType, array $context = []): array
	{
		return [
			self::PARAM_ENTITY_TYPE_ID => [
				'Name' => Loc::getMessage('CRM_CLIENT_SUMMARY_ACTIVITY_PARAM_ENTITY_TYPE_ID_NAME'),
				'FieldName' => self::PARAM_ENTITY_TYPE_ID,
				'Type' => FieldType::SELECT,
				'Options' => self::getEntityTypeIdOptions(),
				'AllowSelection' => true,
				'Required' => true,
			],
			self::PARAM_ENTITY_ID => [
				'Name' => Loc::getMessage('CRM_CLIENT_SUMMARY_ACTIVITY_PARAM_ENTITY_ID_NAME'),
				'FieldName' => self::PARAM_ENTITY_ID,
				'Type' => FieldType::INT,
				'AllowSelection' => true,
				'Required' => true,
			],
		];
	}

	public function execute(): int
	{
		$entityTypeId = $this->getEntityTypeId();
		if ($entityTypeId === null)
		{
			$this->trackError(Loc::getMessage('CRM_CLIENT_SUMMARY_ACTIVITY_ERROR_ENTITY_TYPE_ID_NOT_CORRECT'));

			return CBPActivityExecutionStatus::Closed;
		}

		$entityId = $this->getEntityId();
		if ($entityId === null)
		{
			$this->trackError(Loc::getMessage('CRM_CLIENT_SUMMARY_ACTIVITY_ERROR_ENTITY_ID_NOT_CORRECT'));

			return CBPActivityExecutionStatus::Closed;
		}

		$clientSummary = $this->collectClientSummary($entityTypeId, $entityId);
		if ($clientSummary === null)
		{
			return CBPActivityExecutionStatus::Closed;
		}

		$this->{self::RETURN_PARAM_SUMMARY_JSON} = Json::encode($clientSummary);

		return CBPActivityExecutionStatus::Closed;
	}

	private function collectClientSummary(int $entityTypeId, int $entityId): ?array
	{
		$clientInfo = (new ClientDataCollector($entityTypeId))
			->getMarkers([
				'entityId' => $entityId,
			]);

		if (empty($clientInfo))
		{
			return null;
		}

		[$dealList, $ordersSummary] = (new EntityDataCollector(CCrmOwnerType::Deal))
			->getMarkers([
				'entityId' => 0,
				'clientIdentifiers' => [
					[
						'entityTypeId' => $entityTypeId,
						'entityId' => $entityId,
					],
				],
			]);

		return [
			'client_info' => $clientInfo,
			'deals_list' => $dealList ?? [],
			'orders_summary' => $ordersSummary ?? [],
			'preferred_communication_channel' => $this->getPreferredCommunicationChannel($dealList ?? []),
		];
	}

	private function getPreferredCommunicationChannel(array $dealsList): string
	{
		$counts = array_reduce(
			array_filter(array_column($dealsList, 'communication_data')),
			static function($counts, $arr) {
				foreach ($arr as $type => $items)
				{
					if (ActivityType::isCommunicationChannel($type))
					{
						$counts[$type] = ($counts[$type] ?? 0) + count($items);
					}
				}

				return $counts;
			},
			[],
		);

		$channel = empty($counts)
			? ''
			: array_keys($counts, max($counts))[0] ?? '';

		return ActivityType::mapCommunicationChannel($channel);
	}

	private function getEntityTypeId(): ?int
	{
		$entityTypeId = $this->{self::PARAM_ENTITY_TYPE_ID};
		if (
			!is_numeric($entityTypeId)
			|| !array_key_exists((int)$entityTypeId, self::getEntityTypeIdOptions())
		)
		{
			return null;
		}

		return (int)$entityTypeId;
	}

	private function getEntityId(): ?int
	{
		$entityId = $this->{self::PARAM_ENTITY_ID};
		if (!is_numeric($entityId) || (int)$entityId <= 0)
		{
			return null;
		}

		return (int)$entityId;
	}

	private static function getEntityTypeIdOptions(): array
	{
		if (!Loader::includeModule('crm'))
		{
			return [];
		}

		return [
			CCrmOwnerType::Contact => CCrmOwnerType::GetDescription(CCrmOwnerType::Contact),
			CCrmOwnerType::Company => CCrmOwnerType::GetDescription(CCrmOwnerType::Company),
		];
	}

	protected static function getFileName(): string
	{
		return __FILE__;
	}
}
