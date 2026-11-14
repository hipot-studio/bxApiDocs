<?php

declare(strict_types=1);

namespace Bitrix\Im\V2\Controller\Search;

use Bitrix\Im\V2\Application\Features;
use Bitrix\Im\V2\Controller\BaseController;
use Bitrix\Im\V2\Integration\Search\MessageSearchService;
use Bitrix\Im\V2\Integration\Search\Query\MessageSearchQuery;
use Bitrix\Im\V2\Integration\Search\SearchError;
use Bitrix\Im\V2\Rest\RestAdapter;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\CurrentUser;

/**
 * Cross-chat message search of the messenger.
 *
 * The controller stays thin on purpose: it parses the input, guards the visibility flag, asks the
 * service and serializes the portion. It is deliberately absent from the guest allowlist of
 * AuthorizationPrefilter, so a guest is cut off by the default of the base controller.
 */
class Message extends BaseController
{
	public function configureActions()
	{
		return [
			'search' => [
				'+prefilters' => [
					// no external contract yet: opening the method to REST is a separate task
					new ActionFilter\Scope(ActionFilter\Scope::NOT_REST),
				],
			],
		];
	}

	public function searchAction(
		CurrentUser $user,
		string $query,
		?string $sort = null,
		int $offset = 0,
		int $limit = MessageSearchQuery::DEFAULT_LIMIT,
	): ?array
	{
		// the availability of the caller and not of the portal: what the client was told the block
		// stands by is the very answer the method has to give back
		if (!Features::isMessageSearchAvailableForUser((int)$user->getId()))
		{
			$this->addError(new SearchError(SearchError::FEATURE_UNAVAILABLE));

			return null;
		}

		$queryResult = MessageSearchQuery::parse($query, $sort, $offset, $limit);
		if (!$queryResult->isSuccess())
		{
			$this->addErrors($queryResult->getErrors());

			return null;
		}

		$portion = $this->getSearchService()
			->withContextUser((int)$user->getId())
			->search($queryResult->getResult())
		;
		if (!$portion->isSuccess())
		{
			$this->addErrors($portion->getErrors());

			return null;
		}

		$rest = (new RestAdapter($portion->getItems()))->toRestFormat();
		$rest['hasNextPage'] = $portion->hasNextPage();
		$rest['nextOffset'] = $portion->getNextOffset();

		return $rest;
	}

	private function getSearchService(): MessageSearchService
	{
		return ServiceLocator::getInstance()->get(MessageSearchService::class);
	}
}
