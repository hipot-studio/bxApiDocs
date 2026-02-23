<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Entity\DocumentField;

use Bitrix\Bizproc\FieldType;
use Bitrix\Main\Loader;

class EntitySelectorConfigBuilder
{
	private FieldType $fieldType;
	private mixed $value;
	private ?array $settings = null;
	private ?array $options = null;

	public const OPTION_ENTITY_ID = 'option';
	private const KEY_VALUE_PAIR_SIZE = 2;

	public function __construct(FieldType $fieldType, mixed $value)
	{
		$this->fieldType = $fieldType;
		$this->value = $value;
	}

	public function setSettings(?array $settings = null): static
	{
		$this->settings = $settings;

		return $this;
	}

	public function setOptions(?array $options = null): static
	{
		$this->options = is_null($options) ? null : self::normalizeOptions($options);

		return $this;
	}

	public function build(): array
	{
		$config = [
			'multiple' => $this->fieldType->isMultiple(),
			'tagMaxWidth' => 400,
			'dialogOptions' => $this->buildDialogOptions(),
		];

		if ($this->value)
		{
			$config['items'] =
				$this->hasEntitySetting()
					? $this->getPreselectedItems()
					: $this->getPreselectedItemsFromOptions()
			;
		}

		$settings = $this->settings ?? [];
		unset($settings['dialogOptions']);
		$config = array_merge($config, $settings);

		return $config;
	}

	private function buildDialogOptions(): array
	{
		$dialogOptions = [
			'showAvatars' => false,
			'dropdownMode' => true,
			'compactView' => true,
			'height' => 240,
			'enableSearch' => $this->isEnableSearch(),
		];

		if ($this->hasEntitySetting())
		{
			$dialogOptions['entities'][] = $this->prepareEntity();
		}
		else
		{
			$dialogOptions['tabs'] = [['id' => self::OPTION_ENTITY_ID, 'title' => $this->fieldType->getName()]];
			$dialogOptions['items'] = $this->convertOptionsToItems();
		}

		$dialogOptions = array_merge($dialogOptions, $this->getDialogOptionsSetting() ?? []);

		return $dialogOptions;
	}

	private function isEnableSearch(): bool
	{
		return isset($this->settings['enableSearch']) && (bool)$this->settings['enableSearch'];
	}

	private function getDialogOptionsSetting(): ?array
	{
		return
			(isset($this->settings['dialogOptions']) && is_array($this->settings['dialogOptions']))
				? $this->settings['dialogOptions']
				: null
		;
	}

	private function hasEntitySetting(): bool
	{
		return isset($this->settings['entity']) && is_array($this->settings['entity']);
	}

	public function prepareEntity(): array
	{
		$entity = $this->settings['entity'] ?? [];

		if (empty($entity))
		{
			return $entity;
		}

		$booleanFields = [
			'dynamicLoad',
			'dynamicSearch',
			'searchable',
			'showLink',
		];

		return $this->convertFieldsToBoolean($entity, $booleanFields);
	}

	private function convertFieldsToBoolean(array $entity, array $fields): array
	{
		foreach ($fields as $field)
		{
			if (isset($entity[$field]))
			{
				$entity[$field] = \CBPHelper::getBool($entity[$field]);
			}
		}

		return $entity;
	}

	public function getPreselectedItems(): array
	{
		Loader::requireModule('ui');

		$value = (array)$this->value;
		$entityId = $this->settings['entity']['id'] ?? '';

		if (!class_exists(\Bitrix\UI\EntitySelector\Dialog::class) || !$entityId)
		{
			return [];
		}

		$preselectedItems = array_map(
			static fn($item) => [$entityId, $item],
			$value
		);

		$options = [];

		return \Bitrix\UI\EntitySelector\Dialog::getPreselectedItems($preselectedItems, $options)->toArray();
	}

	private function convertOptionsToItems(): array
	{
		$options = $this->options ?? [];

		if (empty($options))
		{
			return [];
		}

		$items = [];
		foreach ($options as $id => $title)
		{
			$items[] = [
				'id' => $id,
				'entityId' => self::OPTION_ENTITY_ID,
				'title' => (string)$title,
				'tabs' => self::OPTION_ENTITY_ID,
			];
		}

		return $items;
	}

	private function getPreselectedItemsFromOptions(): array
	{
		$options = $this->options;
		if (!$options)
		{
			return [];
		}

		$value = (array)$this->value;
		$items = [];

		foreach ($value as $selectedId)
		{
			if (isset($options[$selectedId]))
			{
				$items[] = [
					'id' => $selectedId,
					'entityId' => self::OPTION_ENTITY_ID,
					'title' => (string)$options[$selectedId],
				];
			}
		}

		return $items;
	}

	/**
	 * @param mixed $options
	 * @return array
	 */
	protected static function normalizeOptions(mixed $options): array
	{
		$normalized = [];
		if (is_array($options))
		{
			foreach ($options as $key => $value)
			{
				if (is_array($value) && count($value) === self::KEY_VALUE_PAIR_SIZE)
				{
					$v = array_values($value);
					[$key, $value] = $v;
				}
				$normalized[$key] = $value;
			}
		}
		elseif ($options !== '')
		{
			$normalized[$options] = $options;
		}

		return $normalized;
	}

	public function buildSelectedItemFromOptions(): ?array
	{
		if (is_scalar($this->value) && isset($this->options[$this->value]))
		{
			return [
				'id' => $this->value,
				'entityId' => self::OPTION_ENTITY_ID,
				'title' => $this->options[$this->value]
			];
		}

		return null;
	}
}
