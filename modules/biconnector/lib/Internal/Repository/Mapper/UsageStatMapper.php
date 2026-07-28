<?php

namespace Bitrix\BIConnector\Internal\Repository\Mapper;

use Bitrix\BIConnector\Internal\Entity\UsageStatEntry;
use Bitrix\BIConnector\Internal\Model\EO_UsageStat;
use Bitrix\BIConnector\Internal\Model\UsageStatTable;

class UsageStatMapper
{
	public function convertFromOrm(EO_UsageStat $ormModel): UsageStatEntry
	{
		$entry = new UsageStatEntry();

		$entry
			->setId($ormModel->getId())
			->setTimestamp($ormModel->getTimestampX())
			->setKeyId($ormModel->getKeyId())
			->setServiceId($ormModel->getServiceId())
			->setSourceId($ormModel->getSourceId())
			->setFields($ormModel->getFields())
			->setFilters($ormModel->getFilters())
			->setInput($ormModel->getInput())
			->setRequestMethod($ormModel->getRequestMethod())
			->setRequestUri($ormModel->getRequestUri())
			->setRowNum($ormModel->getRowNum())
			->setDataSize($ormModel->getDataSize())
			->setRealTime($ormModel->getRealTime())
			->setIsOverLimit((bool)$ormModel->getIsOverLimit())
			->setSource($ormModel->getSource())
			->setExternalDashboardId($ormModel->getExternalDashboardId())
			->setExternalDashboardName($ormModel->getExternalDashboardName())
			->setExternalChartId($ormModel->getExternalChartId())
			->setExternalChartName($ormModel->getExternalChartName())
			->setExternalDatasetId($ormModel->getExternalDatasetId())
			->setExternalDatasetName($ormModel->getExternalDatasetName())
		;

		return $entry;
	}

	public function convertToOrm(UsageStatEntry $entity): EO_UsageStat
	{
		$ormModel = $entity->getId() !== null
			? EO_UsageStat::wakeUp($entity->getId())
			: UsageStatTable::createObject()
		;

		$ormModel
			->setTimestampX($entity->getTimestamp())
			->setKeyId($entity->getKeyId())
			->setServiceId($entity->getServiceId())
			->setSourceId($entity->getSourceId())
			->setFields($entity->getFields())
			->setFilters($entity->getFilters())
			->setInput($entity->getInput())
			->setRequestMethod($entity->getRequestMethod())
			->setRequestUri($entity->getRequestUri())
			->setRowNum($entity->getRowNum())
			->setDataSize($entity->getDataSize())
			->setRealTime($entity->getRealTime())
			->setIsOverLimit($entity->isOverLimit())
			->setSource($entity->getSource())
			->setExternalDashboardId($entity->getExternalDashboardId())
			->setExternalDashboardName($entity->getExternalDashboardName())
			->setExternalChartId($entity->getExternalChartId())
			->setExternalChartName($entity->getExternalChartName())
			->setExternalDatasetId($entity->getExternalDatasetId())
			->setExternalDatasetName($entity->getExternalDatasetName())
		;

		return $ormModel;
	}
}
