<?php
declare(strict_types=1);

namespace Bitrix\Landing\Transfer\Script\Action;

use Bitrix\Landing\Manager;
use Bitrix\Landing\Transfer\TransferException;

class PrepareSiteData extends Blank
{
	public function action(): void
	{
		$data = $this->context->getData();

		if (!isset($data))
		{
			throw new TransferException('DATA not found');
		}

		if (!$this->context->getLang())
		{
			$data['LANG'] = Manager::getZone();
		}

		$notAllowedKeys = [
			'ID', 'DOMAIN_ID', 'DATE_CREATE', 'DATE_MODIFY',
			'CREATED_BY_ID', 'MODIFIED_BY_ID', 'CODE',
		];
		foreach ($notAllowedKeys as $key)
		{
			if (isset($data[$key]))
			{
				unset($data[$key]);
			}
		}

		$this->context->setData($data);
	}
}
