<?php

declare(strict_types=1);

namespace Bitrix\Catalog\Internal\Service\Rest;

use Bitrix\Catalog\Config\State;
use Bitrix\Catalog\Store\EnableWizard\Manager;
use Bitrix\Main\Result;

final readonly class DocumentModeService
{
	public function __construct(
		private AccessSupport $accessSupport,
	)
	{
	}

	public function status(): Result
	{
		$access = $this->accessSupport->ensureEntityRead();
		if (!$access->isSuccess())
		{
			return $access;
		}

		return ServiceResult::success([
			'status' => State::isUsedInventoryManagement() ? 'Y' : 'N',
		]);
	}

	public function statusDetails(): Result
	{
		$access = $this->accessSupport->ensureEntityRead();
		if (!$access->isSuccess())
		{
			return $access;
		}

		return ServiceResult::success([
			'enabled' => State::isUsedInventoryManagement(),
			'mode' => Manager::getCurrentMode(),
		]);
	}
}
