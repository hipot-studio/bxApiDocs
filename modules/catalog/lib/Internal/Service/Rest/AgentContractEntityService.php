<?php

declare(strict_types=1);

namespace Bitrix\Catalog\Internal\Service\Rest;

use Bitrix\Catalog\v2\AgentContract\AccessController as AgentContractAccessController;
use Bitrix\Catalog\v2\AgentContract\Manager;
use Bitrix\Main\Result;

final class AgentContractEntityService
{
	/**
	 * @param list<int> $ids
	 */
	public function delete(array $ids): Result
	{
		$access = $this->ensureAccess();
		if (!$access->isSuccess())
		{
			return $access;
		}

		$ids = array_values(array_unique(array_map('intval', $ids)));
		$errors = [];
		foreach ($ids as $id)
		{
			$deleteResult = Manager::delete($id);
			if (!$deleteResult->isSuccess())
			{
				foreach ($deleteResult->getErrors() as $error)
				{
					$errors[] = $error;
				}
			}
		}

		if ($errors !== [])
		{
			return ServiceResult::validation($errors);
		}

		return ServiceResult::success(['result' => true]);
	}

	private function ensureAccess(): Result
	{
		if (!AgentContractAccessController::check())
		{
			return ServiceResult::accessDenied();
		}

		return ServiceResult::success();
	}
}
