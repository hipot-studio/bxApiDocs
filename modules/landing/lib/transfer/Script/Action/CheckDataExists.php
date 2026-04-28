<?php
declare(strict_types=1);

namespace Bitrix\Landing\Transfer\Script\Action;

use Bitrix\Landing\Transfer\Requisite;
use Bitrix\Landing\Transfer\TransferException;

class CheckDataExists extends Blank
{
	public function action(): void
	{
		$data = $this->context->getData();

		if (!isset($data))
		{
			throw new TransferException(
				'Data has no exists',
				Requisite\ExceptionCode::InvalidContext->value
			);
		}
	}

}
