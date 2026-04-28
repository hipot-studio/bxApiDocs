<?php
declare(strict_types=1);

namespace Bitrix\Landing\Transfer\Script\Action;

use Bitrix\Landing\Hook;
use Bitrix\Landing\Transfer\AppConfiguration;
use Bitrix\Landing\Transfer\TransferException;

class UnpackAdditionalFiles extends Blank
{
	public function action(): void
	{
		$structure = $this->context->getStructure();
		if (!isset($structure))
		{
			throw new TransferException('Structure is not set');
		}

		$data = $this->context->getData();
		foreach (Hook::HOOKS_CODES_FILES as $hookCode)
		{
			$fileId = (int)($data['ADDITIONAL_FIELDS'][$hookCode] ?? null);
			if ($fileId > 0)
			{
				$unpackFile = $structure->getUnpackFile($fileId);
				if ($unpackFile)
				{
					$data['ADDITIONAL_FIELDS'][$hookCode] = AppConfiguration::saveFile(
						$unpackFile
					);
				}
				else
				{
					unset($data['ADDITIONAL_FIELDS'][$hookCode]);
				}
			}
		}
		$this->context->setData($data);
	}
}
