<?php

namespace Bitrix\ImOpenLines\V2\Controller\Vote;

use Bitrix\ImOpenLines\V2\Controller\BaseController;
use Bitrix\ImOpenLines\V2\Controller\Filter\CommentAsHeadValidate;
use Bitrix\ImOpenLines\V2\Controller\Filter\VoteAsHeadAccessCheck;
use Bitrix\ImOpenLines\V2\Controller\Filter\VoteAsHeadValidate;
use Bitrix\ImOpenLines\V2\Session\Session;
use Bitrix\Main\Error;

class Head extends BaseController
{
	protected function getDefaultPreFilters()
	{
		return array_merge(
			parent::getDefaultPreFilters(),
			[
				new VoteAsHeadAccessCheck(),
			],
		);
	}

	public function configureActions()
	{
		return [
			'vote' => [
				'+prefilters' => [
					new VoteAsHeadValidate(),
				],
			],
			'comment' => [
				'+prefilters' => [
					new CommentAsHeadValidate(),
				],
			],
		];
	}

	/**
	 * @restMethod imopenlines.v2.Vote.Head.vote
	 */
	public function voteAction(Session $session, int $rating): ?array
	{
		$result = \Bitrix\ImOpenLines\Session::voteAsHead($session->getSessionId(), $rating, null);
		if (!$result)
		{
			$this->addError(new Error('Vote failed', 'VOTE_ERROR'));

			return null;
		}

		return ['result' => true];
	}

	/**
	 * @restMethod imopenlines.v2.Vote.Head.comment
	 */
	public function commentAction(Session $session, string $comment): ?array
	{
		$result = \Bitrix\ImOpenLines\Session::voteAsHead($session->getSessionId(), null, $comment);
		if (!$result)
		{
			$this->addError(new Error('Comment failed', 'COMMENT_ERROR'));

			return null;
		}

		return ['result' => true];
	}
}
