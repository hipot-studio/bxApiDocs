<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Service\WorkflowTemplate;

use Bitrix\Bizproc\Internal\Repository\WorkflowTemplate\LaunchedTemplateRepository;
use Bitrix\Main\DI\ServiceLocator;

class LaunchedTemplateService
{
	private LaunchedTemplateRepository $repository;

	public function __construct()
	{
		$this->repository = ServiceLocator::getInstance()->get(LaunchedTemplateRepository::class);
	}

	/**
	 * @return int[]
	 */
	public function getIdsBySystemTemplateId(int $systemTemplateId): array
	{
		return $this->repository->getIdsBySystemTemplateId($systemTemplateId);
	}

	/**
	 * @param string[] $systemCodes e.g. ['bitrix_ai_day_plan', 'bitrix_ai_day_plan_simple']
	 * @return int[]
	 */
	public function getIdsBySystemCodes(array $systemCodes): array
	{
		return $this->repository->getIdsBySystemCodes($systemCodes);
	}

	public function isLaunchedFrom(int $templateId, array $systemCodes): bool
	{
		return $this->repository->isLaunchedFrom($templateId, $systemCodes);
	}
}
