<?php

namespace Bitrix\CrmMobile\Controller;

use Bitrix\Crm\Controller\ErrorCode;
use Bitrix\Crm\EO_Status;
use Bitrix\Crm\Service\Factory;
use Bitrix\CrmMobile\Dto;
use Bitrix\CrmMobile\Controller\Base;
use Bitrix\CrmMobile\Service\ConfigurationPermissions;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;

Loader::requireModule('crm');
Loader::requireModule('bizproc');

class Stage extends Base
{
	public function createAction(Factory $factory, int $categoryId, array $fields): ?Dto\Stage
	{
		// the funnel the stage is created in is named by the request, so the capabilities of the created
		// stage are known here and stated with it ({@see Dto\Stage}). Managing stages is decided in the
		// contour of the entity type as a whole, so carrying the funnel does not change this gate.
		$capabilities = ConfigurationPermissions::createForEntityType($factory->getEntityTypeId(), $categoryId);

		if (!$capabilities->canManageStages())
		{
			$this->addError(ErrorCode::getAccessDeniedError());
			return null;
		}

		// asked after the right, because a smart process asked for the stage dictionary of a funnel it
		// does not have creates that funnel ({@see Factory\Dynamic::getStagesEntityId()})
		$entityId = $factory->getStagesEntityId($categoryId);
		$status = new \CCrmStatus($entityId);

		$id = $status->Add([
			'NAME' => $fields['name'],
			'SORT' => $fields['sort'],
			'SEMANTICS' => $fields['semantics'],
			'COLOR' => $fields['color'],
		]);

		if (!$id)
		{
			$this->addError(new Error($status->GetLastError()));
			return null;
		}

		$this->resortStagesAndSave($factory, $categoryId);

		$stage = $status->GetStatusById($id);

		return Dto\Stage::make([
			'id' => $stage['ID'],
			'name' => $stage['NAME'],
			'sort' => $stage['SORT'],
			'color' => $stage['COLOR'],
			'semantics' => $stage['SEMANTICS'],
			'statusId' => $stage['STATUS_ID'],
			'canManageStages' => $capabilities->canManageStages(),
			'canManageTunnels' => $capabilities->canManageTunnels(),
		]);
	}

	private function resortStagesAndSave(Factory $factory, int $categoryId): void
	{
		$sort = 10;
		$stageObjects = $factory->getStages($categoryId);

		foreach ($stageObjects as $stageObject)
		{
			$stageObject->setSort($sort);
			$sort += 10;
		}

		$stageObjects->save();
	}

	/**
	 * $categoryId names the funnel the stage is expected to stand in. A request that does not name one
	 * takes the stage from every funnel of the entity type and does not question its membership.
	 */
	public function updateAction(Factory $factory, array $fields, ?int $categoryId = null): bool
	{
		$stage = $this->resolveStage($factory, $fields['statusId'] ?? null, $categoryId);
		if (!$stage)
		{
			$this->addError(new Error(Loc::getMessage('CRM_STAGE_STAGE_NOT_FOUND')));
			return false;
		}

		if (
			array_key_exists('tunnelsContractVersion', $fields)
			&& !in_array($fields['tunnelsContractVersion'], [2, '2'], true)
		)
		{
			$this->addError(new Error('Unsupported tunnels contract version', ErrorCode::INVALID_ARG_VALUE));
			return false;
		}

		$stageFields = $this->requestedStageFields($fields);
		$tunnels = $this->requestedTunnels($factory, $fields);

		if (!$this->mayWriteEveryPart($factory, $stage, $stageFields !== null, $tunnels !== null))
		{
			$this->addError(ErrorCode::getAccessDeniedError());
			return false;
		}

		if ($stageFields !== null && !$this->writeStageFields($stage, $stageFields))
		{
			return false;
		}

		if ($tunnels !== null)
		{
			$tunnelManager = new \Bitrix\Crm\Automation\TunnelManager($factory->getEntityTypeId());
			$tunnelManagerResult = $tunnelManager->updateStageTunnels(
				$tunnels,
				$stage->getStatusId(),
				$this->getCurrentUser()->getId()
			);

			if (!$tunnelManagerResult->isSuccess())
			{
				$this->addErrors($tunnelManagerResult->getErrors());
				return false;
			}
		}

		return true;
	}

	/**
	 * The stage the request names, and null where it names none of this entity type or one standing in
	 * another funnel: the funnel of a stage is decided here and never taken from the request.
	 *
	 * $statusId is nullable only because an update request may leave the key out altogether.
	 */
	private function resolveStage(Factory $factory, ?string $statusId, ?int $categoryId): ?EO_Status
	{
		if ($statusId === null)
		{
			return null;
		}

		$stage = $factory->getStage($statusId);
		if (!$stage)
		{
			return null;
		}

		if ($categoryId !== null && (int)$stage->getCategoryId() !== $categoryId)
		{
			return null;
		}

		return $stage;
	}

	/**
	 * The fields of the stage itself the request asks to write, and null where it asks for none. Only a
	 * value the write below understands counts as a request; anything else is ignored rather than
	 * refused, as it was before these parts were told apart.
	 */
	private function requestedStageFields(array $fields): ?array
	{
		$requested = [];

		foreach (['name', 'color'] as $key)
		{
			if (isset($fields[$key]) && is_string($fields[$key]))
			{
				$requested[$key] = $fields[$key];
			}
		}

		return $requested === [] ? null : $requested;
	}

	/**
	 * @param array $stageFields {@see requestedStageFields()}
	 */
	private function writeStageFields(EO_Status $stage, array $stageFields): bool
	{
		if (isset($stageFields['name']))
		{
			$stage->setName($stageFields['name']);
		}

		if (isset($stageFields['color']))
		{
			$stage->setColor($stageFields['color']);
		}

		$result = $stage->save();
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());
			return false;
		}

		return true;
	}

	private function requestedTunnels(Factory $factory, array $fields): ?array
	{
		if (!$factory->isCategoriesEnabled() || !$factory->isStagesEnabled())
		{
			return null;
		}

		if (array_key_exists('tunnels', $fields) && is_array($fields['tunnels']))
		{
			return $fields['tunnels'];
		}

		if (!array_key_exists('tunnelsContractVersion', $fields))
		{
			return [];
		}

		return $this->clearsTunnels($fields) ? [] : null;
	}

	/**
	 * The empty set of tunnels - the one that takes every tunnel off the stage - has to ask for itself
	 * by a flag: the form encoding of the mobile transport drops an empty array along with its key
	 * ({@see BX.ajax.prepareData}). A boolean arrives as its own string over that encoding, hence the
	 * two forms; anything else, the string `'false'` included, asks for no tunnel write.
	 */
	private function clearsTunnels(array $fields): bool
	{
		$flag = $fields['clearTunnels'] ?? null;

		return $flag === true || $flag === 'true';
	}

	/**
	 * Every part the request carries is allowed to be written. A part it does not carry asks for no
	 * right of its own: the tunnels of a stage are edited by the tunnel right alone, and the fields of
	 * the stage by the stage right alone. A refused part turns the whole request down before the first
	 * write.
	 *
	 * The tunnels are asked in the contour of the funnel the stage stands in - the funnel the tunnel
	 * robot is written into.
	 */
	private function mayWriteEveryPart(
		Factory $factory,
		EO_Status $stage,
		bool $stageFieldsRequested,
		bool $tunnelsRequested
	): bool
	{
		if (!$stageFieldsRequested && !$tunnelsRequested)
		{
			return true;
		}

		$sourceCategoryId = $stage->getCategoryId();
		$capabilities = ConfigurationPermissions::createForEntityType(
			$factory->getEntityTypeId(),
			$sourceCategoryId === null ? null : (int)$sourceCategoryId,
		);

		return (!$stageFieldsRequested || $capabilities->canManageStages())
			&& (!$tunnelsRequested || $capabilities->canManageTunnels())
		;
	}

	/**
	 * $categoryId names the funnel the stage is expected to stand in, as it does for
	 * {@see updateAction()}.
	 */
	public function deleteAction(Factory $factory, string $statusId, ?int $categoryId = null): bool
	{
		$stage = $this->resolveStage($factory, $statusId, $categoryId);

		if (!$stage)
		{
			$this->addError(new Error(Loc::getMessage('CRM_STAGE_STAGE_NOT_FOUND')));
			return false;
		}

		if (!ConfigurationPermissions::createForEntityType($factory->getEntityTypeId())->canManageStages())
		{
			$this->addError(ErrorCode::getAccessDeniedError());
			return false;
		}

		if ($stage->getSystem())
		{
			$this->addError(new Error(Loc::getMessage('CRM_STAGE_STAGE_IS_SYSTEM')));
			return false;
		}

		$result = $stage->delete();

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());
			return false;
		}

		return true;
	}
}
