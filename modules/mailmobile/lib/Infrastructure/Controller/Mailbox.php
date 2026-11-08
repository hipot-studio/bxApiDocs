<?php

namespace Bitrix\MailMobile\Infrastructure\Controller;

use Bitrix\Intranet;
use Bitrix\Main;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Engine\Controller;

// Prototype stub: no real mailbox provisioning; returns a fixed successful response.
class Mailbox extends Controller
{
	protected function getDefaultPreFilters(): array
	{
		return [
			new Main\Engine\ActionFilter\ContentType([Main\Engine\ActionFilter\ContentType::JSON, 'application/x-www-form-urlencoded']),
			new Main\Engine\ActionFilter\Authentication(),
			new Main\Engine\ActionFilter\HttpMethod(
				[Main\Engine\ActionFilter\HttpMethod::METHOD_POST]
			),
			new Intranet\ActionFilter\IntranetUser(),
		];
	}

	public function createBitrixMailboxAction(array $fields = []): array
	{
		// Prototype stub: always returns a deterministic fake mailbox.
		return [
			'id' => 1,
			'email' => 'mailbox@bitrix24',
		];
	}

	public function getFeatureStateAction(): array
	{
		return [
			'enabled' => Option::get('mailmobile', 'create_bitrix_mailbox_enabled', 'N') === 'Y',
		];
	}
}
