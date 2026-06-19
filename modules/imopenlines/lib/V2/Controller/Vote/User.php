<?php

namespace Bitrix\ImOpenLines\V2\Controller\Vote;

use Bitrix\ImOpenLines\V2\Controller\BaseController;
use Bitrix\ImOpenLines\V2\Controller\Filter\VoteAsUserAccessCheck;
use Bitrix\ImOpenLines\V2\Session\Session;
use Bitrix\Main\Error;

class User extends BaseController
{
	protected function getDefaultPreFilters()
	{
		return array_merge(
			parent::getDefaultPreFilters(),
			[
				new VoteAsUserAccessCheck(),
			],
		);
	}

	/**
	 * @restMethod imopenlines.v2.Vote.User.vote
	 */
	public function voteAction(Session $session, bool $action): ?array
	{
		$currentUserId = (int)$this->getCurrentUser()?->getId();
		$result = \Bitrix\ImOpenLines\Session::voteAsUser($session->getSessionId(), $action ? 'like' : 'dislike', $currentUserId);
		if (!$result)
		{
			$this->addError(new Error('Vote failed', 'VOTE_ERROR'));

			return null;
		}

		return ['result' => true];
	}
}
