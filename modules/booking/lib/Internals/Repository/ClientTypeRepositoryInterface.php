<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Repository;

use Bitrix\Booking\Entity;

interface ClientTypeRepositoryInterface
{
	public function getList(): Entity\Client\ClientTypeCollection;

	public function get(string $code, string $moduleId): Entity\Client\ClientType|null;
}
