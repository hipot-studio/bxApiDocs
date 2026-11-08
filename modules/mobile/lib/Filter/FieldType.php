<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Filter;

/**
 * Controls the mobile filter draws a field with (CONTRACT-DTO-01). The values are the wire ones: the
 * client keeps the same vocabulary in its own enum (`layout/ui/field-filter/entry` FieldType), and a
 * name told differently by the two sides is a field the form cannot draw.
 *
 * Not the vocabulary of the web filter: that one names controls (`Main\UI\Filter\FieldAdapter` -
 * CHECKBOX, CUSTOM_ENTITY, DEST_SELECTOR), and several of its names come out as one control here -
 * a checkbox is a boolean, both selectors are a picker of ids. FieldDescriptorNormalizer::mapType()
 * is where the two vocabularies meet.
 */
enum FieldType: string
{
	case String = 'string';
	case Number = 'number';
	case Date = 'date';
	case ListItems = 'list';
	case Boolean = 'boolean';
	case EntitySelector = 'entity_selector';
	case Fulltext = 'fulltext';
}
