<?php

namespace Bitrix\Intranet\Internal\Entity\AnnualSummary;

use Bitrix\Main\Entity\EntityInterface;

interface SummaryInterface extends EntityInterface
{
	public function getId(): string;

	public function getUserId(): int;

	public function getTotal(): int;
}