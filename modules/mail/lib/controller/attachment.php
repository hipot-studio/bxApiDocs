<?php

namespace Bitrix\Mail\Controller;

use Bitrix\Mail\Helper\Config\Feature;
use Bitrix\Mail\Internal\Service\Attachment\ArchiveService;
use Bitrix\Main;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\Response\Zip;

class Attachment extends Controller
{
	public function configureActions(): array
	{
		$actions = parent::configureActions();

		// Plain GET navigation from the download link: no CSRF token to carry, an expired
		// session lands on the login form, and streaming must not block other requests.
		$actions['downloadArchive'] = [
			'-prefilters' => [
				Main\Engine\ActionFilter\Csrf::class,
				Main\Engine\ActionFilter\Authentication::class,
			],
			'+prefilters' => [
				new Main\Engine\ActionFilter\Authentication(true),
				new Main\Engine\ActionFilter\CloseSession(),
			],
		];

		return $actions;
	}

	public function downloadArchiveAction(int $messageId): ?Zip\Archive
	{
		if (!Feature::isMailListImprovementsAvailable())
		{
			$this->addError(new Main\Error('Feature is not available.', 'MAIL_LIST_IMPROVEMENTS_DISABLED'));

			return null;
		}

		$result = (new ArchiveService())->buildMessageArchive($messageId, (int)$this->getCurrentUser()->getId());

		if (!$result->isSuccess())
		{
			$this->addError($result->getErrors()[0]);

			return null;
		}

		return $result->getData()['archive'];
	}
}
