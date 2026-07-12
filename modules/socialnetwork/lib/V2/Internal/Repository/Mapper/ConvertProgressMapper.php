<?php

declare(strict_types=1);

namespace Bitrix\Socialnetwork\V2\Internal\Repository\Mapper;

use Bitrix\Socialnetwork\V2\Internal\Entity\Convert\ConvertHandlerStatus;
use Bitrix\Socialnetwork\V2\Internal\Entity\Convert\ConvertProgress;
use Bitrix\Socialnetwork\V2\Internal\Entity\Convert\ConvertStatus;
use Bitrix\Socialnetwork\V2\Internal\Entity\Convert\ConvertTrackedHandler;

class ConvertProgressMapper
{
	public const CONVERT_PREFIX = 'CONVERT';

	public const CONVERT_STATUS = 'CONVERT_STATUS';

	private const HANDLER_PREFIX = 'CONVERT_HANDLER';

	public function mapFromOptions(int $collabId, array $options): ConvertProgress
	{
		if ($options === [])
		{
			return new ConvertProgress(
				collabId: $collabId,
			);
		}

		$status = null;
		$handlerStatus = new ConvertHandlerStatus();

		foreach ($options as $option)
		{
			$optionName = (string)($option['NAME'] ?? '');
			$optionValue = (string)($option['VALUE'] ?? '');

			if ($optionName === self::CONVERT_STATUS)
			{
				$status = $this->handleStatus($optionValue);
			}

			if (str_starts_with($optionName, self::HANDLER_PREFIX))
			{
				$handlerStatus = $this->parseHandlerStatus(
					handlerStatus: $handlerStatus,
					optionName: $optionName,
					optionValue: $optionValue,
				);
			}
		}

		return new ConvertProgress(
			collabId: $collabId,
			handlerStatus: $handlerStatus,
			status: $status,
		);
	}

	public function mapToOptions(ConvertProgress $progress): array
	{
		$collabId = $progress->getCollabId();
		$status = $progress->getStatus();

		$result = [];

		if ($status !== null)
		{
			$result[] = [
				'COLLAB_ID' => $collabId,
				'NAME' => self::CONVERT_STATUS,
				'VALUE' => $status->value,
			];
		}

		foreach (ConvertTrackedHandler::cases() as $handler)
		{
			$result[] = [
				'COLLAB_ID' => $collabId,
				'NAME' => self::HANDLER_PREFIX . '_' . $handler->value,
				'VALUE' => $progress->isHandlerExecuted($handler) ? 'Y' : 'N',
			];
		}

		return $result;
	}

	private function handleStatus(string $status): ?ConvertStatus
	{
		return ConvertStatus::tryFrom($status);
	}

	private function parseHandlerStatus(
		ConvertHandlerStatus $handlerStatus,
		string $optionName,
		string $optionValue,
	): ConvertHandlerStatus
	{
		$trackedHandler = ConvertTrackedHandler::tryFrom(
			str_replace(
				self::HANDLER_PREFIX . '_',
				'',
				$optionName,
			)
		);

		if ($trackedHandler === null)
		{
			return $handlerStatus;
		}

		if ($optionValue !== 'Y')
		{
			return $handlerStatus;
		}

		$handlerStatus->markExecuted($trackedHandler);

		return $handlerStatus;
	}
}
