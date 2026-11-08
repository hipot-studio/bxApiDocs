<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Activity;

use Bitrix\Bizproc\Activity\Dto\ExpressionLabelDictionary;
use Bitrix\Bizproc\Activity\Dto\ReadableExpressionResult;
use Bitrix\Bizproc\Automation\Helper;
use Bitrix\Bizproc\Workflow\Template\SourceType;
use Bitrix\Bizproc\Workflow\Template\SystemValueCatalog;
use Bitrix\Main\Application;

/**
 * Expands one expression value into readable text: every reference stands as {Field name}, plain
 * text between references is carried over as is. A reference no name is known for stays untouched and
 * clears the resolution flag. Reads names only, never values; display-only.
 */
final class ReadableExpressionResolver
{
	/** Robot designer form of a reference: a readable name, or a service record behind a tilde. */
	private const AUTOMATION_FORM_PATTERN = '/\{\{(?<mixed>[^=].*?)\}\}/is';

	private const SERVICE_FORM_PREFIX = '~';
	private const SERVICE_FORM_LABEL_SEPARATOR = '#';
	private const SERVICE_FORM_PARAMETER_PREFIX = '*:';
	private const SERVICE_FORM_CONSTANT_PREFIX = '&:';

	/** @var array<string, array<string, string>> source type => (field id => field name) */
	private array $nameMaps = [];

	public function __construct(
		private readonly array $documentType,
		private readonly ExpressionLabelDictionary $labels,
	) {}

	public function resolve(string $expression): ReadableExpressionResult
	{
		if (self::isCalculated($expression))
		{
			return new ReadableExpressionResult($expression, false);
		}

		$isFullyResolved = true;
		$substitute = static function (?string $label, string $reference) use (&$isFullyResolved): string
		{
			if ($label === null || $label === '')
			{
				$isFullyResolved = false;

				return $reference;
			}

			return '{' . $label . '}';
		};

		$designerForms = preg_replace_callback(
			self::AUTOMATION_FORM_PATTERN,
			fn(array $matches): string => $substitute($this->readDesignerLabel($matches['mixed']), $matches[0]),
			$expression,
		);
		if ($designerForms === null)
		{
			// A failed pass has expanded nothing: the expression is unresolved, not resolved into an empty text.
			return new ReadableExpressionResult($expression, false);
		}

		$text = preg_replace_callback(
			\CBPActivity::ValueInlinePattern,
			fn(array $matches): string => $substitute(
				$this->readReferenceLabel($matches['object'], $matches['field']),
				$matches[0],
			),
			$designerForms,
		);
		if ($text === null)
		{
			return new ReadableExpressionResult($expression, false);
		}

		return new ReadableExpressionResult($text, $isFullyResolved);
	}

	private function readDesignerLabel(string $mixed): ?string
	{
		$mixed = trim($mixed);
		if (str_starts_with($mixed, self::SERVICE_FORM_PREFIX))
		{
			return $this->readServiceFormLabel($mixed);
		}

		return $this->readNameFormLabel($mixed);
	}

	/**
	 * A readable name is matched by the conversion of the robot designer itself and not by a map of our
	 * own, so that the rules of the match stay in one place: the ">" modifiers, the "_printable" suffix,
	 * the visibility prefix a global is written with. A name the conversion knows nothing of leaves the
	 * record raw.
	 */
	private function readNameFormLabel(string $mixed): ?string
	{
		$parsed = \CBPActivity::parseExpression($this->unConvertNameForm($mixed));
		if ($parsed === null)
		{
			return null;
		}

		return $this->readReferenceLabel($parsed['object'], $parsed['field']);
	}

	/** Fail-closed the same way {@see self::readNameMapOnce()} is. */
	private function unConvertNameForm(string $mixed): string
	{
		try
		{
			return (string)Helper::unConvertExpressions('{{' . $mixed . '}}', $this->documentType);
		}
		catch (\Throwable $e)
		{
			Application::getInstance()->getExceptionHandler()->writeToLog($e);

			return '';
		}
	}

	/**
	 * Only a service record carrying a label of its own after a "#" is worth expanding, and its name
	 * comes from the reference and not from that label: a reference to a deleted step has to stay
	 * unresolved. Unwrapped the way Automation\Helper::unConvertExpressions() unwraps it.
	 */
	private function readServiceFormLabel(string $mixed): ?string
	{
		$labelPosition = mb_strpos($mixed, self::SERVICE_FORM_LABEL_SEPARATOR);
		if ($labelPosition === false)
		{
			return null;
		}

		$reference = trim(mb_substr($mixed, 1, $labelPosition - 1));
		if (str_starts_with($reference, self::SERVICE_FORM_PARAMETER_PREFIX))
		{
			$reference = SourceType::Parameter . mb_substr($reference, 1);
		}
		elseif (str_starts_with($reference, self::SERVICE_FORM_CONSTANT_PREFIX))
		{
			$reference = SourceType::Constant . mb_substr($reference, 1);
		}

		$parsed = \CBPActivity::parseExpression('{=' . $reference . '}');
		if ($parsed === null)
		{
			return null;
		}

		return $this->readReferenceLabel($parsed['object'], $parsed['field']);
	}

	private function readReferenceLabel(string $objectName, string $fieldName): ?string
	{
		$source = SourceType::getObjectSourceType($objectName, $fieldName);
		if ($source === null)
		{
			return null;
		}

		return match ($source[0])
		{
			SourceType::DocumentField,
			SourceType::GlobalVariable,
			SourceType::GlobalConstant => $this->readNameMap($source[0])[$source[1]] ?? null,

			SourceType::Parameter,
			SourceType::Variable,
			SourceType::Constant => $this->labels->resolve($source[0], $source[1]),

			SourceType::Activity => $this->labels->resolve(
				SourceType::Activity,
				ExpressionLabelDictionary::makeActivityOutputKey($source[1], $source[2]),
			),

			SourceType::System => SystemValueCatalog::getLabel($source[1], $source[2]),

			default => null,
		};
	}

	/** @return array<string, string> */
	private function readNameMap(string $sourceType): array
	{
		$this->nameMaps[$sourceType] ??= $this->readNameMapOnce($sourceType);

		return $this->nameMaps[$sourceType];
	}

	/**
	 * Fail-closed: an unreadable name source leaves every reference of that source unresolved instead
	 * of breaking the whole readable text. Log for diagnostics.
	 *
	 * @return array<string, string>
	 */
	private function readNameMapOnce(string $sourceType): array
	{
		try
		{
			return $sourceType === SourceType::DocumentField
				? Helper::getFieldNames($this->documentType)
				: Helper::getGlobalNames($sourceType, $this->documentType)
			;
		}
		catch (\Throwable $e)
		{
			Application::getInstance()->getExceptionHandler()->writeToLog($e);

			return [];
		}
	}

	private static function isCalculated(string $expression): bool
	{
		return
			preg_match(\CBPActivity::CalcPattern, $expression) === 1
			|| preg_match(\CBPActivity::CalcInlinePattern, $expression) === 1
		;
	}
}
