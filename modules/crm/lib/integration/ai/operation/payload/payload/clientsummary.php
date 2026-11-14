<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\AI\Operation\Payload\Payload;

use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\Operation\Payload\CalcMarkersInterface;
use Bitrix\Crm\Integration\AI\Operation\Payload\PayloadInterface;
use Bitrix\Crm\V2\Internal\Entity\Summary\SummaryResult;
use Bitrix\Crm\V2\Internal\Service\AiSummary\SummaryRules;
use Bitrix\Crm\V2\Internal\Service\Summary\ClientSummaryService;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Web\Json;

/**
 * The input of the client summary: the rules of the answer and the data they are applied to, both
 * as values of markers.
 *
 * The record of the prompt registry stays a thin wrapper - the engine directives and the markers,
 * and no wording of its own. A directive written into a value is neutralized here instead of being
 * relied on to arrive too late: the markers are substituted by the fourth formatter of the ai module
 * (`Markers`), and the eight that follow it - `UserMarkers`, `AuthorMessage`, `ContextMessages`,
 * `Language`, `CopilotName`, `Fields`, `Role`, `Clean` - walk the text with the values already in it.
 *
 * The data is collected on behalf of the user who asked for the summary, so a section they may not
 * read arrives as `restricted` and never as missing. The states of the sections and the marks of an
 * incomplete history are passed on as the service worded them: the rules are written for exactly
 * these keys.
 *
 * Empty markers are how a failure is told - the operation turns them into a launch error instead of
 * sending the model a prompt with its placeholders still in it.
 */
final class ClientSummary extends AbstractPayload implements CalcMarkersInterface
{
	public const MARKER_RULES = 'summary_rules';
	public const MARKER_DATA = 'summary_data';

	public function getPayloadCode(): string
	{
		return 'generate_client_summary';
	}

	public function setMarkers(array $markers): PayloadInterface
	{
		$this->markers = array_merge($markers, $this->calcMarkers());

		return $this;
	}

	public function calcMarkers(): array
	{
		$summary = $this->collectSummary();
		if ($summary === null)
		{
			return [];
		}

		try
		{
			$data = Json::encode(self::neutralizeDirectives($summary->toArray()));
		}
		catch (ArgumentException $exception)
		{
			AIManager::logger()->error(
				'{date}: {class}: Client summary data of {target} cannot be encoded: {error}' . PHP_EOL,
				[
					'class' => self::class,
					'target' => $this->identifier,
					'error' => $exception->getMessage(),
				],
			);

			return [];
		}

		$markers = [
			self::MARKER_RULES => self::rules(),
			self::MARKER_DATA => $data,
		];

		$this->logInputSize($markers);

		return $markers;
	}

	/**
	 * Replaces the curly brackets of the collected texts with their fullwidth twins, the way the
	 * rendered message replaces the square ones of BBCode
	 * ({@see \Bitrix\Crm\V2\Internal\Service\AiSummary\SummaryMessageRenderer}). Only the
	 * leaves are touched: the brackets of the JSON itself are the shape of the value and not a text of
	 * a third party, and a structural one is never followed by the name of a directive.
	 */
	private static function neutralizeDirectives(array $data): array
	{
		foreach ($data as $key => $value)
		{
			if (is_array($value))
			{
				$data[$key] = self::neutralizeDirectives($value);
			}
			elseif (is_string($value))
			{
				$data[$key] = strtr($value, ['{' => "\u{FF5B}", '}' => "\u{FF5D}"]);
			}
		}

		return $data;
	}

	/**
	 * The operation takes every part of the rules, the JSON contract included: unlike the agent, it
	 * owes a strict structure and not a text.
	 */
	private static function rules(): string
	{
		return implode("\n\n", [
			SummaryRules::blocks(),
			SummaryRules::principles(),
			SummaryRules::sectionStates(),
			SummaryRules::incompleteness(),
			SummaryRules::injectionGuard(),
			SummaryRules::responseLanguage(Loc::getCurrentLang()),
			SummaryRules::jsonContract(),
		]);
	}

	private function collectSummary(): ?SummaryResult
	{
		$result = (new ClientSummaryService())->collect(
			$this->identifier->getEntityTypeId(),
			$this->identifier->getEntityId(),
			$this->userId,
		);

		if (!$result->isSuccess())
		{
			AIManager::logger()->error(
				'{date}: {class}: Client summary data of {target} is not collected: {errors}' . PHP_EOL,
				[
					'class' => self::class,
					'target' => $this->identifier,
					'errors' => $result->getErrorMessages(),
				],
			);

			return null;
		}

		$summary = $result->getData()['summary'] ?? null;

		return $summary instanceof SummaryResult ? $summary : null;
	}

	/**
	 * The volume of the input is measured here: the markers are the whole of what the operation
	 * sends, so the budget of the engine is spent on exactly these two values.
	 */
	private function logInputSize(array $markers): void
	{
		AIManager::logger()->info(
			'{date}: {class}: Client summary input of {target}: rules={rulesLength}, data={dataLength}' . PHP_EOL,
			[
				'class' => self::class,
				'target' => $this->identifier,
				'rulesLength' => mb_strlen($markers[self::MARKER_RULES]),
				'dataLength' => mb_strlen($markers[self::MARKER_DATA]),
			],
		);
	}
}
