<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Integration\Bizproc;

use Bitrix\Bizproc\Public\Service\WorkflowTemplate\LaunchedTemplateService;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable;
use Bitrix\Main\Loader;

class AiAgentTemplateQuery
{
	private const SYSTEM_CODE = 'bitrix_booking_ai_call';

	/**
	 * @return int[]
	 */
	public function findUserTemplateIds(): array
	{
		if (!Loader::includeModule('bizproc'))
		{
			return [];
		}

		return (new LaunchedTemplateService())->getIdsBySystemCodes([self::SYSTEM_CODE]);
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
