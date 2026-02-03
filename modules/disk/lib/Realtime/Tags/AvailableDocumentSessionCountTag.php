<?php

declare(strict_types=1);

namespace Bitrix\Disk\Realtime\Tags;

use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;
use CPullWatch;

class AvailableDocumentSessionCountTag extends Tag
{
	public function getName(): string
	{
		return 'available_document_session_count';
	}

	public function subscribe(): void
	{
		if (Loader::includeModule('pull'))
		{
			CPullWatch::Add(
				CurrentUser::get()->getId(),
				$this->getName(),
			);

		}
	}
}