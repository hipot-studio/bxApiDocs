<?php

namespace Bitrix\AI\Facade;

use Bitrix\AI\Engine\IEngine;
use Bitrix\AI\Engine\ThirdParty;
use Bitrix\AI\Payload\Audio;
use Bitrix\AI\Payload\Prompt;
use Bitrix\AI\Payload\StyledPicture;
use Bitrix\Main\Analytics\AnalyticsEvent;
use Bitrix\Main\Application;
use Bitrix\Main\Engine\Response\Converter;
use Bitrix\Main\Text\StringHelper;

class Analytics
{

	private const DEFAULT_TOOL = 'ai';
	private const FEATURE_RELEASE_EVENT = 'feature_release';
	private const FEATURE_RELEASE_CATEGORY = 'release';
	private const FEATURE_FLAG_PREFIX = 'flag_';
	private const PROMPT_SECTION_REWRITE = [
		'set_ai_session_name' => 'system_chat',

		'translate_picture_request' => 'system_pic',

		'summarize_transcript' => 'system_crm_call',
		'extract_form_fields' => 'system_crm_call',
		'scoring_criteria_extraction' => 'system_crm_call',
		'call_scoring' => 'system_crm_call',

		'flows_recommendations' => 'system_flow',

		'meeting_summarization' => 'system_videocall',
		'meeting_overview' => 'system_videocall',
		'meeting_insights' => 'system_videocall',

		'landing_ai_data' => 'system_site',
		'landing_ai_content' => 'system_site',
		'landing_ai_block_content' => 'system_site',
	];

	public static function engineGenerateResultEvent(
		string $eventType,
		IEngine $engine,
		array $analyticData,
	)
	{
		$converter = new Converter(Converter::TO_CAMEL);
		if ($engine->getPayload() instanceof StyledPicture)
		{
			$section = $analyticData['c_section'] ?? '';
			$promptCode = 'prompt_' . $converter->process(($engine->getPayload()->getData()['style'] ?? $engine->getPayload()->getData()['prompt']));
			$p3 = 'format_' . $converter->process(($engine->getPayload()->getData()['format'] ?? ''));
			$analyticData['category'] = 'image_operations';
		}
		else
		{
			$section = ($engine->getPayload() instanceof Prompt) ? $engine->getPayload()->getPromptCategory() : '';
			$promptCode = ($engine->getPayload() instanceof Prompt) ? 'prompt_' . $converter->process($engine->getPayload()->getPromptCode()) : '';
			$p3 = ($engine->getPayload()->getRole() !== null) ? 'role_' . $converter->process($engine->getPayload()->getRole()->getCode()) : '';
			if ($engine->getPayload() instanceof Prompt)
			{
				if (array_key_exists($engine->getPayload()->getPromptCode(), self::PROMPT_SECTION_REWRITE))
				{
					$section = self::PROMPT_SECTION_REWRITE[$engine->getPayload()->getPromptCode()];
				}
				else if($engine->getPayload()->hasSystemCategory())
				{
					$section = 'system';
				}
			}
		}

		$category = $analyticData['category'] ?? '';
		if ($category === '')
		{
			$category = ($engine->getPayload() instanceof Audio) ? 'audio_operations' : 'text_operations';
		}

		if ($section === '')
		{
			$section = match ($engine->getContext()->getModuleId())
			{
				'im' => 'from_chat',
				'crm' => 'crm',
				'tasks' => 'tasks',
				default => $engine->getContext()->getModuleId(),
			};
		}

		$providerPrefix = ($engine instanceof ThirdParty) ? 'marketprovider_' : 'provider_';

		$analyticsEvent = new AnalyticsEvent($eventType, 'ai', $category);
		$analyticsEvent
			->setType(($analyticData['type'] ?? ''))
			->setSection($section)
			->setSubSection(($analyticData['c_sub_section'] ?? ''))
			->setElement(($analyticData['c_element'] ?? ''))
			->setP1($promptCode)
			->setP2($providerPrefix . $engine->getName())
			->setP3($p3)
			->setP5($converter->process($engine->getContext()->getContextId()))
			->setUserId($engine->getContext()->getUserId())
			->send();
	}

	public static function sendAiQueryLimitEvent(string $errorLimit, string $moduleId, int $userId): void
	{
		self::buildAiQueryLimitEvent($errorLimit, $moduleId, $userId)->send();
	}

	protected static function buildAiQueryLimitEvent(
		string $errorLimit,
		string $moduleId,
		int $userId,
		?bool $isWestZone = null,
		?bool $hasActiveVibePlusEdition = null,
	): AnalyticsEvent
	{
		$event = new AnalyticsEvent('limit_exceeded', self::DEFAULT_TOOL, 'limits');
		$event
			->setType('bitrixgpt_limit')
			->setP1('moduleId_' . $moduleId)
			->setSection(strtolower(str_replace('_', '-', $errorLimit)))
			->setUserId($userId)
		;

		$isWestZone ??= Portal::isWestZone();
		if ($isWestZone)
		{
			$hasActiveVibePlusEdition ??= Portal::hasActiveVibePlusEdition();
			$event->setP2('isVibe_' . ($hasActiveVibePlusEdition ? 'true' : 'false'));
		}

		return $event;
	}

	/**
	 * Sends the feature release event for the given feature flag identifier.
	 *
	 * Returns true only when the platform has actually written the event: a caller that
	 * remembers sent flags must not mark a flag whose event was silently dropped.
	 *
	 * The checks below mirror the early exits of the platform, which returns void and so cannot be
	 * asked whether it wrote anything. `send()` gives up on three conditions: an undefined log file
	 * constant, a file that is not writable, and a payload its own validation rejects. The first
	 * two are mirrored here literally; the third is covered by the identifier checks above, since
	 * every other field of the event is a literal of this class. Should the mirror ever drift from
	 * `send()`, this method returns true for a dropped event, the caller writes its marker, and the
	 * release date of that flag is lost for good - so the mirror moves only together with `send()`.
	 *
	 * @see \Bitrix\Main\Analytics\AnalyticsEvent::send()
	 *
	 * @param string $flagIdentifier Feature identifier in lowerCamelCase, without the `flag_` prefix.
	 * @return bool
	 */
	public static function sendFeatureReleaseEvent(string $flagIdentifier): bool
	{
		// Built up front for the platform limit check below, which the current identifier format
		// leaves unreachable: the conversion keeps no underscore. Kept in case the format changes.
		$p1 = self::buildFeatureReleaseP1($flagIdentifier);

		if ($flagIdentifier === '')
		{
			return false;
		}

		if (!preg_match('/^[A-Za-z][A-Za-z0-9]*\z/', $flagIdentifier))
		{
			return false;
		}

		if (substr_count($p1, '_') > 1)
		{
			return false;
		}

		if (!defined('ANALYTICS_V2_FILENAME'))
		{
			return false;
		}

		if (!is_writable(ANALYTICS_V2_FILENAME))
		{
			return false;
		}

		self::buildFeatureReleaseEvent($flagIdentifier)->send();

		return true;
	}

	/**
	 * Converts a module option name into a feature flag identifier.
	 *
	 * @param string $optionName
	 * @return string
	 */
	public static function optionNameToFlagIdentifier(string $optionName): string
	{
		$name = $optionName;
		if (str_starts_with($name, self::FEATURE_FLAG_PREFIX))
		{
			$name = substr($name, strlen(self::FEATURE_FLAG_PREFIX));
		}

		// No stock converter normalizes hyphens, and a hyphen would reach the metric as is.
		$name = str_replace('-', '_', $name);

		return StringHelper::snake2camel($name, true);
	}

	protected static function buildFeatureReleaseEvent(string $flagIdentifier): AnalyticsEvent
	{
		return (new AnalyticsEvent(
			event: self::FEATURE_RELEASE_EVENT,
			tool: self::DEFAULT_TOOL,
			category: self::FEATURE_RELEASE_CATEGORY,
		))
			->setP1(self::buildFeatureReleaseP1($flagIdentifier))
			->setUserId(0)
		;
	}

	private static function buildFeatureReleaseP1(string $flagIdentifier): string
	{
		return self::FEATURE_FLAG_PREFIX . $flagIdentifier;
	}
}
