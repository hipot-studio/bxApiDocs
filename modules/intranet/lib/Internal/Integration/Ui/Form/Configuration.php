<?php

declare(strict_types=1);

namespace Bitrix\Intranet\Internal\Integration\Ui\Form;

use Bitrix\Main\Loader;
use Bitrix\UI\Form\EntityEditorConfigScope;
use Bitrix\UI\Form\EntityEditorConfiguration;

class Configuration
{
	public const USER_PROFILE_CONFIG_ID = 'intranet-user-profile';

	public function __construct(
		private readonly EntityEditorConfiguration $configuration,
	)
	{
	}

	public static function createByDefault(): self
	{
		return new self(new EntityEditorConfiguration());
	}

	/**
	 * Returns visible sorted field names from user profile form
	 */
	public function getUserProfileFieldNames(): ?array
	{
		$configuration = $this->getUserProfileConfiguration();
		$fields = $configuration[0]['elements'][0]['elements'] ?? null;

		if (!is_array($fields))
		{
			return null;
		}

		$result = [];

		foreach ($fields as $field)
		{
			if (isset($field['name']) && is_string($field['name']))
			{
				$result[] = $this->mapFieldName($field['name']);
			}
		}

		return $result;
	}

	protected function getUserProfileConfiguration(): array
	{
		if (!Loader::includeModule('ui'))
		{
			return [];
		}

		$result = $this->configuration->get(
			static::USER_PROFILE_CONFIG_ID,
			EntityEditorConfigScope::COMMON,
		);

		return is_array($result) ? $result : [];
	}

	private function mapFieldName(string $name): string
	{
		return $name === 'UF_DEPARTMENT' ? 'DEPARTMENT' : $name;
	}
}
