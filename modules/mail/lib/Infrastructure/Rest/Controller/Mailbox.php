<?php

declare(strict_types=1);

namespace Bitrix\Mail\Infrastructure\Rest\Controller;

use Bitrix\Mail\Infrastructure\Rest\Dto\MailboxDto;
use Bitrix\Mail\Infrastructure\Rest\Dto\SenderDto;
use Bitrix\Mail\Infrastructure\Rest\RequestParams;
use Bitrix\Mail\Integration\Main\SenderProvider;
use Bitrix\Mail\MailboxTable;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Controller\RestController;
use Bitrix\Rest\V3\Dto\DtoCollection;
use Bitrix\Rest\V3\Exception\EntityNotFoundException;
use Bitrix\Rest\V3\Interaction\Request\GetRequest;
use Bitrix\Rest\V3\Interaction\Request\ListRequest;
use Bitrix\Rest\V3\Interaction\Response\GetResponse;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;

/**
 * REST controller for mailbox operations.
 */
#[DtoType(MailboxDto::class)]
class Mailbox extends RestController
{
	private const DEFAULT_LIMIT = 25;
	private const MAX_LIMIT = 100;

	/**
	 * Endpoint: mail.mailbox.get
	 *
	 * Returns a single mailbox by ID.
	 *
	 * @restMethod mail.mailbox.get
	 * @param GetRequest $request id* — mailbox identifier
	 * @return GetResponse {result: MailboxDto}
	 */
	public function getAction(GetRequest $request): GetResponse
	{
		$userId = (int)CurrentUser::get()->getId();
		$mailboxId = (int)$request->id;

		// MailboxTable::getUserMailboxes returns array keyed by mailbox ID (see mail/lib/mailbox.php)
		$mailboxes = MailboxTable::getUserMailboxes($userId);

		if (!isset($mailboxes[$mailboxId]))
		{
			throw new EntityNotFoundException($mailboxId);
		}

		return new GetResponse($this->mapMailboxToDto($mailboxes[$mailboxId]));
	}

	/**
	 * Endpoint: mail.mailbox.list
	 *
	 * Returns a list of user's connected mailboxes.
	 *
	 * @restMethod mail.mailbox.list
	 * @param ListRequest $request filter: name (partial), email (partial)
	 * @return ListResponse {result: MailboxDto[]}
	 */
	public function listAction(ListRequest $request): ListResponse
	{
		$userId = (int)CurrentUser::get()->getId();
		$params = new RequestParams($this->getRequest()->getJsonList());

		$name = $params->getString('name');
		$email = $params->getString('email');

		$limit = min($request->pagination?->getLimit() ?? self::DEFAULT_LIMIT, self::MAX_LIMIT);
		$offset = max(0, $request->pagination?->getOffset() ?? 0);

		$filtered = [];
		foreach (MailboxTable::getUserMailboxes($userId) as $mailbox)
		{
			if ($name !== null && mb_stripos($mailbox['NAME'] ?? '', $name) === false)
			{
				continue;
			}

			if ($email !== null && mb_stripos($mailbox['EMAIL'] ?? '', $email) === false)
			{
				continue;
			}

			$filtered[] = $mailbox;
		}

		$page = array_slice($filtered, $offset, $limit);

		$collection = new DtoCollection(MailboxDto::class);
		foreach ($page as $mailbox)
		{
			$collection->add($this->mapMailboxToDto($mailbox));
		}

		return new ListResponse($collection);
	}

	private function mapMailboxToDto(array $mailbox): MailboxDto
	{
		$dto = new MailboxDto();
		$dto->id = (int)($mailbox['ID'] ?? 0);
		$dto->name = $mailbox['NAME'] ?? '';
		$dto->email = $mailbox['EMAIL'] ?? '';
		$dto->senderName = (string)($mailbox['USERNAME'] ?? '');

		return $dto;
	}

	/**
	 * Endpoint: mail.mailbox.senders
	 *
	 * Returns sender addresses available for the current user.
	 *
	 * @restMethod mail.mailbox.senders
	 * @param ListRequest $request
	 * @return ListResponse {result: SenderDto[]}
	 */
	public function sendersAction(ListRequest $request): ListResponse
	{
		$userId = (int)CurrentUser::get()->getId();

		$limit = min($request->pagination?->getLimit() ?? self::DEFAULT_LIMIT, self::MAX_LIMIT);
		$offset = max(0, $request->pagination?->getOffset() ?? 0);

		$page = array_slice(SenderProvider::getAvailableSenders($userId), $offset, $limit);

		$collection = new DtoCollection(SenderDto::class);
		foreach ($page as $sender)
		{
			$dto = new SenderDto();
			$dto->email = $sender['email'];
			$dto->name = $sender['name'];
			$dto->sender = $sender['sender'];

			$collection->add($dto);
		}

		return new ListResponse($collection);
	}
}
