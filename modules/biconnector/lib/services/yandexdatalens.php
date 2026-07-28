<?php
namespace Bitrix\BIConnector\Services;

use Bitrix\BIConnector\DataSourceConnector\YandexDataLensFieldDto;
use Bitrix\BIConnector\DataSourceConnector\FieldDto;
use Bitrix\BIConnector\Service;

class YandexDataLens extends MicrosoftPowerBI
{
	protected static $serviceId = 'datalens';

	/**
	 * @inheritDoc
	 */
	public static function validateDashboardUrl(\Bitrix\Main\Event $event)
	{
		$url = $event->getParameters()[0];
		$uri = new \Bitrix\Main\Web\Uri($url);
		$isUrlOk =
			($uri->getScheme() === 'https')
			&& ($uri->getHost() === 'datalens.yandex.ru')
		;
		$result = new \Bitrix\Main\EventResult(\Bitrix\Main\EventResult::SUCCESS, ($isUrlOk ? 3 : 0));
		return $result;
	}

	/**
	 * @param string $fieldName
	 * @param array $fieldInfo
	 *
	 * @return FieldDto
	 */
	protected function prepareFieldDto(string $fieldName, array $fieldInfo): FieldDto
	{
		$type = $fieldInfo['FIELD_TYPE_EX'] ?? $fieldInfo['FIELD_TYPE'];

		$parentDto = parent::prepareFieldDto($fieldName, $fieldInfo);

		return new YandexDataLensFieldDto(
			$parentDto->id,
			$parentDto->name,
			$parentDto->description,
			$type ?? 'string',
			$parentDto->isMetric,
			$parentDto->isPrimary,
			$parentDto->isSystem,
			$parentDto->aggregationType,
			$parentDto->groupKey,
			$parentDto->groupConcat,
			$parentDto->groupCount,
			$parentDto->isValueSplitable
		);
	}
}
