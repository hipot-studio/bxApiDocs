<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Rest\Service;

use Bitrix\BizprocDesigner\Infrastructure\Rest\Dto\ValidationIssueDto;
use Bitrix\BizprocDesigner\Infrastructure\Rest\Dto\ValidationReportDto;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Result\AgentWorkflowValidationResult;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Rest\V3\Dto\DtoCollection;

/**
 * Turns the verdict of the graph validators into the report of template.validate.
 *
 * Almost everything the report carries is already in the error: the address in Error::code, the class of
 * the failure in customData['errorCode'], the server-side dictionaries in customData['details']. The block
 * is not - it is recovered from the index inside the address against the submitted blocks, or read from
 * customData['blockId'] where a validator named it. The text of the message is never read: an address is
 * never parsed out of it.
 *
 * Blocking problems and non-blocking risks travel apart all the way here: the errors of the result are the
 * issues, and only an accepted graph carries warnings ({@see AgentWorkflowValidationResult}). Hence the
 * verdict rests on the issues alone - a warning never makes a graph invalid.
 *
 * The refusal of template.draft.add needs no mapping - the errors go to the core untouched
 * ({@see \Bitrix\BizprocDesigner\Infrastructure\Rest\Exception\GraphValidationException}).
 */
final class GraphValidationErrorMapper
{
	/**
	 * Keys DTO-02 allows in details. The filter stands here rather than where an error is built: this is the
	 * one place details leaves the server, so the guarantee holds for an error assembled anywhere.
	 */
	private const DETAIL_KEYS = [
		'allowedKeys',
		'requiredKeys',
		'allowedOutputs',
		'constraint',
		'maxBytes',
		'actualBytes',
		'recommendedSelect',
	];

	/**
	 * @param array $blocks blocks as the caller submitted them - the addresses count their positions here
	 */
	public static function toReport(Result $result, array $blocks): ValidationReportDto
	{
		$issues = self::toIssues($result->getErrors(), $blocks);

		$report = new ValidationReportDto();
		$report->valid = $issues->count() === 0;
		$report->issues = $issues;
		$report->warnings = self::toIssues(self::warningsOf($result), $blocks);

		return $report;
	}

	/**
	 * @param list<Error> $errors
	 */
	private static function toIssues(array $errors, array $blocks): DtoCollection
	{
		$issues = new DtoCollection(ValidationIssueDto::class);
		foreach ($errors as $error)
		{
			$issues->add(self::toIssue($error, $blocks));
		}

		return $issues;
	}

	/**
	 * Risks the validators judged worth naming without refusing the graph. Only an accepted graph carries
	 * them: a refused one leaves the agent a blocking problem to fix and a re-run to read the risks from.
	 *
	 * @return list<Error>
	 */
	private static function warningsOf(Result $result): array
	{
		return $result instanceof AgentWorkflowValidationResult ? $result->warnings : [];
	}

	private static function toIssue(Error $error, array $blocks): ValidationIssueDto
	{
		$path = self::address($error);

		$issue = new ValidationIssueDto();
		$issue->path = $path;
		$issue->blockId = self::statedBlockId($error) ?? self::blockId($path, $blocks);
		$issue->code = self::errorCode($error);
		$issue->message = $error->getMessage();
		$issue->details = self::details($error);

		return $issue;
	}

	/**
	 * The address a validator wrote, and nothing else. Error::code defaults to the integer 0, so casting
	 * whatever stands there would answer a branch that named no address with the address "0" - a block of
	 * the submitted graph the problem is not about.
	 */
	private static function address(Error $error): string
	{
		$code = $error->getCode();

		return is_string($code) ? $code : '';
	}

	/**
	 * The block a validator named itself. An error about the graph as a whole carries no address to recover
	 * a block from, yet may well be about one - an unclosed loop is about its loop node.
	 */
	private static function statedBlockId(Error $error): ?string
	{
		$customData = $error->getCustomData();
		$blockId = is_array($customData) ? ($customData['blockId'] ?? null) : null;

		return is_string($blockId) && $blockId !== '' ? $blockId : null;
	}

	/**
	 * The block the problem belongs to, taken from the "blocks.<index>" its address opens with. Null both
	 * for a problem outside a block and for a submitted block carrying no usable id - the report is built
	 * for an invalid graph too, where the id itself may be what the validator rejected.
	 */
	private static function blockId(string $path, array $blocks): ?string
	{
		$index = self::blockIndex($path);
		if ($index === null)
		{
			return null;
		}

		$block = $blocks[$index] ?? null;
		$id = is_array($block) ? ($block['id'] ?? null) : null;

		return is_scalar($id) ? (string)$id : null;
	}

	private static function blockIndex(string $path): ?int
	{
		$segments = explode('.', $path);
		if (($segments[0] ?? '') !== 'blocks' || preg_match('/^[0-9]+$/D', $segments[1] ?? '') !== 1)
		{
			return null;
		}

		return (int)$segments[1];
	}

	private static function errorCode(Error $error): ?string
	{
		$customData = $error->getCustomData();
		$errorCode = is_array($customData) ? ($customData['errorCode'] ?? null) : null;

		return is_string($errorCode) ? $errorCode : null;
	}

	/**
	 * Server-side dictionaries of the problem, reduced to the keys DTO-02 publishes: what an agent was
	 * submitted, what it addressed and what it sent never travel here.
	 */
	private static function details(Error $error): ?array
	{
		$customData = $error->getCustomData();
		$details = is_array($customData) ? ($customData['details'] ?? null) : null;
		if (!is_array($details))
		{
			return null;
		}

		$published = array_intersect_key($details, array_flip(self::DETAIL_KEYS));

		return $published === [] ? null : $published;
	}
}
