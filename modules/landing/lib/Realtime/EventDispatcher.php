<?php

declare(strict_types=1);

namespace Bitrix\Landing\Realtime;

use Bitrix\Landing\Realtime\Enricher\SiteEventEnricher;
use Bitrix\Landing\Realtime\Recipient\InitiatorRecipientResolver;
use Bitrix\Landing\Realtime\Transport\PullTransport;

final class EventDispatcher
{
	private EventValidatorInterface $validator;
	private RecipientResolverInterface $recipientResolver;
	private TransportInterface $transport;
	/** @var EventEnricherInterface[] */
	private array $enrichers;

	public function __construct(
		?EventValidatorInterface $validator = null,
		?RecipientResolverInterface $recipientResolver = null,
		?TransportInterface $transport = null,
		?array $enrichers = null
	)
	{
		$this->validator = $validator ?? new EventValidator();
		$this->recipientResolver = $recipientResolver ?? new InitiatorRecipientResolver();
		$this->transport = $transport ?? new PullTransport();
		$this->enrichers = $enrichers ?? [
			new SiteEventEnricher(),
		];
	}

	public static function dispatch(Event $event): void
	{
		(new self())->dispatchEvent($event);
	}

	public function dispatchEvent(Event $event): void
	{
		Logger::write('dispatch.start', [
			'event' => Logger::eventToArray($event),
		]);

		$validationResult = $this->validator->validate($event);
		if (!$validationResult->isSuccess())
		{
			Logger::write('dispatch.validation_failed', [
				'event' => Logger::eventToArray($event),
				'errors' => array_map(
					static fn($error): array => [
						'code' => (string)$error->getCode(),
						'message' => (string)$error->getMessage(),
					],
					$validationResult->getErrors()
				),
			]);

			return;
		}

		foreach ($this->enrichers as $enricher)
		{
			if ($enricher->supports($event))
			{
				Logger::write('dispatch.enricher_supported', [
					'enricher' => $enricher::class,
					'event' => Logger::eventToArray($event),
				]);

				$event = $enricher->enrich($event);

				Logger::write('dispatch.enriched', [
					'enricher' => $enricher::class,
					'event' => Logger::eventToArray($event),
				]);
			}
		}

		$recipientIds = $this->recipientResolver->resolve($event);
		Logger::write('dispatch.recipients_resolved', [
			'recipientResolver' => $this->recipientResolver::class,
			'recipientIds' => $recipientIds,
			'event' => Logger::eventToArray($event),
		]);

		if (!$recipientIds)
		{
			Logger::write('dispatch.no_recipients', [
				'event' => Logger::eventToArray($event),
			]);

			return;
		}

		$this->transport->send($recipientIds, $event);
		Logger::write('dispatch.finish', [
			'recipientIds' => $recipientIds,
			'event' => Logger::eventToArray($event),
		]);
	}
}
