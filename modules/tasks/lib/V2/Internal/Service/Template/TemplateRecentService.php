<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Service\Template;

use Bitrix\Tasks\V2\Internal\Repository\Template\TemplateRecentRepositoryInterface;

class TemplateRecentService
{
	private const LIMIT = 30;

	public function __construct(
		private readonly TemplateRecentRepositoryInterface $repository,
	)
	{
	}

	public function executeAdd(int $userId, int $templateId): void
	{
		$recent = $this->repository->get($userId);

		if (!empty($recent) && $recent[0] === $templateId)
		{
			return;
		}

		$key = array_search($templateId, $recent, true);
		if ($key !== false)
		{
			unset($recent[$key]);
		}

		array_unshift($recent, $templateId);
		$recent = array_slice($recent, 0, self::LIMIT);

		$this->repository->save($userId, $recent);
	}

	public function executeRemove(int $userId, int $templateId): void
	{
		$recent = $this->repository->get($userId);

		$key = array_search($templateId, $recent, true);
		if ($key !== false)
		{
			unset($recent[$key]);
			$this->repository->save($userId, array_values($recent));
		}
	}
}
