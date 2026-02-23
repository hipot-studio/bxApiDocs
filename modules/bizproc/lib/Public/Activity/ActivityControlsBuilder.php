<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Activity;

use Bitrix\Bizproc\Activity\PropertiesDialog;
use Bitrix\Main\Localization\Loc;
use Closure;
use Bitrix\Bizproc\Internal\Entity\Activity\ActivityControllerBuilder\ActivityControlDto;

class ActivityControlsBuilder
{
	protected Configurator $configurator;
	protected array $activity;

	public function __construct(Configurator $configurator, array $activity)
	{
		$this->configurator = $configurator;
		$this->activity = $activity;
	}

	/**
	 * @return ActivityControlDto[]
	 */
	public function build(): array
	{
		$defaultControls = $this->buildDefaultControls();
		$propertyControls = $this->buildPropertyControls();

		return array_merge($defaultControls, $propertyControls);
	}

	protected function buildDefaultControls(): array
	{
		return [
			$this->buildTitleControl(),
			$this->buildIdControl(),
			$this->buildCommentControl(),
		];
	}

	private function buildTitleControl(): ActivityControlDto
	{
		return new ActivityControlDto(
			[
				'Name' => Loc::getMessage('BIZPROC_ACTIVITY_CONTROLS_BUILDER_TITLE') ?? '',
				'Type' => \Bitrix\Bizproc\FieldType::STRING,
				'Required' => true,
				'FieldName' => 'title'
			],
			$this->getCurrentValue('Title')
		);
	}

	private function buildIdControl(): ActivityControlDto
	{
		return new ActivityControlDto(
			[
				'Name' => Loc::getMessage('BIZPROC_ACTIVITY_CONTROLS_BUILDER_ID') ?? '',
				'Type' => \Bitrix\Bizproc\FieldType::STRING,
				'Required' => true,
				'Hidden' => true,
				'FieldName' => 'activity_id',
			],
			$this->activity['Name'] ?? null
		);
	}

	private function buildCommentControl(): ActivityControlDto
	{
		return new ActivityControlDto(
			[
				'Name' => Loc::getMessage('BIZPROC_ACTIVITY_CONTROLS_BUILDER_COMMENT') ?? '',
				'Type' => \Bitrix\Bizproc\FieldType::STRING,
				'Required' => false,
				'Hidden' => true,
				'FieldName' => 'activity_editor_comment',
			],
			$this->getCurrentValue('EditorComment')
		);
	}

	protected function buildPropertyControls(): array
	{
		$propertiesMap = $this->configurator->getPropertiesMap();

		$properties = [];
		foreach ($propertiesMap as $key => $property)
		{
			$property['FieldName'] ??= $key;
			$properties[] = new ActivityControlDto(
				$property,
				$this->getCurrentValue($key, $property)
			);
		}

		return $properties;
	}

	protected function getCurrentValue(string $propertyKey, array $property = [])
	{
		$value = $this->activity['Properties'][$propertyKey] ?? ($property['Default'] ?? null);

		$getter = $property['Getter'] ?? null;
		if (is_callable($getter) && $getter instanceof Closure)
		{
			$dialog = new PropertiesDialog('', []);
			$value = $property['Getter']($dialog, $property, $this->activity, false);
		}

		return $value;
	}
}
