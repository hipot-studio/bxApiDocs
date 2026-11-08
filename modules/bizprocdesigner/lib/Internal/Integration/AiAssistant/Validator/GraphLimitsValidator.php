<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Validator;

use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Enum\GraphErrorCode;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Error\GraphError;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Result;
use Bitrix\Main\Web\Json;

/**
 * The bounds of a submitted graph: the size of the graph itself, how many blocks and connections it carries,
 * the settings the blocks carry in total and the length of a single setting value.
 *
 * Bounds only, no rules: a graph within them is judged by the ordinary validators. They are checked in one
 * pass so the early step of the REST actions and the domain pass read the same constants through the same
 * code
 * ({@see \Bitrix\BizprocDesigner\Infrastructure\Rest\Controller\AbstractAgentRestController::validateGraphLimits()}).
 *
 * Applied under the profile of the external contour only ({@see ValidationProfile::enforcesGraphLimits()}): the
 * numbers are calibrated against templates of a test portal, and a false refusal costs an editor user more than
 * it costs an agent - extending them to the internal assistant is a product decision with a calibration of its
 * own.
 *
 * A refusal names both the bound and what was submitted: a write replaces the whole graph, so an agent not
 * told by how much it overshot has nothing to shrink towards.
 */
final class GraphLimitsValidator
{
	/**
	 * Settings across all blocks of the graph. Above the theoretical maximum of a legal graph
	 * (MAX_BLOCKS x 18 settings of the widest activity) with a margin; the largest template measured on the
	 * portal carries 748.
	 */
	public const MAX_SETTINGS = 8192;

	/**
	 * Characters of a single setting value; a non-string value counts as its serialized length. The longest
	 * value measured is 23 611 characters (the system prompt of an AI activity).
	 */
	public const MAX_SETTING_VALUE_LENGTH = 65536;

	/**
	 * Serialized size of the submitted graph, in bytes. A domain quantity rather than the size of the http
	 * body: what costs the conversion and the layout is the graph. The largest graph measured on the portal
	 * is 337 108 bytes (about 329 KB).
	 *
	 * The size an agent is answered with is the one the early step measures, on the body as it was sent, so
	 * the agent arrives at the same number itself. The domain pass measures the converted graph and reaches
	 * an answer only for a graph the early step let through - one within a few bytes of the bound.
	 */
	public const MAX_GRAPH_BYTES = 2 * 1024 * 1024;

	/**
	 * The graph is measured the length it was written, not the length the default encoder would give it:
	 * the default escapes quotes, ampersands and angle brackets into six characters each, which on the html
	 * a mail activity carries would count several times what the agent actually sent.
	 */
	private const SIZE_OPTIONS = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE;

	/**
	 * The submitted blocks and connections, in the plain shape - the raw request body before the conversion
	 * on the early step, the converted graph in the domain pass.
	 *
	 * Answers with the first bound that is exceeded and stops there: the ones after it are measured on a
	 * graph already refused, and a graph over the settings bound would otherwise answer with a problem per
	 * oversized value on top of it.
	 */
	public function validate(mixed $blocks, mixed $connections, ValidationProfile $profile): Result
	{
		if (!$profile->enforcesGraphLimits())
		{
			return new Result();
		}

		$sizeResult = $this->validateSize($blocks, $connections);
		if (!$sizeResult->isSuccess())
		{
			return $sizeResult;
		}

		$countResult = $this->validateCounts($blocks, $connections);
		if (!$countResult->isSuccess())
		{
			return $countResult;
		}

		// Blocks that are not a collection carry nothing to count; naming what is wrong with them belongs to
		// the validators that judge the shape of the graph.
		return is_array($blocks) ? $this->validateSettings($blocks) : new Result();
	}

	private function validateSize(mixed $blocks, mixed $connections): Result
	{
		$serialized = self::serialized(['blocks' => $blocks, 'connections' => $connections]);
		$size = $serialized === null ? null : strlen($serialized);
		if ($size === null || $size <= self::MAX_GRAPH_BYTES)
		{
			return new Result();
		}

		// No address: the size is of the graph as a whole and belongs to no place inside it.
		return (new Result())->addError(GraphError::unaddressed(
			'the graph should be at most ' . self::MAX_GRAPH_BYTES . " bytes, {$size} submitted",
			GraphErrorCode::GraphSizeLimitExceeded,
		));
	}

	/**
	 * How many blocks and connections the graph carries. The bounds belong to the validators that judge the
	 * two collections and are read from there, so there is still one number per bound; counting them before
	 * the conversion keeps a graph over the bound from paying for the conversion of every element first.
	 *
	 * The refusal is the one those validators give, word for word - same address, same class, same text - so
	 * one broken graph is described one way whichever pass met it first.
	 */
	private function validateCounts(mixed $blocks, mixed $connections): Result
	{
		if (is_array($blocks) && count($blocks) > AgentBlocksValidator::MAX_BLOCKS)
		{
			return (new Result())->addError(GraphError::at(
				'blocks',
				'blocks should contain at most ' . AgentBlocksValidator::MAX_BLOCKS . ' items',
				GraphErrorCode::BlockLimitExceeded,
			));
		}

		if (is_array($connections) && count($connections) > AgentConnectionsValidator::MAX_CONNECTIONS)
		{
			return (new Result())->addError(GraphError::at(
				'connections',
				'connections should contain at most ' . AgentConnectionsValidator::MAX_CONNECTIONS . ' items',
				GraphErrorCode::ConnectionLimitExceeded,
			));
		}

		return new Result();
	}

	private function validateSettings(array $blocks): Result
	{
		$submittedSettings = 0;
		$overflowPath = null;
		$valueErrors = [];

		foreach ($blocks as $blockKey => $block)
		{
			$settings = is_array($block) ? ($block['settings'] ?? null) : null;
			if (!is_array($settings))
			{
				continue;
			}

			$submittedSettings += count($settings);
			if ($overflowPath === null && $submittedSettings > self::MAX_SETTINGS)
			{
				// The block the count went over on: the graph as a whole is over the bound, and this is where
				// an agent shortening it has to start.
				$overflowPath = "blocks.{$blockKey}.settings";
			}

			if ($overflowPath !== null)
			{
				continue;
			}

			foreach ($settings as $settingKey => $setting)
			{
				$value = is_array($setting) ? ($setting['value'] ?? null) : null;
				$length = self::valueLength($value);
				if ($length > self::MAX_SETTING_VALUE_LENGTH)
				{
					$valuePath = "blocks.{$blockKey}.settings.{$settingKey}.value";
					$valueErrors[] = GraphError::at(
						$valuePath,
						"{$valuePath} should be at most " . self::MAX_SETTING_VALUE_LENGTH
							. " characters, {$length} submitted",
						GraphErrorCode::SettingValueLimitExceeded,
					);
				}
			}
		}

		if ($overflowPath !== null)
		{
			return (new Result())->addError(GraphError::at(
				$overflowPath,
				"{$overflowPath} brings the graph over the limit of " . self::MAX_SETTINGS
					. " settings, {$submittedSettings} submitted in total",
				GraphErrorCode::SettingLimitExceeded,
			));
		}

		return (new Result())->addErrors($valueErrors);
	}

	/**
	 * An array value is measured serialized, the way it travels and the way it is stored; a scalar is
	 * measured as the string the conversion turns it into.
	 */
	private static function valueLength(mixed $value): int
	{
		if (is_string($value))
		{
			return mb_strlen($value);
		}

		if (is_array($value))
		{
			$serialized = self::serialized($value);

			return $serialized === null ? 0 : mb_strlen($serialized);
		}

		return is_scalar($value) ? mb_strlen((string)$value) : 0;
	}

	/**
	 * Null when the value cannot be serialized at all - a bound that cannot be measured is not a bound that
	 * was exceeded, and the graph goes on to the validators that can name what is wrong with it.
	 */
	private static function serialized(mixed $value): ?string
	{
		try
		{
			return (string)Json::encode($value, self::SIZE_OPTIONS);
		}
		catch (ArgumentException)
		{
			return null;
		}
	}
}
