<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Integration\Bizproc;

use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTriggerTable;
use Bitrix\Main\Loader;

class AiAgentTemplateQuery
{
	private const SYSTEM_CODE = 'bitrix_booking_ai_call';
	private const TRIGGER_TYPE = 'BookingAiCallTrigger';

	/**
	 * @return int[]
	 */
	public function findUserTemplateIds(): array
	{
		if (!Loader::includeModule('bizproc'))
		{
			return [];
		}

		$triggerRows = WorkflowTemplateTriggerTable::query()
			->setSelect(['TEMPLATE_ID'])
			->where('TRIGGER_TYPE', self::TRIGGER_TYPE)
			->exec()
		;

		$templateIds = [];
		while ($triggerRow = $triggerRows->fetch())
		{
			$templateIds[] = (int)$triggerRow['TEMPLATE_ID'];
		}

		if (empty($templateIds))
		{
			return [];
		}

		$rows = WorkflowTemplateTable::query()
			->setSelect(['ID'])
			->whereIn('ID', $templateIds)
			->whereNull('SYSTEM_CODE')
			->where('ACTIVE', true)
			->exec()
		;

		$result = [];
		while ($row = $rows->fetch())
		{
			$result[] = (int)$row['ID'];
		}

		return $result;
	}

	public function findUserTemplateOwnerUserId(): int
	{
		$templateIds = $this->findUserTemplateIds();

		if (count($templateIds) !== 1)
		{
			return 0;
		}

		$row = WorkflowTemplateTable::query()
			->setSelect(['ACTIVATED_BY', 'CREATED_BY', 'USER_ID'])
			->where('ID', $templateIds[0])
			->fetch()
		;

		if ($row === false)
		{
			return 0;
		}

		$activatedBy = (int)($row['ACTIVATED_BY'] ?? 0);
		if ($activatedBy > 0)
		{
			return $activatedBy;
		}

		$createdBy = (int)($row['CREATED_BY'] ?? 0);
		if ($createdBy > 0)
		{
			return $createdBy;
		}

		$userId = (int)($row['USER_ID'] ?? 0);
		if ($userId > 0)
		{
			return $userId;
		}

		return 0;
	}

	public function findSystemTemplateId(): int|null
	{
		if (!Loader::includeModule('bizproc'))
		{
			return null;
		}

		$row = WorkflowTemplateTable::query()
			->setSelect(['ID'])
			->where('SYSTEM_CODE', self::SYSTEM_CODE)
			->setLimit(1)
			->fetch()
		;

		if ($row === false)
		{
			return null;
		}

		return (int)$row['ID'];
	}
}
