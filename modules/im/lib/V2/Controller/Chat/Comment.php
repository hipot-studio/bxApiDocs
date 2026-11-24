<?php

namespace Bitrix\Im\V2\Controller\Chat;

use Bitrix\Im\V2\Analytics\ChatAnalytics;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Chat\ChatError;
use Bitrix\Im\V2\Chat\Comment\CommentListProvider;
use Bitrix\Im\V2\Controller\BaseController;
use Bitrix\Im\V2\Controller\Filter\ChatTypeFilter;
use Bitrix\Im\V2\Controller\Filter\ThreadCapableChatFilter;
use Bitrix\Im\V2\Message\Forward\ForwardSourcePopupItem;
use Bitrix\Im\V2\Message\MessagePopupItem;
use Bitrix\Im\V2\MessageCollection;
use Bitrix\Im\V2\Reading\Reader;
use Bitrix\Im\V2\Rest\PopupData;
use Bitrix\Im\V2\Rest\RestAdapter;

class Comment extends BaseController
{
	public function configureActions()
	{
		$config = parent::configureActions();

		$config['subscribe'] = [
			'+prefilters' => [
				new ChatTypeFilter([Chat\CommentChat::class]),
			],
		];
		$config['unsubscribe'] = [
			'+prefilters' => [
				new ChatTypeFilter([Chat\CommentChat::class]),
			],
		];
		$config['readAll'] = [
			'+prefilters' => [
				new ThreadCapableChatFilter(),
			],
		];
		$config['list'] = [
			'+prefilters' => [
				new ThreadCapableChatFilter(ChatError::WRONG_PARENT_CHAT),
			],
		];

		return $config;
	}

	/**
	 * API-01: the table of contents of the discussions of a chat, all of them and not the
	 * subscriptions of the reader alone. dialogId addresses the parent chat here, as it does for
	 * readAll and unlike subscribe/unsubscribe, where it addresses the thread itself.
	 *
	 * Not to be confused with im.v2.Chat.Message.CommentInfo.list, which answers with the plates of a
	 * set of messages: the names are close, the purposes are not, and both stay.
	 *
	 * offset counts the ordered sequence of the discussions and the answer names where the next page
	 * starts, in nextOffset: the filters of the list drop rows out of the window they were read in, so
	 * the number of cells that came back does not say how far the sequence was walked. A cursor would
	 * not do either: the order follows what this reader has left unread and shifts as they read.
	 *
	 * @restMethod im.v2.Chat.Comment.list
	 */
	public function listAction(
		Chat $chat,
		CommentListProvider $provider,
		int $limit = self::DEFAULT_LIMIT,
		int $offset = 0
	): ?array
	{
		$page = $provider->getPage($chat, $this->getLimit($limit), max($offset, 0));

		// The plates of the root posts are not assembled here: the cell already holds every number they
		// would show, and building them would count the replies of the page twice.
		//
		// The collection is shared by the two items on purpose: the title of the source of a forwarded
		// post is written into the message itself, and ForwardSourcePopupItem is a prefill item, so
		// PopupData resolves the titles before the very messages the other item serializes. Reached
		// through the nested level of messages - as it was - the resolving ran after the posts were
		// already serialized, and a post forwarded from a discussion carried no name of its source, not
		// even for a reader who has access to it.
		$rootMessages = new MessageCollection($page->getRootMessageIds());
		$popupData = new PopupData([
			new ForwardSourcePopupItem($rootMessages),
			MessagePopupItem::getInstanceMessages($rootMessages),
		]);
		$rest = (new RestAdapter())->setAdditionalPopupData($popupData)->toRestFormat();

		$rest['threads'] = $page->toRestFormat();
		$rest['hasNextPage'] = $page->hasNextPage;
		$rest['nextOffset'] = $page->nextOffset;

		return $rest;
	}

	/**
	 * source names the place the gesture was made from and is optional: a client that does not send
	 * it subscribes exactly as it always did, and only the analytics label is the poorer for it.
	 *
	 * @restMethod im.v2.Chat.Comment.subscribe
	 */
	public function subscribeAction(Chat\CommentChat $chat, ?string $source = null): ?array
	{
		return $this->subscribe($chat, true, $source);
	}

	/**
	 * @restMethod im.v2.Chat.Comment.unsubscribe
	 */
	public function unsubscribeAction(Chat\CommentChat $chat, ?string $source = null): ?array
	{
		return $this->subscribe($chat, false, $source);
	}

	/**
	 * @restMethod im.v2.Chat.Comment.readAll
	 */
	public function readAllAction(Chat $chat, Reader $reader): ?array
	{
		// no second condition on purpose: the admissible set of parents is decided once, by the prefilter
		$reader->readCommentChildren($chat, $this->getCurrentUser()->getId());

		(new ChatAnalytics($chat))->addReadAllThreads();

		return ['result' => true];
	}

	protected function subscribe(Chat\CommentChat $chat, bool $flag, ?string $source = null): ?array
	{
		$result = $chat->subscribe($flag);

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		(new ChatAnalytics($chat))->addFollowComments($flag, $source);

		return ['result' => true];
	}
}
