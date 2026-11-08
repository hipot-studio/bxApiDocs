<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\Call;

use Bitrix\Call\Model\CallUserTable;
use Bitrix\Call\Service\FollowUpReader;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Closure;
use DateTimeImmutable;

/**
 * Calls of a chat that a user may open, newest first, as the Sync activity lists them.
 *
 * Only scalars cross the boundary: no type of the module call appears in a public signature.
 * The class is not final: isAvailable() and the closures are the seam of the tests.
 *
 * @internal
 */
class SyncCallsGateway
{
	private const MODULE_ID = 'call';

	private Closure $pageOfChat;
	private Closure $accessibleCallIdsOf;
	private Closure $detailUrlOf;

	public function __construct(
		?Closure $pageOfChat = null,
		?Closure $accessibleCallIdsOf = null,
		?Closure $detailUrlOf = null,
	)
	{
		$this->pageOfChat = $pageOfChat
			?? static fn(int $chatId, array $order, array $pagination): Result
				=> (new FollowUpReader())->listByChat($chatId, $order, $pagination)
		;
		// checkAccessClosedForm() is "call member OR access to the associated chat": with chat access already
		// known true by the caller, every row of the page is a member of that OR — no per-call query is needed.
		// Without it, only membership can still grant access, checked as one batched CallUserTable lookup.
		$this->accessibleCallIdsOf = $accessibleCallIdsOf
			?? static function (array $callIds, int $userId): array {
				if ($callIds === [] || $userId <= 0)
				{
					return [];
				}

				return array_map('intval', array_column(
					CallUserTable::getList([
						'select' => ['CALL_ID'],
						'filter' => ['=USER_ID' => $userId, '@CALL_ID' => $callIds],
					])->fetchAll(),
					'CALL_ID',
				));
			}
		;
		// Repeats the route of the call slider from call/install/index.php: the module publishes no contract for it.
		$this->detailUrlOf = $detailUrlOf ?? static fn(int $callId): string => "/call/detail/{$callId}";
	}

	public function isAvailable(): bool
	{
		return Loader::includeModule(self::MODULE_ID);
	}

	public function listAccessibleByChat(int $chatId, int $userId, int $limit, bool $userHasChatAccess): Result
	{
		$result = new Result();
		if (!$this->isAvailable())
		{
			return $result->setData(['items' => [], 'hasOlderCalls' => false]);
		}

		$page = ($this->pageOfChat)($chatId, ['startDate' => 'desc'], ['limit' => $limit]);
		if (!$page->isSuccess())
		{
			return $result->addErrors($page->getErrors());
		}

		$data = $page->getData();
		$rows = $data['items'] ?? [];

		$accessibleCallIds = null;
		if (!$userHasChatAccess)
		{
			$callIds = array_map(static fn(array $row): int => (int)$row['callId'], $rows);
			$accessibleCallIds = array_flip(($this->accessibleCallIdsOf)($callIds, $userId));
		}

		$items = [];
		foreach ($rows as $row)
		{
			$callId = (int)$row['callId'];
			if ($accessibleCallIds !== null && !isset($accessibleCallIds[$callId]))
			{
				continue;
			}

			$hasFollowUp = (bool)($row['hasFollowUp'] ?? false);
			$items[] = [
				'callId' => $callId,
				'startDate' => self::timestampOf($row['startDate'] ?? null),
				'hasFollowUp' => $hasFollowUp,
				'detailUrl' => $hasFollowUp ? ($this->detailUrlOf)($callId) : null,
			];
		}

		// Without chat access and nothing accessible on this page, hiding hasMore too avoids leaking the single
		// bit "this chat has more calls than the page" to a user who cannot open any of them.
		$hasOlderCalls = (!$userHasChatAccess && $items === []) ? false : (bool)($data['hasMore'] ?? false);

		return $result->setData([
			'items' => $items,
			'hasOlderCalls' => $hasOlderCalls,
		]);
	}

	private static function timestampOf(mixed $date): ?int
	{
		if (!is_string($date) || $date === '')
		{
			return null;
		}

		$parsed = DateTimeImmutable::createFromFormat(DATE_ATOM, $date);

		return $parsed === false ? null : $parsed->getTimestamp();
	}
}
