<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Provider\Address;

use Bitrix\Crm\V2\Internal\Service\Address\RequisiteAddressService;
use Bitrix\Crm\V2\Public\Entity\Address\Address;

final class RequisiteAddressProvider
{
	public function __construct(private readonly RequisiteAddressService $service = new RequisiteAddressService())
	{
	}

	public function get(int $requisiteId, int $typeId, int $ownerTypeId, int $userId): Address
	{
		return $this->service->get($requisiteId, $typeId, $ownerTypeId, $userId);
	}

	/**
	 * @return array<int, Address>
	 */
	public function getList(
		int $requisiteId,
		int $ownerTypeId,
		int $userId,
		array $filter = [],
		array $order = [],
		?int $limit = null,
		?int $offset = null,
	): array
	{
		return $this->service->getList(
			$requisiteId,
			$ownerTypeId,
			$userId,
			$filter,
			$order,
			$limit,
			$offset,
		);
	}
}
