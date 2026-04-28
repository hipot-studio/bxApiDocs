<?php
declare(strict_types=1);

namespace Bitrix\Landing\Transfer\Script\Action;

use Bitrix\Landing\Transfer\Requisite;
use Bitrix\Landing\Transfer\Script\Action\Closet\ContexterTrait;

class CheckIsNeedImportPage extends Blank
{
	use ContexterTrait;

	public function action(): void
	{
		if (!$this->isImportPageScript())
		{
			return;
		}

		$id = (int)($this->context->getRunData()->get(Requisite\Dictionary\RunDataPart::OldId));
		if ($id <= 0)
		{
			$this->setEndEpisode();

			return;
		}

		$isIndexPage = $this->isIndexPage();
		if (!$isIndexPage)
		{
			$this->setEndEpisode();
		}
	}

}
