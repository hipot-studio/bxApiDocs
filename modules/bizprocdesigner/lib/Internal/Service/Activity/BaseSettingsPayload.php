<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Service\Activity;

use Bitrix\Bizproc\Internal\Service\Activity\UnifiedPanelDescriptorProvider;
use Bitrix\Bizproc\Internal\Service\Container;
use Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\Rule\BaseSettingsExpressionDto;

/**
 * What a base-settings construction carries of its own, and whether anyone ever filled it. One rule for the
 * two contours that ask: the converter builds no child of an untouched proxy
 * ({@see \Bitrix\BizprocDesigner\Internal\Command\Activity\Complex\ConvertRuleCommand}), so the validation
 * must let through exactly what the converter drops
 * ({@see \Bitrix\BizprocDesigner\Internal\Command\Activity\Complex\ValidateSingleRuleCommand}). The block
 * is put on the node by the editor itself the moment the node is opened (initBaseSettingsIfNeeded), and a
 * form nobody opened would otherwise mark the whole node unfilled on the first save.
 */
final class BaseSettingsPayload
{
	/**
	 * Host-metadata keys SaveCommandHandler injects into the Properties of every activity. A base-settings
	 * block has no title or comment of its own, so they arrive empty here and are none of its state.
	 */
	private const HOST_METADATA_PROPERTIES = ['Title', 'EditorComment'];

	/** Properties the construction owns: its payload without the host metadata the save path injects. */
	public static function extractProperties(BaseSettingsExpressionDto $expression): array
	{
		$activityData = $expression->activityData ?? [];
		$properties = is_array($activityData['Properties'] ?? null) ? $activityData['Properties'] : [];

		foreach (self::HOST_METADATA_PROPERTIES as $key)
		{
			unset($properties[$key]);
		}

		return $properties;
	}

	/**
	 * The same payload as the node itself receives it. Asked only where the construction is merged into its
	 * host, so the emptiness of a form - it answers for a proxy child too - is left reading the payload whole.
	 */
	public static function extractHostMergedProperties(BaseSettingsExpressionDto $expression): array
	{
		$properties = self::extractProperties($expression);

		foreach (self::nodeGraphProperties() as $key)
		{
			unset($properties[$key]);
		}

		return $properties;
	}

	/** Whether the form behind the construction holds nothing the user put there. */
	public static function isFormEmpty(BaseSettingsExpressionDto $expression): bool
	{
		return self::isEmptyValue(self::extractProperties($expression));
	}

	/**
	 * Properties describing the graph of the node, the settings of its filters and the verdict of the panel
	 * over its cards, never a value of a settings form. A construction extracted through the node-action path
	 * is shaped as a full activity and carries its own empty copy of them; host-merging that copy would
	 * replace the rules of the node with the empty scaffold of its own card and lose every setting the person
	 * saved.
	 *
	 * Asked of the one place the set is declared in and never listed here: the panel owns it whole
	 * ({@see UnifiedPanelDescriptorProvider::getServiceProperties()}), and a name it gains later would
	 * otherwise be left out of the cleanup - an empty copy of the form silently replacing a real value of the
	 * node, by the very mechanism this cleanup exists against.
	 *
	 * @return list<string>
	 */
	private static function nodeGraphProperties(): array
	{
		return Container::instance()->getUnifiedPanelDescriptorProvider()->getServicePropertyNames();
	}

	/**
	 * Emptiness of a property payload down the nesting: {@see \CBPHelper::isEmptyValue} looks at the top
	 * level only, and the empty state of a field-changing action is `['FieldValue' => []]` there - an
	 * array of one empty array. Zero stays a value, as everywhere in the engine.
	 */
	private static function isEmptyValue(mixed $value): bool
	{
		if (is_array($value))
		{
			foreach ($value as $item)
			{
				if (!self::isEmptyValue($item))
				{
					return false;
				}
			}

			return true;
		}

		return \CBPHelper::isEmptyValue($value);
	}
}
