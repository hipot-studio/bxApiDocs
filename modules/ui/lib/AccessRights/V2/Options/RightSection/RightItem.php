<?php

namespace Bitrix\UI\AccessRights\V2\Options\RightSection;

use Bitrix\Main\Type\Contract\Arrayable;
use Bitrix\UI\AccessRights\V2\Options\RightSection\RightItem\Preset;
use Bitrix\UI\AccessRights\V2\Options\RightSection\RightItem\Type;
use Bitrix\UI\AccessRights\V2\Options\RightSection\RightItem\Variable;
use JsonSerializable;

class RightItem implements JsonSerializable, Arrayable
{
	/** Stands for the value itself in {@see self::$unloadedVariableTitlePattern}. */
	public const UNLOADED_VARIABLE_VALUE_PLACEHOLDER = '#VALUE#';

	protected ?string $subtitle = null;
	protected ?string $hint = null;
	protected ?bool $isGroupHead = null;
	protected ?string $group = null;

	protected array|string|null $minValue = null;
	protected array|string|null $maxValue = null;
	protected array|string|null $defaultValue = null;
	protected array|string|null $emptyValue = null;
	protected array|string|null $nothingSelectedValue = null;
	protected ?bool $isEmptyOnSetMinMaxValueInColumn = null;
	protected ?array $selectedVariablesAliases = null;

	protected string|int|null $allSelectedCode = null;
	protected ?string $allSelectedTitle = null;
	protected ?array $variablesSource = null;
	protected ?string $unloadedVariableTitlePattern = null;
	protected ?bool $isEnableSearch = null;
	protected ?bool $isShowAvatars = null;
	protected ?bool $isCompactView = null;
	protected ?string $hintTitle = null;
	protected ?string $dependentVariablesPopupHint = null;

	protected ?string $iconClass = null;
	protected ?bool $isClickable = null;
	protected ?bool $isDeletable = null;
	protected ?bool $isNew = null;
	protected ?bool $isModified = null;

	/** @var Variable[] */
	protected array $variables = [];

	/** @var Preset[] */
	protected array $presets = [];

	public function __construct(
		protected string $id,
		protected string $title,
		protected Type $type,
	)
	{
	}

	public function getId(): int|string
	{
		return $this->id;
	}

	public function setId(int|string $id): RightItem
	{
		$this->id = $id;

		return $this;
	}

	public function getType(): Type
	{
		return $this->type;
	}

	public function setType(Type $type): static
	{
		$this->type = $type;

		return $this;
	}

	public function getTitle(): string
	{
		return $this->title;
	}

	public function setTitle(string $title): static
	{
		$this->title = $title;

		return $this;
	}

	public function getSubtitle(): ?string
	{
		return $this->subtitle;
	}

	public function setSubtitle(?string $subtitle): static
	{
		$this->subtitle = $subtitle;

		return $this;
	}

	public function getHint(): ?string
	{
		return $this->hint;
	}

	public function setHint(?string $hint): static
	{
		$this->hint = $hint;

		return $this;
	}

	public function isGroupHead(): ?bool
	{
		return $this->isGroupHead;
	}

	public function setIsGroupHead(?bool $isGroupHead): static
	{
		$this->isGroupHead = $isGroupHead;

		return $this;
	}

	public function getGroup(): ?string
	{
		return $this->group;
	}

	public function setGroup(?string $group): static
	{
		$this->group = $group;

		return $this;
	}

	public function getMinValue(): array|string|null
	{
		return $this->minValue;
	}

	public function setMinValue(array|string|null $minValue): static
	{
		$this->minValue = $minValue;

		return $this;
	}

	public function getMaxValue(): array|string|null
	{
		return $this->maxValue;
	}

	public function setMaxValue(array|string|null $maxValue): static
	{
		$this->maxValue = $maxValue;

		return $this;
	}

	public function getDefaultValue(): array|string|null
	{
		return $this->defaultValue;
	}

	public function setDefaultValue(array|string|null $defaultValue): static
	{
		$this->defaultValue = $defaultValue;

		return $this;
	}

	public function getEmptyValue(): array|string|null
	{
		return $this->emptyValue;
	}

	public function setEmptyValue(array|string|null $emptyValue): static
	{
		$this->emptyValue = $emptyValue;

		return $this;
	}

	public function getNothingSelectedValue(): array|string|null
	{
		return $this->nothingSelectedValue;
	}

	public function setNothingSelectedValue(array|string|null $nothingSelectedValue): static
	{
		$this->nothingSelectedValue = $nothingSelectedValue;

		return $this;
	}

	public function isSetEmptyOnSetMinMaxValueInColumn(): ?bool
	{
		return $this->isEmptyOnSetMinMaxValueInColumn;
	}

	public function setIsEmptyOnSetMinMaxValueInColumn(?bool $isEmptyOnSetMinMaxValueInColumn): static
	{
		$this->isEmptyOnSetMinMaxValueInColumn = $isEmptyOnSetMinMaxValueInColumn;

		return $this;
	}

	public function getSelectedVariablesAliases(): ?array
	{
		return $this->selectedVariablesAliases;
	}

	public function setSelectedVariablesAliases(?array $selectedVariablesAliases): static
	{
		$this->selectedVariablesAliases = $selectedVariablesAliases;

		return $this;
	}

	public function getAllSelectedCode(): int|string|null
	{
		return $this->allSelectedCode;
	}

	public function setAllSelectedCode(int|string|null $allSelectedCode): static
	{
		$this->allSelectedCode = $allSelectedCode;

		return $this;
	}

	public function getAllSelectedTitle(): ?string
	{
		return $this->allSelectedTitle;
	}

	public function setAllSelectedTitle(?string $allSelectedTitle): static
	{
		$this->allSelectedTitle = $allSelectedTitle;

		return $this;
	}

	public function getVariablesSource(): ?array
	{
		return $this->variablesSource;
	}

	public function setVariablesSource(?array $variablesSource): static
	{
		$this->variablesSource = $variablesSource;

		return $this;
	}

	public function getUnloadedVariableTitlePattern(): ?string
	{
		return $this->unloadedVariableTitlePattern;
	}

	/**
	 * Caption a value of a source-fed right gets when neither the source nor the variables of the right name it:
	 * {@see self::UNLOADED_VARIABLE_VALUE_PLACEHOLDER} stands where the value goes.
	 */
	public function setUnloadedVariableTitlePattern(?string $unloadedVariableTitlePattern): static
	{
		$this->unloadedVariableTitlePattern = $unloadedVariableTitlePattern;

		return $this;
	}

	public function isEnableSearch(): ?bool
	{
		return $this->isEnableSearch;
	}

	public function setIsEnableSearch(?bool $isEnableSearch): static
	{
		$this->isEnableSearch = $isEnableSearch;

		return $this;
	}

	public function isShowAvatars(): ?bool
	{
		return $this->isShowAvatars;
	}

	public function setIsShowAvatars(?bool $isShowAvatars): static
	{
		$this->isShowAvatars = $isShowAvatars;

		return $this;
	}

	public function isCompactView(): ?bool
	{
		return $this->isCompactView;
	}

	public function setIsCompactView(?bool $isCompactView): static
	{
		$this->isCompactView = $isCompactView;

		return $this;
	}

	public function getHintTitle(): ?string
	{
		return $this->hintTitle;
	}

	public function setHintTitle(?string $hintTitle): static
	{
		$this->hintTitle = $hintTitle;

		return $this;
	}

	public function getVariables(): array
	{
		return $this->variables;
	}

	public function setVariables(array $variables): static
	{
		$this->variables = $variables;

		return $this;
	}

	public function addVariable(Variable $variable): static
	{
		$this->variables[] = $variable;

		return $this;
	}

	public function getPresets(): array
	{
		return $this->presets;
	}

	public function setPresets(array $presets): static
	{
		$this->presets = $presets;

		return $this;
	}

	public function addPreset(Preset $preset): static
	{
		$this->presets[] = $preset;

		return $this;
	}

	public function getDependentVariablesPopupHint(): ?string
	{
		return $this->dependentVariablesPopupHint;
	}

	public function setDependentVariablesPopupHint(?string $dependentVariablesPopupHint): static
	{
		$this->dependentVariablesPopupHint = $dependentVariablesPopupHint;

		return $this;
	}

	public function getIconClass(): ?string
	{
		return $this->iconClass;
	}

	public function setIconClass(?string $iconClass): static
	{
		$this->iconClass = $iconClass;

		return $this;
	}

	public function isClickable(): ?bool
	{
		return $this->isClickable;
	}

	public function setIsClickable(?bool $isClickable): static
	{
		$this->isClickable = $isClickable;

		return $this;
	}

	public function isDeletable(): ?bool
	{
		return $this->isDeletable;
	}

	public function setIsDeletable(?bool $isDeletable): static
	{
		$this->isDeletable = $isDeletable;

		return $this;
	}

	public function isNew(): ?bool
	{
		return $this->isNew;
	}

	public function setIsNew(?bool $isNew): static
	{
		$this->isNew = $isNew;

		return $this;
	}

	public function isModified(): ?bool
	{
		return $this->isModified;
	}

	public function setIsModified(?bool $isModified): static
	{
		$this->isModified = $isModified;

		return $this;
	}

	public function toArray(): array
	{
		return [
			'id' => $this->getId(),
			'type' => $this->getType()->value,
			'title' => $this->getTitle(),
			'subtitle' => $this->getSubtitle(),
			'hint' => $this->getHint(),
			'group' => $this->getGroup(),
			'groupHead' => $this->isGroupHead(),
			'minValue' => $this->getMinValue(),
			'maxValue' => $this->getMaxValue(),
			'defaultValue' => $this->getDefaultValue(),
			'emptyValue' => $this->getEmptyValue(),
			'nothingSelectedValue' => $this->getNothingSelectedValue(),
			'setEmptyOnSetMinMaxValueInColumn' => $this->isSetEmptyOnSetMinMaxValueInColumn(),

			'variables' => array_map(static fn (Variable $variable) => $variable->toArray(), $this->getVariables()),
			'presets' => array_map(static fn (Preset $preset) => $preset->toArray(), $this->getPresets()),
			'variablesSource' => $this->prepareVariablesSourceForOutput(),
			'unloadedVariableTitlePattern' => $this->getUnloadedVariableTitlePattern(),

			'allSelectedCode' => $this->getAllSelectedCode(),
			'allSelectedTitle' => $this->getAllSelectedTitle(),
			'selectedVariablesAliases' => $this->getSelectedVariablesAliases(),
			'enableSearch' => $this->isEnableSearch(),
			'showAvatars' => $this->isShowAvatars(),
			'compactView' => $this->isCompactView(),
			'hintTitle' => $this->getHintTitle(),

			'dependentVariablesPopupHint' => $this->getDependentVariablesPopupHint(),

			'iconClass' => $this->getIconClass(),
			'isClickable' => $this->isClickable(),
			'isDeletable' => $this->isDeletable(),
			'isNew' => $this->isNew(),
			'isModified' => $this->isModified(),
		];
	}

	public function jsonSerialize(): array
	{
		return $this->toArray();
	}

	private function prepareVariablesSourceForOutput(): ?array
	{
		$source = self::tryVariablesSourceFromArray($this->variablesSource);
		if ($source === null)
		{
			return null;
		}

		if (array_key_exists('providerOptions', $source) && !$source['providerOptions'])
		{
			// the client normalizes provider options as a plain object, so an empty map must stay `{}` in JSON
			$source['providerOptions'] = new \stdClass();
		}

		return $source;
	}

	public static function tryFromArray(array $data): ?self
	{
		if (!isset($data['id']))
		{
			return null;
		}

		if (!isset($data['title']) || !is_string($data['title']))
		{
			return null;
		}

		if (!isset($data['type']) || !is_string($data['type']))
		{
			return null;
		}

		$type = Type::tryFrom($data['type']);
		if ($type === null)
		{
			return null;
		}

		$item = new self(
			$data['id'],
			$data['title'],
			$type,
		);

		if (isset($data['subtitle']))
		{
			$item->setSubtitle((string)$data['subtitle']);
		}

		if (isset($data['hint']))
		{
			$item->setHint((string)$data['hint']);
		}

		if (isset($data['groupHead']))
		{
			$item->setIsGroupHead((bool)$data['groupHead']);
		}

		if (isset($data['group']))
		{
			$item->setGroup((string)$data['group']);
		}

		if (isset($data['minValue']))
		{
			$item->setMinValue($data['minValue']);
		}

		if (isset($data['maxValue']))
		{
			$item->setMaxValue($data['maxValue']);
		}

		if (isset($data['defaultValue']))
		{
			$item->setDefaultValue($data['defaultValue']);
		}

		if (isset($data['emptyValue']))
		{
			$item->setEmptyValue($data['emptyValue']);
		}

		if (isset($data['nothingSelectedValue']))
		{
			$item->setNothingSelectedValue($data['nothingSelectedValue']);
		}

		if (isset($data['setEmptyOnSetMinMaxValueInColumn']))
		{
			$item->setIsEmptyOnSetMinMaxValueInColumn((bool)$data['setEmptyOnSetMinMaxValueInColumn']);
		}

		if (isset($data['selectedVariablesAliases']) && is_array($data['selectedVariablesAliases']))
		{
			$item->setSelectedVariablesAliases($data['selectedVariablesAliases']);
		}

		if (isset($data['allSelectedCode']))
		{
			$item->setAllSelectedCode($data['allSelectedCode']);
		}

		if (isset($data['allSelectedTitle']))
		{
			$item->setAllSelectedTitle((string)$data['allSelectedTitle']);
		}

		if (isset($data['variablesSource']))
		{
			$item->setVariablesSource(self::tryVariablesSourceFromArray($data['variablesSource']));
		}

		$unloadedVariableTitlePattern = $data['unloadedVariableTitlePattern'] ?? null;
		if (is_string($unloadedVariableTitlePattern) && $unloadedVariableTitlePattern !== '')
		{
			$item->setUnloadedVariableTitlePattern($unloadedVariableTitlePattern);
		}

		if (isset($data['enableSearch']))
		{
			$item->setIsEnableSearch((bool)$data['enableSearch']);
		}

		if (isset($data['showAvatars']))
		{
			$item->setIsShowAvatars((bool)$data['showAvatars']);
		}

		if (isset($data['compactView']))
		{
			$item->setIsCompactView((bool)$data['compactView']);
		}

		if (isset($data['hintTitle']))
		{
			$item->setHintTitle((string)$data['hintTitle']);
		}

		if (isset($data['dependentVariablesPopupHint']))
		{
			$item->setDependentVariablesPopupHint((string)$data['dependentVariablesPopupHint']);
		}

		if (isset($data['iconClass']))
		{
			$item->setIconClass((string)$data['iconClass']);
		}

		if (isset($data['isClickable']))
		{
			$item->setIsClickable((bool)$data['isClickable']);
		}

		if (isset($data['isDeletable']))
		{
			$item->setIsDeletable((bool)$data['isDeletable']);
		}

		if (isset($data['isNew']))
		{
			$item->setIsNew((bool)$data['isNew']);
		}

		if (isset($data['isModified']))
		{
			$item->setIsModified((bool)$data['isModified']);
		}

		if (isset($data['variables']) && is_array($data['variables']))
		{
			$variables = [];
			foreach ($data['variables'] as $variableData)
			{
				if (!is_array($variableData))
				{
					continue;
				}
				$variable = Variable::tryFromArray($variableData);
				if ($variable === null)
				{
					continue;
				}
				$variables[] = $variable;
			}
			$item->setVariables($variables);
		}

		if (isset($data['presets']) && is_array($data['presets']))
		{
			$presets = [];
			foreach ($data['presets'] as $presetData)
			{
				if (!is_array($presetData))
				{
					continue;
				}
				$preset = Preset::tryFromArray($presetData);
				if ($preset === null)
				{
					continue;
				}
				$presets[] = $preset;
			}
			$item->setPresets($presets);
		}

		return $item;
	}

	private static function tryVariablesSourceFromArray(mixed $source): ?array
	{
		if (!is_array($source))
		{
			return null;
		}

		$entityId = $source['entityId'] ?? null;
		if (!is_string($entityId) || $entityId === '')
		{
			return null;
		}

		$result = [
			'entityId' => $entityId,
		];

		if (array_key_exists('dynamicLoad', $source))
		{
			$result['dynamicLoad'] = (bool)$source['dynamicLoad'];
		}

		if (array_key_exists('dynamicSearch', $source))
		{
			$result['dynamicSearch'] = (bool)$source['dynamicSearch'];
		}

		if (array_key_exists('dynamicSearchMatchMode', $source))
		{
			$matchMode = $source['dynamicSearchMatchMode'];
			$result['dynamicSearchMatchMode'] = is_scalar($matchMode) ? (string)$matchMode : null;
		}

		$providerOptions = $source['providerOptions'] ?? null;
		if (is_array($providerOptions) || is_object($providerOptions))
		{
			$result['providerOptions'] = (array)$providerOptions;
		}

		if (array_key_exists('fillRecentItems', $source))
		{
			$result['fillRecentItems'] = (bool)$source['fillRecentItems'];
		}

		$itemEntityId = $source['itemEntityId'] ?? null;
		if (is_string($itemEntityId) && $itemEntityId !== '')
		{
			$result['itemEntityId'] = $itemEntityId;
		}

		return $result;
	}
}
