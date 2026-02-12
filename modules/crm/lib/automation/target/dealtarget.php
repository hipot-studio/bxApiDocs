<?php
namespace Bitrix\Crm\Automation\Target;

use Bitrix\Crm\Automation\Factory;
use Bitrix\Crm\Category\DealCategory;
use Bitrix\Crm\DealTable;
use Bitrix\Crm\PhaseSemantics;

class DealTarget extends BaseTarget
{
	protected $entityStages;
	protected ?int $categoryId = null;

	public function isAvailable()
	{
		return Factory::isAutomationAvailable(\CCrmOwnerType::Deal);
	}

	public function canTriggerSetExecuteBy(): bool
	{
		return true;
	}

	public function getEntityTypeId()
	{
		return \CCrmOwnerType::Deal;
	}

	public function getEntityIdByDocumentId(string $documentId): int
	{
		return (int)str_replace('DEAL_', '', $documentId);
	}

	protected function getEntityFields(array $select): array
	{
		$id = $this->getEntityId();
		if (empty($id))
		{
			return [];
		}

		return DealTable::query()
			->setSelect($select)
			->where('ID', $id)
			->fetch() ?: [];
	}

	public function getResponsibleId()
	{
		$entity = $this->getEntityFields(['ASSIGNED_BY_ID']);

		return (int)$entity['ASSIGNED_BY_ID'];
	}

	public function getEntityStatus()
	{
		$entity = $this->getEntityFields(['STAGE_ID', 'CATEGORY_ID']);
		$this->categoryId = $entity['CATEGORY_ID'] ?? null;

		return $entity['STAGE_ID'] ?? '';
	}

	public function getDocumentCategory(): int
	{
		if ($this->categoryId === null)
		{
			$entity = $this->getEntityFields(['CATEGORY_ID']);
			$this->categoryId = (int)$entity['CATEGORY_ID'];
		}

		return $this->categoryId;
	}

	public function setEntityStatus($statusId, $executeBy = null)
	{
		$id = $this->getEntityId();
		if (empty($id))
		{
			return false;
		}

		$fields = ['STAGE_ID' => $statusId];
		if ($executeBy)
		{
			$fields['MODIFY_BY_ID'] = $executeBy;
		}

		$CCrmDeal = new \CCrmDeal(false);
		$updateResult = $CCrmDeal->Update($id, $fields, true, true, [
			'DISABLE_USER_FIELD_CHECK' => true,
			'REGISTER_SONET_EVENT' => true,
			'CURRENT_USER' => $executeBy ?? 0, //System user
		]);

		return $updateResult;
	}

	public function getEntityStatuses()
	{
		if ($this->entityStages === null)
		{
			$categoryId = $this->getDocumentCategory();
			$this->entityStages = array_keys(DealCategory::getStageList($categoryId));
		}

		return $this->entityStages;
	}

	public function getStatusInfos($categoryId = 0)
	{
		if ($this->getEntityId() > 0)
		{
			$categoryId = $this->getDocumentCategory();
		}

		$processColor = \CCrmViewHelper::PROCESS_COLOR;
		$successColor = \CCrmViewHelper::SUCCESS_COLOR;
		$failureColor = \CCrmViewHelper::FAILURE_COLOR;

		$statuses = DealCategory::getStageInfos($categoryId);

		foreach ($statuses as $id => $stageInfo)
		{
			if (!empty($stageInfo['COLOR']))
				continue;

			$stageSemanticID = \CCrmDeal::GetSemanticID($stageInfo['STATUS_ID'], $categoryId);
			$isSuccess = $stageSemanticID === PhaseSemantics::SUCCESS;
			$isFailure = $stageSemanticID === PhaseSemantics::FAILURE;

			$statuses[$id]['COLOR'] = ($isSuccess ? $successColor : ($isFailure ? $failureColor : $processColor));
		}
		return $statuses;
	}

	public function getDocumentCategoryCode(): string
	{
		return 'CATEGORY_ID';
	}
}
