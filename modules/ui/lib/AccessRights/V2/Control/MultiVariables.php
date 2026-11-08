<?php

namespace Bitrix\UI\AccessRights\V2\Control;

use Bitrix\UI\AccessRights\V2\Contract\AccessRightsBuilder\Provider\Models\RightModel;
use Bitrix\UI\AccessRights\V2\Contract\AccessRightsBuilder\Provider\Structure\Configurator\RightItemConfigurator;
use Bitrix\UI\AccessRights\V2\Contract\AccessRightsBuilder\Provider\Structure\Control;
use Bitrix\UI\AccessRights\V2\Control\Trait\HasHintTitle;
use Bitrix\UI\AccessRights\V2\Control\Trait\HasSelectedVariablesAliases;
use Bitrix\UI\AccessRights\V2\Control\Trait\HasSharedOptions;
use Bitrix\UI\AccessRights\V2\Control\Value\MultiVariable;
use Bitrix\UI\AccessRights\V2\Control\Value\VariablesSource;
use Bitrix\UI\AccessRights\V2\Options\RightSection\RightItem;
use Bitrix\UI\AccessRights\V2\Options\RightSection\RightItem\Type;

class MultiVariables implements Control, RightItemConfigurator
{
	use HasSharedOptions;
	use HasSelectedVariablesAliases;
	use HasHintTitle;

	/** @var MultiVariable[] */
	protected array $variables = [];

	protected string|int|null $allSelectedCode = null;
	protected ?string $allSelectedTitle = null;
	protected ?VariablesSource $variablesSource = null;
	protected ?string $unloadedVariableTitlePattern = null;
	protected ?bool $enableSearch = null;
	protected ?bool $showAvatars = null;
	protected ?bool $compactView = null;

	public function getType(): Type
	{
		return Type::MultiVariables;
	}

	public function configureRightItem(RightItem $rightItem): void
	{
		foreach ($this->variables as $variable)
		{
			$rightItemVariable = (new RightItem\Variable($variable->value(), $variable->title()))
				->setEntityId($variable->entityId())
				->setSupertitle($variable->supertitle())
				->setAvatar($variable->avatar())
				->setAvatarOptions($variable->avatarOptions());

			$rightItem->addVariable($rightItemVariable);
		}

		$rightItem
			->setVariablesSource($this->variablesSource?->toArray())
			->setAllSelectedTitle($this->allSelectedTitle)
			->setUnloadedVariableTitlePattern($this->unloadedVariableTitlePattern);

		$this->configureRightItemBySharedOptions($rightItem);
		$this->configureRightItemBySelectedVariablesAliases($rightItem);
		$this->configureRightItemByHintTitle($rightItem);
	}

	/**
	 * @param MultiVariable[] $variables
	 * @return $this
	 */
	public function variables(array $variables): static
	{
		$this->variables = $variables;

		return $this;
	}

	public function allSelectedCode(string|int|null $code): static
	{
		$this->allSelectedCode = $code;

		return $this;
	}

	public function allSelectedTitle(?string $title): static
	{
		$this->allSelectedTitle = $title;

		return $this;
	}

	public function variablesSource(?VariablesSource $variablesSource): static
	{
		$this->variablesSource = $variablesSource;

		return $this;
	}

	/**
	 * @param ?string $pattern caption of a value the source does not name, with
	 *                {@see RightItem::UNLOADED_VARIABLE_VALUE_PLACEHOLDER} in place of the value
	 */
	public function unloadedVariableTitlePattern(?string $pattern): static
	{
		$this->unloadedVariableTitlePattern = $pattern;

		return $this;
	}

	public function enableSearch(?bool $enableSearch): static
	{
		$this->enableSearch = $enableSearch;

		return $this;
	}

	public function showAvatars(?bool $showAvatars): static
	{
		$this->showAvatars = $showAvatars;

		return $this;
	}

	public function compactView(?bool $compactView): static
	{
		$this->compactView = $compactView;

		return $this;
	}

	public function convertUIValueToModelValue(int|string $value): int|string
	{
		return $value;
	}

	public function convertModelValueToUIValue(RightModel $model): int|string
	{
		return $model->getValue();
	}
}
