<?php

declare(strict_types=1);

namespace Bitrix\Disk\Controller;

use Bitrix\Disk\Internals\Engine\Controller;
use Bitrix\Disk\Public\Command\Favorite\AddFavoritesCommand;
use Bitrix\Disk\Public\Command\Favorite\RemoveFavoritesCommand;
use Bitrix\Disk\Public\Features\Favorites;
use Bitrix\Disk\Public\Service\Favorite\FavoriteOnboardingService;
use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Command\Exception\CommandValidationException;
use Bitrix\Main\Config\Feature;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\ActionFilter\Authentication;
use Bitrix\Main\Engine\ActionFilter\Csrf;
use Bitrix\Main\Engine\ActionFilter\HttpMethod;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;

/**
 * Personal favorites of Disk: disk.api.favorite.add, disk.api.favorite.remove and
 * disk.api.favorite.completeOnboarding. The work is done by the public commands and the onboarding service.
 */
final class Favorite extends Controller
{
	public const ERROR_FAVORITES_DISABLED = 'FAVORITES_DISABLED';

	public function configureActions(): array
	{
		$prefilters = [
			new Authentication(),
			new Csrf(),
			new HttpMethod([HttpMethod::METHOD_POST]),
		];

		return [
			'add' => ['prefilters' => $prefilters],
			'remove' => ['prefilters' => $prefilters],
			'completeOnboarding' => ['prefilters' => $prefilters],
		];
	}

	/**
	 * @param array $objectIds ids of the rows of the list
	 * @return array{items: list<array{objectId: int, status: string, isFavorite: bool|null}>}|null
	 */
	public function addAction(array $objectIds = []): ?array
	{
		if (!$this->checkEnabled())
		{
			return null;
		}

		return $this->runCommand(new AddFavoritesCommand($this->getUserId(), $objectIds));
	}

	/**
	 * @param array $objectIds ids of the rows of the list
	 * @return array{items: list<array{objectId: int, status: string, isFavorite: bool|null}>}|null
	 */
	public function removeAction(array $objectIds = []): ?array
	{
		if (!$this->checkEnabled())
		{
			return null;
		}

		return $this->runCommand(new RemoveFavoritesCommand($this->getUserId(), $objectIds));
	}

	public function completeOnboardingAction(): ?array
	{
		if (!$this->checkEnabled())
		{
			return null;
		}

		ServiceLocator::getInstance()->get(FavoriteOnboardingService::class)->complete($this->getUserId());

		return [];
	}

	private function runCommand(AbstractCommand $command): ?array
	{
		try
		{
			$result = $command->run();
		}
		catch (CommandValidationException $exception)
		{
			$this->addErrors(array_map($this->localizeValidationError(...), $exception->getValidationErrors()));

			return null;
		}

		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return ['items' => $result->getData()['items'] ?? []];
	}

	private function localizeValidationError(Error $error): Error
	{
		$phraseCode = match ($error->getCode())
		{
			AddFavoritesCommand::ERROR_TOO_MANY_OBJECTS => 'DISK_FAVORITE_CONTROLLER_ERROR_TOO_MANY',
			AddFavoritesCommand::ERROR_INVALID_OBJECT_IDS => 'DISK_FAVORITE_CONTROLLER_ERROR_INVALID_IDS',
			default => null,
		};
		if ($phraseCode === null)
		{
			return $error;
		}

		$message = Loc::getMessage($phraseCode, ['#LIMIT#' => AddFavoritesCommand::MAX_OBJECTS]);

		return new Error($message ?: $error->getMessage(), $error->getCode());
	}

	private function checkEnabled(): bool
	{
		if (Feature::isEnabled(Favorites::class))
		{
			return true;
		}

		$this->addError(new Error(
			(string)Loc::getMessage('DISK_FAVORITE_CONTROLLER_ERROR_DISABLED'),
			self::ERROR_FAVORITES_DISABLED,
		));

		return false;
	}

	private function getUserId(): int
	{
		return (int)$this->getCurrentUser()?->getId();
	}
}
