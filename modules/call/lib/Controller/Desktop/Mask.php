<?php

namespace Bitrix\Call\Controller\Desktop;

use Bitrix\Main\Engine\Controller;

/**
 * @internal
 */
class Mask extends Controller
{
	/**
	 * @restMethod call.Desktop.Mask.get
	 */
	public function getAction(): array
	{
		return [
			'masks' => \Bitrix\Call\Desktop\Mask::get(),
		];
	}
}