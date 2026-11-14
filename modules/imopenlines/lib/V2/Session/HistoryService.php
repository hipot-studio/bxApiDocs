<?php

namespace Bitrix\ImOpenLines\V2\Session;

use Bitrix\Im\Model\LinkPinTable;
use Bitrix\Im\Model\MessageTable;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Link\Pin\PinCollection;
use Bitrix\Im\V2\Message;
use Bitrix\Im\V2\MessageCollection;
use Bitrix\Im\V2\Rest\RestAdapter;
use Bitrix\ImOpenLines\Config;
use Bitrix\ImOpenLines\Model\SessionTable;
use Bitrix\ImOpenLines\V2\Chat\OpenLineChatDecorator;
use Bitrix\Main\Loader;
use Bitrix\Main\Type\DateTime;

Loader::requireModule('im');

/**
 * Read-only assembly of an open line session history slice in the V2 Chat.load shape.
 */
class HistoryService
{
	private const PIN_LIMIT = 50;

	private Session $session;

	public function __construct(Session $session)
	{
		$this->session = $session;
	}

	/**
	 * @param int $limit page size, most recent messages of the window (older than the cursor).
	 * @param int $lastId keyset cursor: 0 => first page; > 0 => messages of the window with ID < lastId.
	 * @return array first page: Chat.load payload (chat, messages, users, files, reactions,
	 *  additionalMessages, pins) + openlines + session + hasPrevPage. Older pages: messages
	 *  payload + hasPrevPage only - the chat/session context is already loaded by the first page.
	 */
	public function getHistory(int $limit, int $lastId = 0): array
	{
		$limit = max($limit, 1);
		$chatId = (int)$this->session->getChatId();
		$startId = (int)$this->session->getStartId();
		$endId = (int)$this->session->getEndId();

		[$messages, $hasPrevPage] = $this->loadMessages($chatId, $startId, $endId, $limit, $lastId);

		if ($lastId > 0)
		{
			$payload = (new RestAdapter($messages))->toRestFormat();
			$payload['hasPrevPage'] = $hasPrevPage;

			return $payload;
		}

		$chat = Chat::getInstance($chatId);
		$pins = $this->loadWindowPins($chatId, $startId, $endId);
		$openLines = (new OpenLineChatDecorator($chat))->toRestFormat();

		$payload = (new RestAdapter($chat, $messages, $pins))->toRestFormat();
		$payload = array_merge($payload, $openLines);
		$payload['session'] = $this->getSessionBlock($openLines['openlines']['crm'] ?? []);
		$payload['hasPrevPage'] = $hasPrevPage;

		return $payload;
	}

	/**
	 * Builds the V2 message collection page for the session window: the most recent $limit
	 * messages of the window, restored to chronological order. The window is a hard boundary -
	 * the cursor can only narrow it.
	 *
	 * @return array{0: MessageCollection, 1: bool} page + exact "the window has older messages" flag
	 *  (computed by selecting one extra id, not by a count heuristic).
	 */
	private function loadMessages(int $chatId, int $startId, int $endId, int $limit, int $lastId): array
	{
		// b_im_message has no (CHAT_ID, ID) index, so a chat slice is read through
		// IX_IM_MESS_5 (CHAT_ID, DATE_CREATE): the DATE_CREATE order lets the scan stop at the
		// limit instead of sorting the whole chat. Same scheme as Im\V2\Message\MessageService.
		$idQuery = $this->buildRangeQuery($chatId, $startId, $endId)
			->setSelect(['ID'])
			->setOrder(['DATE_CREATE' => 'DESC', 'ID' => 'DESC'])
			->setLimit($limit + 1)
		;
		if ($lastId > 0)
		{
			$idQuery->where('ID', '<', $lastId);
		}

		$anchorDate = $this->getScanAnchorDate($chatId, $lastId > 0 ? $lastId : $endId);
		if ($anchorDate !== null)
		{
			$idQuery->where('DATE_CREATE', '<=', $anchorDate);
		}

		$recentIds = $idQuery->fetchCollection()->getIdList();

		$hasPrevPage = count($recentIds) > $limit;
		if ($hasPrevPage)
		{
			// The ids are ordered newest first, so the extra (oldest) one is the last element.
			array_pop($recentIds);
		}

		if (empty($recentIds))
		{
			return [new MessageCollection(), false];
		}

		$messages = new MessageCollection(
			MessageTable::query()
				->setSelect(Message::REST_FIELDS)
				->whereIn('ID', $recentIds)
				->setOrder(['ID' => 'ASC'])
				->fetchCollection()
		);

		return [$messages, $hasPrevPage];
	}

	/**
	 * DATE_CREATE of the message the descending scan starts from: the cursor for older pages,
	 * the window end for the first page. Without it the scan would enter IX_IM_MESS_5 at the
	 * newest message of the chat and walk over everything the ID filters then reject. The bound
	 * only narrows the index range - the exact page boundary stays the keyset cursor on ID.
	 *
	 * @return DateTime|null null for an open ended window or a missing anchor message: the scan
	 *  then starts at the newest message, as before.
	 */
	private function getScanAnchorDate(int $chatId, int $anchorId): ?DateTime
	{
		if ($anchorId <= 0)
		{
			return null;
		}

		$anchor = MessageTable::query()
			->setSelect(['DATE_CREATE'])
			->where('CHAT_ID', $chatId)
			->where('ID', $anchorId)
			->fetchObject()
		;

		return $anchor?->getDateCreate();
	}

	/**
	 * Pins of the window, newest first. Both boundaries are applied in SQL here instead of going
	 * through PinCollection::find(): its END_ID filter key needs a newer im module, and where the
	 * key is ignored the pins of later sessions fill the query limit and push the window pins out.
	 */
	private function loadWindowPins(int $chatId, int $startId, int $endId): PinCollection
	{
		$query = LinkPinTable::query()
			->setSelect(['ID', 'CHAT_ID', 'AUTHOR_ID', 'DATE_CREATE', 'MESSAGE_ID'])
			->where('CHAT_ID', $chatId)
			->setOrder(['ID' => 'DESC'])
			->setLimit(self::PIN_LIMIT)
		;

		if ($startId > 0)
		{
			$query->where('MESSAGE_ID', '>=', $startId);
		}
		if ($endId > 0)
		{
			$query->where('MESSAGE_ID', '<=', $endId);
		}

		$pins = $query->fetchCollection();

		return $pins === null ? new PinCollection() : PinCollection::initByEntityCollection($pins);
	}

	private function buildRangeQuery(int $chatId, int $startId, int $endId): \Bitrix\Main\ORM\Query\Query
	{
		$query = MessageTable::query()->where('CHAT_ID', $chatId);

		if ($startId > 0)
		{
			$query->where('ID', '>=', $startId);
		}
		if ($endId > 0)
		{
			$query->where('ID', '<=', $endId);
		}

		return $query;
	}

	/**
	 * @param array $crm openlines.crm block of the chat payload: the CRM binding of the chat.
	 */
	private function getSessionBlock(array $crm): array
	{
		$configId = (int)$this->session->getConfigId();

		// Legacy parity: the CRM-based join permission branch applies only while
		// the chat CRM binding is active (ENTITY_DATA_1 starts with 'Y').
		$isCrmEnabled = ($crm['crmEnabled'] ?? false) === true;
		$crmEntityType = $isCrmEnabled ? $crm['crmEntityType'] : null;
		$crmEntityId = $isCrmEnabled ? $crm['crmEntityId'] : null;

		[$voteHead, $commentHead] = $this->getVoteHead();

		return [
			'sessionId' => (int)$this->session->getSessionId(),
			'canJoin' => Config::canJoin($configId, $crmEntityType, $crmEntityId),
			'canVoteAsHead' => Config::canVoteAsHead($configId),
			'sessionVoteHead' => $voteHead,
			'sessionCommentHead' => $commentHead,
		];
	}

	/**
	 * VOTE_HEAD / COMMENT_HEAD are missing from the field map of the V2 Session, so the already
	 * loaded session object cannot carry them and the first page reads them directly. Adding the
	 * two fields to Session::mirrorDataEntityFields() would remove this query.
	 *
	 * @return array{0: int, 1: string}
	 */
	private function getVoteHead(): array
	{
		$row = SessionTable::getList([
			'select' => ['VOTE_HEAD', 'COMMENT_HEAD'],
			'filter' => ['=ID' => (int)$this->session->getSessionId()],
		])->fetch();

		if (!$row)
		{
			return [0, ''];
		}

		return [(int)$row['VOTE_HEAD'], (string)($row['COMMENT_HEAD'] ?? '')];
	}
}
