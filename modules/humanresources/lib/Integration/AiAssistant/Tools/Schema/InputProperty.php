<?php

declare(strict_types=1);

namespace Bitrix\HumanResources\Integration\AiAssistant\Tools\Schema;

use Bitrix\HumanResources\Type\NodeEntityType;
use Bitrix\HumanResources\Type\NodeMemberRole;
use Bitrix\HumanResources\Type\NodeSettingsAuthorityType;
use Bitrix\HumanResources\Type\NodeSettingsType;

class InputProperty
{
	public static function nodeId(string $description = 'Node identifier'): array
	{
		return [
			'description' => $description,
			'type' => 'integer',
			'minimum' => 1,
		];
	}

	public static function colorName(): array
	{
		return [
			'description' => 'Color for the team',
			'type' => 'string',
			'enum' => ['blue', 'green', 'cyan', 'orange', 'purple', 'pink'],
		];
	}

	/**
	 * Object mapping role XML IDs to arrays of user IDs.
	 * Roles are restricted to those valid for the given node type.
	 */
	public static function userIdsByRole(NodeEntityType $type): array
	{
		$allowedRoles = NodeMemberRole::allowedValuesForNodeType($type);
		$example = $type === NodeEntityType::TEAM
			? '{"MEMBER_TEAM_HEAD":[1], "MEMBER_TEAM_EMPLOYEE":[2,3]}'
			: '{"MEMBER_HEAD":[1], "MEMBER_EMPLOYEE":[2,3]}'
		;

		$properties = [];
		foreach ($allowedRoles as $roleXmlId)
		{
			$properties[$roleXmlId] = self::userIdList();
		}

		return [
			'description' => 'Object mapping role XML IDs to arrays of user IDs. '
				. 'Allowed role keys: ' . implode(', ', $allowedRoles) . '. '
				. 'Example: ' . $example,
			'type' => 'object',
			'properties' => $properties,
			'additionalProperties' => false,
		];
	}

	/**
	 * Array of user IDs (positive integers, no duplicates).
	 */
	public static function userIdList(string $description = 'List of user IDs'): array
	{
		return [
			'description' => $description,
			'type' => 'array',
			'items' => [
				'type' => 'integer',
				'minimum' => 1,
			],
			'uniqueItems' => true,
		];
	}

	/**
	 * Array of positive integer entity IDs (chats, channels, collabs, etc).
	 */
	public static function idList(string $description): array
	{
		return [
			'description' => $description,
			'type' => 'array',
			'items' => [
				'type' => 'integer',
				'minimum' => 1,
			],
			'uniqueItems' => true,
			'default' => [],
		];
	}

	/**
	 * Settings object: NodeSettingsType keys → arrays of NodeSettingsAuthorityType values.
	 * Restricted to authority-type settings exposed via this tool surface.
	 */
	public static function nodeSettings(): array
	{
		$authorityKeys = array_map(
			fn(NodeSettingsType $t) => $t->value,
			NodeSettingsType::getCasesWithAuthorityTypeValue(),
		);
		$authorityValues = array_map(
			fn(NodeSettingsAuthorityType $a) => $a->value,
			NodeSettingsAuthorityType::cases(),
		);

		$properties = [];
		foreach ($authorityKeys as $key)
		{
			$properties[$key] = [
				'type' => 'array',
				'items' => [
					'type' => 'string',
					'enum' => $authorityValues,
				],
				'uniqueItems' => true,
			];
		}

		return [
			'description' => 'Authority settings for the node. '
				. 'Keys: ' . implode(', ', $authorityKeys) . '. '
				. 'Values: arrays of authority types (' . implode(', ', $authorityValues) . '). '
				. 'Example: {"BUSINESS_PROC_AUTHORITY":["HEAD","DEPUTY_HEAD"]}',
			'type' => 'object',
			'properties' => $properties,
			'additionalProperties' => false,
			'default' => [],
		];
	}

	/**
	 * Pagination limit with bounds.
	 */
	public static function paginationLimit(int $default, int $max): array
	{
		return [
			'description' => "Maximum number of results to return (default {$default}, max {$max})",
			'type' => 'integer',
			'minimum' => 1,
			'maximum' => $max,
			'default' => $default,
		];
	}

	public static function paginationOffset(): array
	{
		return [
			'description' => 'Number of results to skip (for pagination)',
			'type' => 'integer',
			'minimum' => 0,
			'default' => 0,
		];
	}
}
