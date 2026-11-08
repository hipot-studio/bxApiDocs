<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Activity;

use Bitrix\Bizproc\Activity\PropertiesDialog;
use Bitrix\Bizproc\FieldType;
use Bitrix\Main\Localization\Loc;
use Closure;
use Bitrix\Bizproc\Internal\Entity\Activity\ActivityControllerBuilder\ActivityControlDto;
use Bitrix\Bizproc\Internal\Integration\Tasks\DocumentFieldTypes\ProjectType;

class ActivityControlsBuilder
{
	protected Configurator $configurator;
	protected array $activity;
	private ActivityControlsProjectContext $projectContext;

	public function __construct(
		Configurator $configurator,
		array $activity,
		?ActivityControlsProjectContext $projectContext = null,
	)
	{
		$this->configurator = $configurator;
		$this->activity = $activity;
		$this->projectContext = $projectContext ?? new ActivityControlsProjectContext();
	}

	public static function hasProjectControls(array $propertiesMap): bool
	{
		foreach ($propertiesMap as $property)
		{
			if (!is_array($property))
			{
				continue;
			}

			if (self::isProjectControl($property))
			{
				return true;
			}
		}

		return false;
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
				'Name' => Loc::getMessage('BIZPROC_ACTIVITY_CONTROLS_BUILDER_TITLE_MSGVER_1') ?? '',
				'Type' => \Bitrix\Bizproc\FieldType::STRING,
				'Required' => true,
				'FieldName' => 'title',
			],
			$this->getCurrentValue('Title'),
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
			$this->activity['Name'] ?? null,
		);
	}

	private function buildCommentControl(): ActivityControlDto
	{
		return new ActivityControlDto(
			[
				'Name' => Loc::getMessage('BIZPROC_ACTIVITY_CONTROLS_BUILDER_COMMENT_MSGVER_1') ?? '',
				'Type' => \Bitrix\Bizproc\FieldType::STRING,
				'Required' => false,
				'Hidden' => true,
				'FieldName' => 'activity_editor_comment',
			],
			$this->getCurrentValue('EditorComment'),
		);
	}

	protected function buildPropertyControls(): array
	{
		$propertiesMap = $this->configurator->getPropertiesMap();

		$propertiesData = [];
		$projectValues = [];
		foreach ($propertiesMap as $key => $property)
		{
			// Skip service properties that belong to the complex-activity framework
			// (e.g. FieldType::RULES used by extractRulePropertyValue) — they must be
			// present in the properties map for the complex controller but must not
			// be rendered as UI controls in the base-settings block.
			if (($property['Type'] ?? '') === \Bitrix\Bizproc\FieldType::RULES)
			{
				continue;
			}

			$property['FieldName'] ??= $key;
			$value = $this->getCurrentValue($key, $property);

			// Getter/Setter are server-side Closures used to read/write legacy properties;
			// they must not leak into the serialized control JSON (a Closure encodes as {}).
			unset($property['Getter'], $property['Setter']);

			if (self::isProjectControl($property) && $this->isTrustedProjectPropertyValue($key, $value))
			{
				$projectValues[] = $value;
			}

			$propertiesData[] = [$key, $property, $value];
		}

		$this->projectContext->getProjectFieldStateResolver()->preload(
			$projectValues,
			$this->projectContext->userId,
			$this->projectContext->workflowConstants,
		);

		$properties = [];
		foreach ($propertiesData as [$key, $property, $value])
		{
			$properties[] = new ActivityControlDto($property, $value, $this->buildMeta($key, $property, $value));
		}

		return $properties;
	}

	private function buildMeta(string $propertyKey, array $property, mixed $value): array
	{
		if (!self::isProjectControl($property))
		{
			return [];
		}

		if (!$this->isTrustedProjectPropertyValue($propertyKey, $value))
		{
			return [];
		}

		$projectState = $this->projectContext->getProjectFieldStateResolver()->resolve(
			$value,
			$this->projectContext->userId,
			$this->projectContext->workflowConstants,
		);
		if ($projectState === null)
		{
			return [];
		}

		return ['projectState' => $projectState];
	}

	private static function isProjectControl(array $property): bool
	{
		if (($property['Type'] ?? null) === ProjectType::getType())
		{
			return true;
		}

		if (($property['Type'] ?? null) !== FieldType::ENTITYSELECTOR)
		{
			return false;
		}

		$settings = $property['Settings'] ?? [];
		if (($settings['entity']['id'] ?? null) === ProjectType::getType())
		{
			return true;
		}

		foreach (($settings['entities'] ?? []) as $entity)
		{
			if (($entity['id'] ?? null) === ProjectType::getType())
			{
				return true;
			}
		}

		return false;
	}

	private function isTrustedProjectPropertyValue(string $propertyKey, mixed $value): bool
	{
		if ($this->projectContext->trustedPropertyValues === null)
		{
			return true;
		}

		if (!array_key_exists($propertyKey, $this->projectContext->trustedPropertyValues))
		{
			return false;
		}

		$trustedValueKey = $this->projectContext
			->getProjectFieldStateResolver()
			->getProjectValueTrustKey($this->projectContext->trustedPropertyValues[$propertyKey])
		;
		if ($trustedValueKey === null)
		{
			return false;
		}

		return $this->projectContext->getProjectFieldStateResolver()->getProjectValueTrustKey($value) === $trustedValueKey;
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
