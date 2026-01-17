<?php
declare(strict_types=1);

namespace Bitrix\Im\V2\Application\Config;

use Bitrix\Im\V2\Entity\User\User;
use Bitrix\Im\V2\Entity\User\UserCollection;
use Bitrix\Im\V2\Integration\AI\Restriction;
use Bitrix\Im\V2\Rest\RestAdapter;
use Bitrix\Im\V2\Rest\RestConvertible;

class PreloadedEntities implements RestConvertible
{
	private bool $isLoaded = false;
	/**
	 * @var RestConvertible[]
	 */
	private array $entities = [];

	public static function getRestEntityName(): string
	{
		return 'preloadedEntities';
	}

	public function toRestFormat(array $option = []): ?array
	{
		$this->load();
		$option['WITHOUT_ONLINE'] = true;

		return (new RestAdapter(...$this->entities))->toRestFormat($option);
	}

	private function load(): void
	{
		if ($this->isLoaded)
		{
			return;
		}

		$this->entities[] = $this->getUsers();
		$this->entities[] = new LegacyCurrentUser();

		$this->isLoaded = true;
	}

	private function getUsers(): UserCollection
	{
		$userIds = [User::getCurrent()->getId()];
		$copilotId = $this->getCopilotBotId();
		if ($copilotId)
		{
			$userIds[] = $copilotId;
		}

		return new UserCollection($userIds);
	}

	private function getCopilotBotId(): int
	{
		if (!(new Restriction())->isCopilotActive())
		{
			return 0;
		}

		return \Bitrix\Im\V2\Integration\AI\AIHelper::getCopilotBotId();
	}
}
