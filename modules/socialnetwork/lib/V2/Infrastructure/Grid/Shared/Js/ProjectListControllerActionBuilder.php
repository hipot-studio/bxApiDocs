<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Infrastructure\Grid\Shared\Js;

use Bitrix\Main\Web\Json;
use Bitrix\Socialnetwork\Item\Workgroup\Type;
use CUtil;

final class ProjectListControllerActionBuilder
{
	private const CONTROLLER = 'BX.Socialnetwork.Project.List.Controller';

	public static function getRouteConfig(?string $entityType = null): array
	{
		return match ($entityType)
		{
			Type::Scrum->value => [
				'actionPrefix' => 'socialnetwork.v2.Scrum',
				'entityParam' => 'scrumId',
			],
			Type::Collab->value => [
				'actionPrefix' => 'socialnetwork.v2.Project',
				'entityParam' => 'projectId',
			],
			default => [
				'actionPrefix' => 'socialnetwork.v2.LegacyGroup',
				'entityParam' => 'legacyGroupId',
			],
		};
	}

	public static function buildJoinAction(
		int $entityId,
		?string $entityType = null,
		bool $stopPropagation = false,
	): string
	{
		return self::buildMethodCall(
			'joinAction',
			[$entityId, self::encodeRouteConfig($entityType)],
			$stopPropagation,
		);
	}

	public static function buildRowAction(
		string $action,
		int $entityId,
		?string $entityType = null,
		bool $stopPropagation = false,
	): string
	{
		return self::buildMethodCall(
			'rowAction',
			[Json::encode($action), $entityId, self::encodeRouteConfig($entityType)],
			$stopPropagation,
		);
	}

	public static function buildDeleteAction(int $entityId, Type $entityType): string
	{
		return self::buildMethodCall(
			'showProjectDeleteDialog',
			[$entityId, Json::encode($entityType->value)],
			false,
		);
	}

	public static function buildRowActionByRoute(
		string $action,
		int $entityId,
		string $actionPrefix,
		string $entityParam,
		bool $stopPropagation = false,
	): string
	{
		return self::buildMethodCall(
			'rowAction',
			[
				Json::encode($action),
				$entityId,
				Json::encode([
					'actionPrefix' => $actionPrefix,
					'entityParam' => $entityParam,
				]),
			],
			$stopPropagation,
		);
	}

	public static function buildBindAction(
		string $method,
		int $entityId,
		?string $entityType = null,
	): string
	{
		return sprintf(
			'%s.%s.bind(%s, %d, %s)',
			self::CONTROLLER,
			$method,
			self::CONTROLLER,
			$entityId,
			self::encodeRouteConfig($entityType),
		);
	}

	public static function buildMembersPopupAction(
		int $entityId,
		?string $entityType = null,
	): string
	{
		return sprintf(
			'%s.%s().showPopup(%d, %s, this); event.stopPropagation();',
			self::CONTROLLER,
			self::getMembersPopupGetter($entityType),
			$entityId,
			self::encodeInlineRouteConfig($entityType),
		);
	}

	public static function buildDefaultRowAction(string $viewUrl): string
	{
		return self::buildMethodCall(
			'defaultRowAction',
			[Json::encode($viewUrl)],
			false,
		);
	}

	private static function buildMethodCall(
		string $method,
		array $arguments,
		bool $stopPropagation,
	): string
	{
		$call = sprintf(
			'%s.%s(%s)',
			self::CONTROLLER,
			$method,
			implode(', ', $arguments),
		);

		return $stopPropagation
			? 'event.stopPropagation();' . $call . ';'
			: $call
		;
	}

	private static function encodeRouteConfig(?string $entityType = null): string
	{
		return Json::encode(self::getRouteConfig($entityType));
	}

	private static function encodeInlineRouteConfig(?string $entityType = null): string
	{
		$routeConfig = self::getRouteConfig($entityType);

		return sprintf(
			"{actionPrefix:'%s',entityParam:'%s'}",
			CUtil::JSEscape((string)($routeConfig['actionPrefix'] ?? '')),
			CUtil::JSEscape((string)($routeConfig['entityParam'] ?? '')),
		);
	}

	private static function getMembersPopupGetter(?string $entityType = null): string
	{
		return $entityType === Type::Scrum->value
			? 'getScrumMembersPopup'
			: 'getMembersPopup'
		;
	}
}
