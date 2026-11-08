<?php

declare(strict_types=1);

namespace Bitrix\Intranet\UI\LeftMenu\MenuItem;

use Bitrix\Intranet\UI\LeftMenu\User;

class ItemVibecode extends ItemSystem
{
	public function __construct(string $title, string $url)
	{
		parent::__construct([
			'ID' => 'menu_vibecode',
			'TEXT' => $title,
			'LINK' => $url,
		]);
	}

	protected function adjustData($data, User $user): array
	{
		$data = parent::adjustData($data, $user);
		$data['OPEN_IN_NEW_PAGE'] = 'Y';
		$data['PARAMS']['can_be_first_item'] = false;

		return $data;
	}
}
