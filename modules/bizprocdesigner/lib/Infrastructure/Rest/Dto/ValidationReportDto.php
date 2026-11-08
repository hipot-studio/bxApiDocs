<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Rest\Dto;

use Bitrix\Rest\V3\Attribute\Description;
use Bitrix\Rest\V3\Attribute\ElementType;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\DtoCollection;

/**
 * Verdict of validating an agent-facing workflow graph (DTO-02).
 *
 * The verdict covers both the domain validation and the post-layout frame check, because dry-run
 * validating and pushing a draft are required to answer with the same one.
 *
 * The report is built from a domain Result by {@see \Bitrix\BizprocDesigner\Infrastructure\Rest\Service\GraphValidationErrorMapper},
 * not by a dto mapper of its own: the whole resource is read-only.
 */
class ValidationReportDto extends Dto
{
	#[Description(
		'The submitted graph passed both the domain validation and the post-layout check.'
		. ' True exactly when issues is empty; warnings do not affect it.'
	)]
	public bool $valid;

	#[ElementType(ValidationIssueDto::class)]
	#[Description('Blocking problems found in the submitted graph. Empty when the graph is valid.')]
	public DtoCollection $issues;

	#[ElementType(ValidationIssueDto::class)]
	#[Description(
		'Non-blocking risks of the submitted graph: the graph is accepted, and the write path takes it as it is.'
		. ' They come with the dry run alone - a refused write answers with its problems only.'
	)]
	public DtoCollection $warnings;
}
