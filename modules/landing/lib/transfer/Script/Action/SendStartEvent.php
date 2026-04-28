<?php
declare(strict_types=1);

namespace Bitrix\Landing\Transfer\Script\Action;

use Bitrix\Landing\Site;
use Bitrix\Landing\Transfer\Requisite\Dictionary\RatioPart;
use Bitrix\Landing\Transfer\TransferException;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Localization\Loc;

class SendStartEvent extends Blank
{
	public function action(): void
	{
		// todo: like this
		// if ($data['TYPE'] === 'VIBE')
		// {
		// 	(new \Bitrix\Landing\Mainpage\Manager())->onStartPageCreation();
		// }
	}
}