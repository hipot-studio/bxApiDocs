<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\BaseType;

use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Internal\Service\Activity\NodeCandidateCollector;
use Bitrix\Main\Localization\Loc;

/**
 * Reference to another node of the same workflow. The stored value is the name of the node, so the type
 * serves node activities only and is not a type of a global variable or constant.
 *
 * Candidates arrive in `Settings`, filled while the properties map is assembled
 * ({@see \Bitrix\Bizproc\Public\Activity\Field\NodeReferenceDeclaration}). On the run there is no such
 * map, so nothing but the value itself is known.
 */
class NodeReference extends Base
{
	private const CONTROL_WRAPPER_CLASS = 'bizproc-type-control-nodereference-wrapper';
	private const STATE_CLASS = 'bizproc-type-control-nodereference-state';
	private const PRINTABLE_CLASS = 'bizproc-type-control-nodereference-printable';

	public static function getType(): string
	{
		return FieldType::NODEREFERENCE;
	}

	public static function getName(): string
	{
		return Loc::getMessage('BIZPROC_BASE_TYPE_NODE_REFERENCE_NAME') ?? '';
	}

	/** The value is a bare node name, so the model is taught no shape beyond a string. */
	public static function getAiSettingType(): string
	{
		return FieldType::STRING;
	}

	/**
	 * How a link the scheme no longer holds is named in a report about the template: the wording belongs to
	 * the type, so a report says the node is gone instead of showing a `{=...}` reference the user never wrote.
	 *
	 * Only a reader of the whole template may say that - {@see renderControl} judges by a slice and says less.
	 */
	public static function formatBrokenLink(string $nodeName): string
	{
		$message = (string)Loc::getMessage('BPDT_NODEREFERENCE_BROKEN_LINK', ['#NAME#' => $nodeName]);

		return $message === '' ? $nodeName : $message;
	}

	/**
	 * Caption of a value the candidates do not hold: named as a link so a machine name never passes for a
	 * title of a node, and saying nothing of the node being gone - the candidates come from a slice of the
	 * scheme, and a node outside the slice is unavailable without being deleted. Which of the two it is, the
	 * state element says.
	 */
	private static function formatLinkLabel(string $nodeName): string
	{
		$message = (string)Loc::getMessage('BPDT_NODEREFERENCE_ITEM_LINK', ['#NAME#' => $nodeName]);

		return $message === '' ? $nodeName : $message;
	}

	/**
	 * A surface the scheme never reached prints the link instead of offering a choice: it must not drop the
	 * value either. A slice that did arrive holding no node of the kind still offers the list - empty, with
	 * the state saying why - because there is a choice to make here and nothing to make it from. Neither
	 * branch offers an expression control: a link names a node of the scheme, and an expression names none.
	 */
	protected static function renderControl(FieldType $fieldType, array $field, $value, $allowSelection, $renderMode)
	{
		$storedValue = static::normalizeValue($value);

		return static::isSchemeKnown($fieldType)
			? static::renderChoiceControl($fieldType, $field, $storedValue)
			: static::renderPrintableControl($fieldType, $field, $storedValue)
		;
	}

	/**
	 * The state is an element of its own rather than a part of an item: it describes the field, while an
	 * item describes a node.
	 */
	private static function renderChoiceControl(FieldType $fieldType, array $field, string $storedValue): string
	{
		$candidates = static::getCandidates($fieldType);
		$options = '';

		// A link the candidates no longer hold keeps its place at the top of the list, chosen: the state
		// element says what is wrong with it, and the link itself is never dropped. It is named as a link
		// rather than captioned, so a machine name never passes for a title of a node.
		if ($storedValue !== '' && NodeCandidateCollector::findCandidate($candidates, $storedValue) === null)
		{
			$options .= static::renderOption(
				$storedValue,
				static::formatLinkLabel(static::getSelectedTitle($fieldType, $storedValue)),
				true,
			);
		}

		$options .= static::renderOption(
			'',
			(string)Loc::getMessage('BPDT_NODEREFERENCE_CHOOSE'),
			$storedValue === '',
		);

		foreach ($candidates as $candidate)
		{
			if (!is_array($candidate))
			{
				continue;
			}

			$name = (string)($candidate['Name'] ?? '');
			$options .= static::renderOption($name, static::formatCandidateLabel($candidate), $name === $storedValue);
		}

		$linkState = static::resolveLinkState($fieldType, $storedValue);
		$stateLabel = static::formatLinkStateLabel($linkState);
		$stateId = $stateLabel === '' ? '' : static::generateStateId($field);

		return sprintf(
			'<div class="%s">'
			. '<select id="%s" class="%s" name="%s" aria-label="%s"%s data-testid="%s">%s</select>'
			. '%s'
			. '</div>',
			self::CONTROL_WRAPPER_CLASS,
			htmlspecialcharsbx(static::generateControlId($field)),
			htmlspecialcharsbx(static::generateControlClassName($fieldType, $field)),
			htmlspecialcharsbx(static::generateControlName($field)),
			htmlspecialcharsbx((string)$fieldType->getName()),
			static::renderControlStateAttributes($fieldType, $linkState, $stateId),
			htmlspecialcharsbx(static::generateControlTestId($field, 'bizproc-field-control')),
			$options,
			static::renderLinkState($field, $stateLabel, $stateId),
		);
	}

	/**
	 * A link the save is going to refuse is marked invalid on the control itself: a description of the
	 * state says nothing to the form about the value being wrong.
	 */
	private static function renderControlStateAttributes(FieldType $fieldType, string $linkState, string $stateId): string
	{
		$attributes = $fieldType->isRequired() ? ' aria-required="true"' : '';

		if ($stateId !== '')
		{
			$attributes .= sprintf(' aria-describedby="%s"', htmlspecialcharsbx($stateId));
		}

		if ($linkState === NodeCandidateCollector::LINK_STATE_SOURCE_UNAVAILABLE)
		{
			$attributes .= ' aria-invalid="true"';
		}

		return $attributes;
	}

	private static function renderPrintableControl(FieldType $fieldType, array $field, string $storedValue): string
	{
		// The branch is taken where the scheme is unknown, so there is no node to look the caption up in:
		// the one the declaration saved along with the link is the whole of what is left to show.
		$printable = $storedValue === ''
			? (string)Loc::getMessage('BPDT_NODEREFERENCE_NOT_SET')
			: static::getSelectedTitle($fieldType, $storedValue)
		;

		$stateLabel = static::formatLinkStateLabel(static::resolveLinkState($fieldType, $storedValue));
		if ($stateLabel !== '')
		{
			$printable = (string)Loc::getMessage(
				'BPDT_NODEREFERENCE_PRINTABLE_WITH_STATE',
				['#VALUE#' => $printable, '#STATE#' => $stateLabel],
			);
		}

		// No class of the select family here: that family draws a box with an arrow, and static text in it
		// reads as a list that refuses to open.
		return sprintf(
			'<span class="%s">%s</span><input type="hidden" name="%s" value="%s"/>',
			self::PRINTABLE_CLASS,
			htmlspecialcharsbx($printable),
			htmlspecialcharsbx(static::generateControlName($field)),
			htmlspecialcharsbx($storedValue)
		);
	}

	/**
	 * The line is printed whether or not there is anything to say - CSS hides an empty one - while the
	 * identifier the control points at it by is printed only along with words.
	 */
	private static function renderLinkState(array $field, string $stateLabel, string $stateId): string
	{
		return sprintf(
			'<div%s class="%s" data-testid="%s">%s</div>',
			$stateId === '' ? '' : sprintf(' id="%s"', htmlspecialcharsbx($stateId)),
			self::STATE_CLASS,
			htmlspecialcharsbx(static::generateControlTestId($field, 'bizproc-field-nodereference-state')),
			htmlspecialcharsbx($stateLabel),
		);
	}

	private static function renderOption(string $value, string $label, bool $isSelected): string
	{
		return sprintf(
			'<option value="%s"%s>%s</option>',
			htmlspecialcharsbx($value),
			$isSelected ? ' selected' : '',
			htmlspecialcharsbx($label),
		);
	}

	/**
	 * `Title` is already resolved by the collector: a node with no title of its own carries the name of
	 * its kind, and `TitleIsFallback` says so.
	 *
	 * Each of the four cases is one whole phrase rather than a frame filled with fragments: where a node
	 * sits and the note about a missing title do not survive translation apart from the sentence they sit
	 * in - word order and case belong to the translator.
	 */
	private static function formatCandidateLabel(array $candidate): string
	{
		$parentTitle = $candidate['ParentTitle'] ?? null;
		$parentTitle = is_scalar($parentTitle) ? (string)$parentTitle : null;

		$title = $candidate['Title'] ?? null;
		$replacements = ['#TITLE#' => is_scalar($title) ? (string)$title : ''];
		$isUntitled = !empty($candidate['TitleIsFallback']);

		if ($parentTitle === null)
		{
			$code = $isUntitled
				? 'BPDT_NODEREFERENCE_ITEM_CANVAS_UNTITLED'
				: 'BPDT_NODEREFERENCE_ITEM_CANVAS'
			;
		}
		else
		{
			$code = $isUntitled
				? 'BPDT_NODEREFERENCE_ITEM_INSIDE_UNTITLED'
				: 'BPDT_NODEREFERENCE_ITEM_INSIDE'
			;
			$replacements['#PARENT#'] = $parentTitle;
		}

		return (string)Loc::getMessage($code, $replacements);
	}

	/** Caption of a value the candidates do not hold: the one saved with it, or the name of the node. */
	private static function getSelectedTitle(FieldType $fieldType, string $storedValue): string
	{
		$settings = $fieldType->getSettings();
		$title = is_array($settings) ? ($settings['SelectedTitle'] ?? null) : null;
		$title = is_scalar($title) ? trim((string)$title) : '';

		return $title === '' ? $storedValue : $title;
	}

	private static function generateStateId(array $field): string
	{
		return static::generateControlId($field) . '_state';
	}

	/** Test identifiers keep the shape the fields of the designer are addressed by: `<prefix>-<field>`. */
	private static function generateControlTestId(array $field, string $prefix): string
	{
		return $prefix . '-' . (string)($field['Field'] ?? '');
	}

	public static function renderControlSingle(FieldType $fieldType, array $field, $value, $allowSelection, $renderMode)
	{
		return static::renderControl($fieldType, $field, $value, false, $renderMode);
	}

	public static function renderControlMultiple(FieldType $fieldType, array $field, $value, $allowSelection, $renderMode)
	{
		// The type is always single; the multiple branch would offer a control the field has no value for.
		return static::renderControl($fieldType, $field, $value, false, $renderMode);
	}

	/**
	 * Extraction and validation in one: the value is checked against the very list the control offered.
	 *
	 * A refused value is returned all the same - the link keeps pointing where it pointed, and the user is
	 * told what is wrong with it instead of finding the field emptied.
	 */
	public static function extractValueSingle(FieldType $fieldType, array $field, array $request)
	{
		static::cleanErrors();

		$value = static::normalizeValue(static::extractValue($fieldType, $field, $request));
		$linkState = static::resolveLinkState($fieldType, $value);

		if ($value === '')
		{
			if ($fieldType->isRequired())
			{
				static::addError([
					'code' => 'NotExist',
					'message' => static::formatRequiredMessage($linkState),
					'parameter' => static::generateControlName($field),
				]);
			}

			return null;
		}

		if (\CBPActivity::isExpression($value))
		{
			// An expression is computed on the run, and the link has to be known while the scheme is edited:
			// there is nothing to check a computed name against.
			static::addError([
				'code' => 'ErrorValue',
				'message' => Loc::getMessage('BPDT_NODEREFERENCE_EXPRESSION_NOT_ALLOWED'),
				'parameter' => static::generateControlName($field),
			]);

			return null;
		}

		// Only a list that had something to offer can find the link missing from it: neither a silent editor
		// nor a slice holding no node of the kind says anything about the node the link points at.
		if ($linkState === NodeCandidateCollector::LINK_STATE_SOURCE_UNAVAILABLE)
		{
			static::addError([
				'code' => 'ErrorValue',
				'message' => Loc::getMessage('BPDT_NODEREFERENCE_NOT_AVAILABLE'),
				'parameter' => static::generateControlName($field),
			]);
		}

		return $value;
	}

	public static function extractValueMultiple(FieldType $fieldType, array $field, array $request)
	{
		$value = static::extractValueSingle($fieldType, $field, $request);

		return $value === null ? [] : [$value];
	}

	public static function validateValueSingle($value, FieldType $fieldType)
	{
		$value = static::normalizeValue($value);

		return ($value === '' || \CBPActivity::isExpression($value)) ? null : $value;
	}

	/**
	 * `SelectedTitle` of the settings is not read here: it captions the value the node was saved with,
	 * while this method is asked about any value it is handed.
	 */
	protected static function formatValuePrintable(FieldType $fieldType, $value)
	{
		$value = static::normalizeValue($value);
		if ($value === '')
		{
			return '';
		}

		$candidate = NodeCandidateCollector::findCandidate(static::getCandidates($fieldType), $value);
		$title = $candidate === null ? '' : (string)($candidate['Title'] ?? '');

		return $title === '' ? $value : $title;
	}

	private static function resolveLinkState(FieldType $fieldType, string $value): string
	{
		return NodeCandidateCollector::resolveLinkState(
			static::getCandidates($fieldType),
			$value,
			static::isSchemeKnown($fieldType),
		);
	}

	/**
	 * A required field with nothing to choose from is required all the same, and asking the user to choose
	 * would ask for the impossible: the refusal says what the control says about the field.
	 */
	private static function formatRequiredMessage(string $linkState): string
	{
		return (string)Loc::getMessage(
			$linkState === NodeCandidateCollector::LINK_STATE_NO_CANDIDATES
				? 'BPDT_NODEREFERENCE_REQUIRED_NO_CANDIDATES'
				: 'BPDT_NODEREFERENCE_REQUIRED'
		);
	}

	private static function formatLinkStateLabel(string $linkState): string
	{
		return match ($linkState)
		{
			NodeCandidateCollector::LINK_STATE_SOURCE_UNAVAILABLE
				=> (string)Loc::getMessage('BPDT_NODEREFERENCE_STATE_SOURCE_UNAVAILABLE'),
			NodeCandidateCollector::LINK_STATE_NO_CANDIDATES
				=> (string)Loc::getMessage('BPDT_NODEREFERENCE_STATE_NO_CANDIDATES'),
			NodeCandidateCollector::LINK_STATE_SCHEME_UNKNOWN
				=> (string)Loc::getMessage('BPDT_NODEREFERENCE_STATE_SCHEME_UNKNOWN'),
			default => '',
		};
	}

	/** Whether the editor sent the slice of the scheme at all; that is what tells the two branches apart. */
	private static function isSchemeKnown(FieldType $fieldType): bool
	{
		$settings = $fieldType->getSettings();

		return is_array($settings) && !empty($settings['SchemeKnown']);
	}

	private static function getCandidates(FieldType $fieldType): array
	{
		$settings = $fieldType->getSettings();
		$candidates = is_array($settings) ? ($settings['Candidates'] ?? null) : null;

		return is_array($candidates) ? $candidates : [];
	}

	/** The value is a bare name of a node: no suffix, no parts and no list of them. */
	private static function normalizeValue($value): string
	{
		return is_scalar($value) ? trim((string)$value) : '';
	}
}
