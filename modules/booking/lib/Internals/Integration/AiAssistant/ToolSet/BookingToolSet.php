<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Integration\AiAssistant\ToolSet;

use Bitrix\AiAssistant\Definition\Dto\DefinitionMetadataDto;
use Bitrix\AiAssistant\Definition\Dto\UsesToolsDto;
use Bitrix\AiAssistant\Definition\ToolSet\BaseToolSet;
use Bitrix\Booking\Internals\Integration\AiAssistant\Tool\CancelBookingTool;
use Bitrix\Booking\Internals\Integration\AiAssistant\Tool\ConfirmBookingTool;
use Bitrix\Booking\Internals\Integration\AiAssistant\Tool\CreateBookingByResourceAndServicesTool;
use Bitrix\Booking\Internals\Integration\AiAssistant\Tool\CreateBookingByResourceTool;
use Bitrix\Booking\Internals\Integration\AiAssistant\Tool\CreateBookingByServicesTool;
use Bitrix\Booking\Internals\Integration\AiAssistant\Tool\FindClientBookingsTool;
use Bitrix\Booking\Internals\Integration\AiAssistant\Tool\RescheduleBookingTool;
use Bitrix\Booking\Internals\Integration\AiAssistant\Tool\FindAvailableDatesByResourceTool;
use Bitrix\Booking\Internals\Integration\AiAssistant\Tool\FindAvailableDatesByServicesTool;
use Bitrix\Booking\Internals\Integration\AiAssistant\Tool\FindAvailableSlotsByResourceTool;
use Bitrix\Booking\Internals\Integration\AiAssistant\Tool\FindAvailableSlotsByServicesTool;
use Bitrix\Booking\Internals\Integration\AiAssistant\Tool\SetCallStatusTool;

class BookingToolSet extends BaseToolSet
{
	public function getCode(): string
	{
		return 'booking';
	}

	public function getMetadata(): DefinitionMetadataDto
	{
		return new DefinitionMetadataDto(
			'Booking Tool Set',
			'Public Booking Tool Set',
		);
	}

	public function getUsesTools(): UsesToolsDto
	{
		return new UsesToolsDto([
			FindAvailableDatesByResourceTool::class,
			FindAvailableDatesByServicesTool::class,
			FindAvailableSlotsByResourceTool::class,
			FindAvailableSlotsByServicesTool::class,
			CreateBookingByResourceTool::class,
			CreateBookingByServicesTool::class,
			CreateBookingByResourceAndServicesTool::class,
			RescheduleBookingTool::class,
			ConfirmBookingTool::class,
			CancelBookingTool::class,
			FindClientBookingsTool::class,
			SetCallStatusTool::class,
		]);
	}
}
