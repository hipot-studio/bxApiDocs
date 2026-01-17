<?php

declare(strict_types=1);

namespace Bitrix\Booking\Controller\V1;

use Bitrix\Booking\Internals\Exception\ErrorBuilder;
use Bitrix\Booking\Internals\Exception\Exception;
use Bitrix\Booking\Internals\Integration\Catalog\ServiceSkuCreator;

class CatalogServiceSku extends BaseController
{
	private ServiceSkuCreator $serviceSkuCreator;

	protected function init()
	{
		parent::init();

		$this->serviceSkuCreator = new ServiceSkuCreator();
	}

	public function createAction(
		int $iblockId,
		string $serviceName,
	): int|null
	{
		try
		{
			return $this->serviceSkuCreator->create($iblockId, $serviceName);
		}
		catch (Exception $e)
		{
			$this->addError(ErrorBuilder::buildFromException($e));

			return null;
		}
	}
}
