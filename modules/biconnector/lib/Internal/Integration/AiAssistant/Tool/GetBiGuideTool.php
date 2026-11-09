<?php

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool;

use Bitrix\AiAssistant\Exceptions\ValidationException;
use Bitrix\BIConnector\Internal\Feature\FlagGate;
use Bitrix\BIConnector\Internal\Integration\AiAssistant\Operation\OperationImpact;
use Bitrix\BIConnector\Internal\Operation\Builder\Actor;
use Bitrix\BIConnector\Internal\Operation\Builder\Channel;
use Bitrix\BIConnector\Internal\Operation\Builder\Guide\GetGuideOperation;
use Bitrix\BIConnector\Internal\Operation\Builder\RestAvailability;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Localization\Loc;

/**
 * The agent guide of BI Builder: the table of contents or one section, as markdown with a trailer
 * `revision: … · language: … · next: …`. The guide reads no Superset, so the call does not start it.
 */
final class GetBiGuideTool extends BaseBiTool
{
	private const TRAILER_SEPARATOR = "\n\n---\n";
	private const NO_NEXT = '—';
	private const MAX_SECTION_LENGTH = 64;

	public function getName(): string
	{
		return 'get_bi_guide';
	}

	public function getImpact(): OperationImpact
	{
		return OperationImpact::Read;
	}

	public function getDescription(): string
	{
		return 'Returns the guide to BI Builder for agents: how to build and edit reports, charts and datasets '
			. 'with these tools. Call it before creating or editing a report. '
			. 'Without `section` it returns the general rules and the table of contents; with `section` it returns '
			. 'that section. Every answer ends with `revision`, `language` and `next`: read the guide by calling '
			. 'the tool with `section` = `next`, starting from the table of contents, until `next` is `—`.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'section' => [
					'type' => 'string',
					'maxLength' => self::MAX_SECTION_LENGTH,
					'description' => 'Id of a section from the table of contents. '
						. 'Omit it to get the table of contents.',
				],
			],
			'additionalProperties' => false,
		];
	}

	public function canList(int $userId): bool
	{
		return $this->isGuideAvailable($userId);
	}

	public function canRun(int $userId): bool
	{
		return $this->isGuideAvailable($userId);
	}

	protected function execute(int $userId, ...$args): string
	{
		$section = $args['section'] ?? null;
		if (is_string($section) && mb_strlen($section, 'UTF-8') > self::MAX_SECTION_LENGTH)
		{
			throw new ValidationException('Guide section must not exceed 64 characters.');
		}

		$result = (new GetGuideOperation())->execute(
			is_string($section) ? $section : null,
			Loc::getCurrentLang(),
		);
		if (!$result->isSuccess())
		{
			throw new ValidationException($result->getErrors()[0]->getMessage());
		}

		$data = $result->getData();
		if (array_key_exists('text', $data))
		{
			return $data['text'] . $this->trailer($data, $data['next']);
		}

		$lines = [];
		foreach ($data['sections'] as $entry)
		{
			$lines[] = '- `' . $entry['id'] . '` — ' . $entry['title'] . ' (' . $entry['bytes'] . ' bytes)';
		}

		return $data['rules'] . "\n\n" . implode("\n", $lines) . $this->trailer($data, $data['start']);
	}

	private function trailer(array $data, ?string $next): string
	{
		return self::TRAILER_SEPARATOR
			. 'revision: ' . $data['revision']
			. ' · language: ' . $data['language']
			. ' · next: ' . ($next ?? self::NO_NEXT)
		;
	}

	private function isGuideAvailable(int $userId): bool
	{
		if ($userId <= 0 || !self::isBiAvailable())
		{
			return false;
		}

		if (!(new FlagGate())->isEnabled(FlagGate::BUILDER_AGENT_SKILL, $userId))
		{
			return false;
		}

		$actor = new Actor($userId, Channel::Mcp);

		return ServiceLocator::getInstance()->get(RestAvailability::class)->check($actor)->isSuccess();
	}
}
