<?php
declare(strict_types=1);

namespace Bitrix\Landing\Transfer\Script\Action;

use Bitrix\Landing\Transfer\Requisite\Dictionary\RatioPart;
use Bitrix\Landing\Transfer\Requisite\Dictionary\RunDataPart;
use Bitrix\Landing\Transfer\Script\Action\Closet\ContexterTrait;

class SaveIndexPage extends Blank
{
	use ContexterTrait;

	public function action(): void
	{
		$newId = $this->context->getRunData()->get(RunDataPart::NewId);
		if ($newId > 0 && $this->isIndexPage())
		{
			$this->context->getRatio()->set(
				RatioPart::IndexPageId,
				$newId
			);
		}
	}
}
