<?php

declare(strict_types=1);

namespace Bitrix\Booking\Internals\Integration\AiAssistant\Tool;

class SetCallStatusTool extends BaseBookingTool
{
	protected function execute(int $userId, ...$args): string
	{
		//@todo update b_booking_booking_message.DELIVERY_STATUS
		return 'Status has been successfully updated';
	}

	public function getName(): string
	{
		return 'set_call_status_tool';
	}

	public function getDescription(): string
	{
		return "Must be called at the end of every call to record its outcome. Pass 'success' if the client was informed or declined further calls on this matter. Pass 'failure' if the client was not reached, not informed, or uncomfortable speaking.";
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'status' => [
					'type' => 'string',
					'description' => 'Final outcome of the call.',
					'enum' => [
						//@todo
						'success',
						'failure',
					],
				],
			],
			'required' => [
				'status',
			],
		];
	}
}
