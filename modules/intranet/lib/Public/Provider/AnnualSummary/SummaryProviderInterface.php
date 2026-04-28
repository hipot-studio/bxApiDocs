<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Public\Provider\AnnualSummary;

use Bitrix\Intranet\Internal\Entity\AnnualSummary\SummaryInterface;
use Bitrix\Main\Entity\EntityCollection;

interface SummaryProviderInterface
{
	public function getId(): string;

	public function getLastUserId(): int;

	public function getUserIdLimit(): int;

	/**
	 * @param int[] $userIds
	 * @return EntityCollection<SummaryInterface>
	 */
	public function provide(array $userIds): EntityCollection;
}