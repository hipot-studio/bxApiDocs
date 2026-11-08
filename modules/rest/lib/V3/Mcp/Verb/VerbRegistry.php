<?php

namespace Bitrix\Rest\V3\Mcp\Verb;

use Bitrix\Rest\V3\Schema\MethodDescription;

final class VerbRegistry
{
	public function resolve(MethodDescription $methodDescription): ?ResolvedVerb
	{
		$actionUri = strtolower($methodDescription->actionUri);

		if (str_ends_with($actionUri, '.field.list'))
		{
			return new ResolvedVerb(
				code: 'field.list',
				descriptionTemplate: 'List available fields for %s.',
				readOnlyHint: true,
			);
		}

		if (str_ends_with($actionUri, '.field.get'))
		{
			return new ResolvedVerb(
				code: 'field.get',
				descriptionTemplate: 'Get metadata for a specific %s field.',
				readOnlyHint: true,
			);
		}

		return match (strtolower($methodDescription->method))
		{
			'add' => new ResolvedVerb(
				code: 'add',
				descriptionTemplate: 'Create a new %s.',
			),
			'list' => new ResolvedVerb(
				code: 'list',
				descriptionTemplate: 'List %s records.',
				readOnlyHint: true,
			),
			'get' => new ResolvedVerb(
				code: 'get',
				descriptionTemplate: 'Get a %s by identifier.',
				readOnlyHint: true,
			),
			'update' => new ResolvedVerb(
				code: 'update',
				descriptionTemplate: 'Update a %s by identifier or filter.',
			),
			'delete' => new ResolvedVerb(
				code: 'delete',
				descriptionTemplate: 'Delete a %s by identifier or filter.',
				destructiveHint: true,
			),
			'aggregate' => new ResolvedVerb(
				code: 'aggregate',
				descriptionTemplate: 'Calculate aggregate values for %s records.',
				readOnlyHint: true,
			),
			'tail' => new ResolvedVerb(
				code: 'tail',
				descriptionTemplate: 'List %s records using a cursor.',
				readOnlyHint: true,
			),
			default => null,
		};
	}
}
