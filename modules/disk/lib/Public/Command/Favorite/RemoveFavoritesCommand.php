<?php

declare(strict_types=1);

namespace Bitrix\Disk\Public\Command\Favorite;

use Bitrix\Disk\Internal\Service\Favorite\FavoriteToggleService;
use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Result;
use Bitrix\Main\Validation\ValidationResult;

/**
 * Removes the objects of the list from the favorites of the user. The own mark is removed whatever the access is;
 * the selection, the validation and the rows of the result are those of {@see AddFavoritesCommand}.
 */
class RemoveFavoritesCommand extends AbstractCommand
{
	/**
	 * @param int[] $objectIds ids of the rows of the list
	 */
	public function __construct(
		public readonly int $userId,
		public readonly array $objectIds,
	)
	{
	}

	protected function validate(): ValidationResult
	{
		return AddFavoritesCommand::validateObjectIds($this->objectIds);
	}

	protected function execute(): Result
	{
		return AddFavoritesCommand::runSteps(
			'remove',
			$this->userId,
			$this->objectIds,
			fn (FavoriteToggleService $service, int $objectId): FavoriteItemResult
				=> $service->remove($this->userId, $objectId),
		);
	}
}
