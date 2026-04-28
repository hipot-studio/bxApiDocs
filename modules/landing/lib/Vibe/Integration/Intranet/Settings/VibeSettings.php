<?php
declare(strict_types=1);

namespace Bitrix\Landing\Vibe\Integration\Intranet\Settings;

use Bitrix\Landing\Vibe;
use Bitrix\Intranet;
use Bitrix\Main\ErrorCollection;
use Bitrix\Main\Result;

class VibeSettings extends Intranet\Settings\AbstractSettings
{
	public const TYPE = 'welcome';

	public function validate(): ErrorCollection
	{
		$errors = new ErrorCollection();

		//todo: need Validate?

		return $errors;
	}

	public function save(): Result
	{
		return new Result();
	}

	public function get(): Intranet\Settings\SettingsInterface
	{
		$dataManager = new Vibe\Integration\Intranet\Settings\Manager();
		$this->data['vibes'] = $dataManager->getData();

		return $this;
	}

	public function find(string $query): array
	{
		// todo: do find
		$fields = [];

		$searchEngine = Intranet\Settings\Search\SearchEngine::initWithDefaultFormatter($fields);

		return $searchEngine->find($query);
	}
}