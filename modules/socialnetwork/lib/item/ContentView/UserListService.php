<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\Item\ContentView;

use Bitrix\Socialnetwork\Item\UserContentView;
use Closure;

class UserListService
{
	/** @var Closure(array): array */
	private readonly Closure $reader;

	public function __construct(
		private readonly ContentViewAccessService $accessService,
		?callable $reader = null,
	)
	{
		$this->reader = $reader !== null
			? Closure::fromCallable($reader)
			: static fn(array $params): array => UserContentView::getUserList($params)
		;
	}

	public function getUserList(
		string $contentId,
		int $actorId,
		int $page = 1,
		string $pathToUserProfile = '',
	): array
	{
		if (!$this->accessService->canRead($contentId, $actorId))
		{
			return $this->getEmptyResult();
		}

		$userList = ($this->reader)([
			'contentId' => $contentId,
			'page' => $page,
			'pathToUserProfile' => $pathToUserProfile,
		]);

		return [
			'items' => $userList['items'],
			'itemsCount' => count($userList['items']),
			'hiddenCount' => $userList['hiddenCount'],
		];
	}

	private function getEmptyResult(): array
	{
		return [
			'items' => [],
			'itemsCount' => 0,
			'hiddenCount' => 0,
		];
	}
}
