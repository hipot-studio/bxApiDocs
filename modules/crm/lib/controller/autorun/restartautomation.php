<?php

namespace Bitrix\Crm\Controller\Autorun;

use Bitrix\Crm\Controller\Autorun\Dto\PreparedData;
use Bitrix\Crm\Controller\ErrorCode;
use Bitrix\Crm\Integration\BizProc\Starter\CrmStarter;
use Bitrix\Crm\Integration\BizProc\Starter\Dto\DocumentDto;
use Bitrix\Crm\Integration\BizProc\Starter\Dto\RunDataDto;
use Bitrix\Crm\Item;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Factory;
use Bitrix\Crm\Timeline\LogMessageController;
use Bitrix\Crm\Timeline\LogMessageType;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Result;
use CCrmOwnerType;

final class RestartAutomation extends Base
{
	protected function isEntityTypeSupported(Factory $factory): bool
	{
		return $factory->isStagesEnabled();
	}

	protected function getSelect(): array
	{
		return [
			Item::FIELD_NAME_ID,
			Item::FIELD_NAME_STAGE_ID,
		];
	}

	protected function processItem(Factory $factory, Item $item, PreparedData $data): Result
	{
		$result = new Result();

		$userPermission = Container::getInstance()->getUserPermissions();
		if (!$userPermission->item()->canUpdate($item->getEntityTypeId(), $item->getId()))
		{
			return $result->addError(ErrorCode::getAccessDeniedError());
		}

		$starter = new CrmStarter(new DocumentDto($item->getEntityTypeId(), $item->getId()));
		$stageColumnName = $factory->getEntityFieldNameByMap(Item::FIELD_NAME_STAGE_ID);
		$result = $starter->runAutomation(
			new RunDataDto(
				actualFields: [$stageColumnName => $item->getId()],
				previousFields: [],
				userId: Container::getInstance()->getContext()->getUserId(),
			),
			\CCrmBizProcEventType::Edit
		);

		if (!$result->isSuccess())
		{
			return $result;
		}

		$stageId = $item->getStageId();
		$stageName = $factory->getStage($stageId)->getName();
		LogMessageController::getInstance()->onCreate(
			[
				'ENTITY_TYPE_ID' => $item->getEntityTypeId(),
				'ENTITY_ID' => $item->getId(),
				'ASSOCIATED_ENTITY_TYPE_ID' => CCrmOwnerType::Activity,
				'SETTINGS' => [
					'STAGE_ID' => $stageId,
					'STAGE_NAME' => $stageName,
				],
			],
			LogMessageType::RESTART_AUTOMATION,
			$userPermission->getUserId(),
		);

		return $result;
	}
}
