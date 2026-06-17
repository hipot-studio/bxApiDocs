<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Repository\Template\List\QueryBuilder;

use Bitrix\Tasks\V2\Internal\Repository\Template\List\Field;

class ModifierFactory
{
	public function createSelectModifier(Field $field): QueryModifierInterface
	{
		$builderClass = match ($field)
		{
			Field::TemplateChildrenCount => TemplateChildrenCountSelectModifier::class,
			Field::BaseTemplateId => BaseTemplateIdSelectModifier::class,
			Field::CreatedBy => CreatedBySelectModifier::class,
			Field::ResponsibleId => ResponsibleIdSelectModifier::class,
			default => BaseSelectModifier::class,
		};

		return new $builderClass($field);
	}

	public function createFilterModifier(Field $field, string $operator, mixed $value): QueryModifierInterface
	{
		$builderClass = match ($field)
		{
			Field::TemplateChildrenCount => TemplateChildrenCountFilterModifier::class,
			Field::BaseTemplateId => BaseTemplateIdFilterModifier::class,
			Field::TagList => TagListFilterModifier::class,
			Field::SearchIndex => SearchIndexFilterModifier::class,
			Field::Zombie => ZombieFilterModifier::class,
			Field::AccessUserId => AccessFilterModifier::class,
			default => BaseFilterModifier::class,
		};

		return new $builderClass($field, $operator, $value);
	}

	public function createOrderModifier(Field $field, string $direction): QueryModifierInterface
	{
		$builderClass = match ($field)
		{
			Field::TemplateChildrenCount => TemplateChildrenCountOrderModifier::class,
			Field::BaseTemplateId => BaseTemplateIdOrderModifier::class,
			default => BaseOrderModifier::class,
		};

		return new $builderClass($field, $direction);
	}
}