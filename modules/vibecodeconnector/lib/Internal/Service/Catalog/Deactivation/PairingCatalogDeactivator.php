<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Catalog\Deactivation;

use Bitrix\Main\Type\DateTime;
use Bitrix\Vibecodeconnector\Internal\Repository\Catalog\CatalogItemRepository;

final class PairingCatalogDeactivator
{
	public function __construct(
		private readonly CatalogItemRepository $repository = new CatalogItemRepository(),
	) {
	}

	public function deactivateByIss(string $iss): void
	{
		if ($iss === '')
		{
			return;
		}

		$this->repository->deactivateByPairingIss($iss, new DateTime());
	}
}
