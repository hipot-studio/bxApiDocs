<?php
declare(strict_types=1);

namespace Bitrix\Landing\Transfer\Script\Action;

class FinishEpisode extends Blank
{
	public function action(): void
	{
		$this->setEndEpisode();
	}
}
