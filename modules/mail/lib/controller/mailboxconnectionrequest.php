<?php

declare(strict_types=1);

namespace Bitrix\Mail\Controller;

use Bitrix\Intranet;
use Bitrix\Mail\Helper\Mailbox\MailboxConnectionRequestService;
use Bitrix\Main;
use Bitrix\Main\Engine\Controller;

class MailboxConnectionRequest extends Controller
{
	private MailboxConnectionRequestService $mailboxConnectionRequestService;

	protected function init(): void
	{
		parent::init();
		$this->mailboxConnectionRequestService = new MailboxConnectionRequestService();
	}

	protected function getDefaultPreFilters(): array
	{
		return [
			new Main\Engine\ActionFilter\ContentType([
				Main\Engine\ActionFilter\ContentType::JSON,
				'application/x-www-form-urlencoded',
			]),
			new Main\Engine\ActionFilter\Authentication(),
			new Main\Engine\ActionFilter\HttpMethod([
				Main\Engine\ActionFilter\HttpMethod::METHOD_POST,
			]),
			new Intranet\ActionFilter\IntranetUser(),
		];
	}

	public function createRequestAction(string $comment = ''): array
	{
		$result = $this->mailboxConnectionRequestService->createRequest($comment);

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return [];
		}

		return $result->getData();
	}

	public function rejectRequestAction(int $requestId): array
	{
		$service = new MailboxConnectionRequestService();

		$result = $service->rejectRequest($requestId);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return [];
		}

		return $result->getData();
	}

	public function cancelOwnRequestAction(): array
	{
		$result = $this->mailboxConnectionRequestService->cancelOwnRequest();
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return [];
		}

		return [];
	}

	public function getPendingCountAction(): array
	{
		return $this->mailboxConnectionRequestService
			->getPendingCountForController()
			->getData()
		;
	}
}
