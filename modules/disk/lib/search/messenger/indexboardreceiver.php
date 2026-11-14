<?php

namespace Bitrix\Disk\Search\Messenger;

use Bitrix\Disk\Configuration;
use Bitrix\Disk\Document\Flipchart\BoardElementsResult;
use Bitrix\Disk\Document\Flipchart\BoardService;
use Bitrix\Disk\Document\Flipchart\DualMode\BoardApiServiceFactory;
use Bitrix\Disk\Document\Flipchart\DualMode\ConfigurationException;
use Bitrix\Disk\Document\Flipchart\DualMode\ServiceProfile;
use Bitrix\Disk\Document\Flipchart\DualMode\ServiceProfileResolver;
use Bitrix\Disk\Document\Flipchart\Enum\BoardIndexErrorCategory;
use Bitrix\Disk\Document\Flipchart\JwtService;
use Bitrix\Disk\Driver;
use Bitrix\Disk\File;
use Bitrix\Disk\Internals\Index\ObjectExtendedIndexTable;
use Bitrix\Disk\Internals\ObjectTable;
use Bitrix\Disk\Search\BoardTextContentExtractor;
use Bitrix\Disk\SystemUser;
use Bitrix\Disk\TypeFile;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Messenger\Entity\MessageInterface;
use Bitrix\Main\Messenger\Internals\Exception\Receiver\NoLoggableException;
use Bitrix\Main\Messenger\Receiver\AbstractReceiver;
use Bitrix\Main\ObjectException;
use Bitrix\Main\Type\DateTime;

class IndexBoardReceiver extends AbstractReceiver
{
	private ?ServiceProfile $currentProfile = null;

	protected function process(MessageInterface $message): void
	{
		if (
			!Configuration::isBoardIndexEnabled()
			|| !Configuration::allowUseExtendedFullText()
		)
		{
			return;
		}

		/** @var IndexBoardMessage $message */
		$object = ObjectTable::query()
			->setSelect(['ID', 'TYPE', 'TYPE_FILE', 'DELETED_TYPE', 'GLOBAL_CONTENT_VERSION'])
			->where('ID', $message->objectId)
			->fetch()
		;
		if (!$object)
		{
			return;
		}
		if ((int)$object['TYPE'] !== ObjectTable::TYPE_FILE)
		{
			return;
		}
		if ((int)$object['TYPE_FILE'] !== TypeFile::BOARD)
		{
			return;
		}
		if ((int)$object['DELETED_TYPE'] !== ObjectTable::DELETED_TYPE_NONE)
		{
			return;
		}

		$index = ObjectExtendedIndexTable::query()
			->setSelect(['STATUS', 'UPDATE_TIME'])
			->where('OBJECT_ID', $message->objectId)
			->fetch()
		;
		if (!$index || (int)$index['STATUS'] !== ObjectExtendedIndexTable::STATUS_BOARD_WAITING)
		{
			return;
		}

		if ($message->globalContentVersion === (int)$object['GLOBAL_CONTENT_VERSION'])
		{
			$this->processCurrentBoard($message->objectId);
		}
		elseif ($index['UPDATE_TIME'] <= (new DateTime())->add('-1 hour'))
		{
			$this->processCurrentBoard($message->objectId);
		}
	}

	protected function processCurrentBoard(int $objectId): void
	{
		$startedAt = microtime(true);
		try
		{
			$file = File::loadById($objectId)?->getRealObject();
		}
		catch (ObjectException)
		{
			return;
		}
		if (
			!$file instanceof File
			|| $file->isDeleted()
			|| (int)$file->getTypeFile() !== TypeFile::BOARD
			|| $file->getStorage() === null
		)
		{
			return;
		}

		$expectedVersion = (int)$file->getGlobalContentVersion();
		$result = $this->getBoardElements($file);
		if (!$result->isSuccess())
		{
			$this->handleBoardElementsFailure($file, $expectedVersion, $result, $startedAt);

			return;
		}

		$fragments = (new BoardTextContentExtractor())->extract($result->items);
		try
		{
			Driver::getInstance()->getIndexManager()->publishBoardIndex(
				(int)$file->getId(),
				$expectedVersion,
				$fragments,
			);
		}
		catch (\Throwable $exception)
		{
			$this->throwTemporaryFailure(
				'publish',
				$file,
				$expectedVersion,
				'database',
				$startedAt,
				$exception,
			);
		}
	}

	protected function getBoardElements(File $file): BoardElementsResult
	{
		$this->currentProfile = ServiceProfileResolver::createFromOptions()->resolveForObject($file);
		if ($this->currentProfile === null)
		{
			return BoardElementsResult::createError(BoardIndexErrorCategory::ProfileUnresolved);
		}

		$jwtService = new JwtService(SystemUser::create());
		$factory = ServiceLocator::getInstance()->get(BoardApiServiceFactory::class);
		try
		{
			$apiService = $factory->createForServerRead($this->currentProfile, $jwtService);
		}
		catch (ConfigurationException)
		{
			return BoardElementsResult::createError(BoardIndexErrorCategory::ProfileUnavailable);
		}

		return $apiService->getBoardElements(BoardService::convertDocumentIdToExternal($file->getId()));
	}

	private function handleBoardElementsFailure(
		File $file,
		int $expectedVersion,
		BoardElementsResult $result,
		float $startedAt,
	): void
	{
		$category = $result->errorCategory ?? BoardIndexErrorCategory::ElementsEnvelopeInvalid;
		if ($this->isTemporaryFailure($category, $result->httpStatus))
		{
			$this->throwTemporaryFailure('read', $file, $expectedVersion, $category->value, $startedAt);
		}

		try
		{
			$failed = Driver::getInstance()->getIndexManager()->markBoardIndexFailed(
				(int)$file->getId(),
				$expectedVersion,
			);
		}
		catch (\Throwable $exception)
		{
			$this->throwTemporaryFailure(
				'failure_state',
				$file,
				$expectedVersion,
				'database',
				$startedAt,
				$exception,
			);
		}

		$this->logDelivery(
			'read',
			$failed ? 'failed' : 'discarded',
			$file,
			$expectedVersion,
			$category->value,
			$startedAt,
		);
	}

	private function isTemporaryFailure(BoardIndexErrorCategory $category, ?int $httpStatus): bool
	{
		if (in_array($category, [
			BoardIndexErrorCategory::Transport,
			BoardIndexErrorCategory::Timeout,
			BoardIndexErrorCategory::SigningEnvelopeInvalid,
		], true))
		{
			return true;
		}
		if ($category !== BoardIndexErrorCategory::Http)
		{
			return false;
		}

		return $httpStatus === 408
			|| $httpStatus === 429
			|| $httpStatus === null
			|| $httpStatus < 400
			|| $httpStatus >= 500
		;
	}

	private function throwTemporaryFailure(
		string $stage,
		File $file,
		int $expectedVersion,
		string $category,
		float $startedAt,
		?\Throwable $previous = null,
	): never
	{
		$this->logDelivery($stage, 'retry', $file, $expectedVersion, $category, $startedAt);

		throw new NoLoggableException('Temporary board indexing failure.', 0, $previous);
	}

	private function logDelivery(
		string $stage,
		string $result,
		File $file,
		int $expectedVersion,
		string $category,
		float $startedAt,
	): void
	{
		ServiceLocator::getInstance()->get('disk.boardIndexingLogger')->warning(
			'Board indexing delivery: stage={stage}, result={result}, objectId={objectId}, '
			. 'version={version}, profile={profile}, category={category}, duration={duration}',
			[
				'stage' => $stage,
				'result' => $result,
				'objectId' => (int)$file->getId(),
				'version' => $expectedVersion,
				'profile' => $this->currentProfile?->value ?? 'unresolved',
				'category' => $category,
				'duration' => (int)round((microtime(true) - $startedAt) * 1000),
			],
		);
	}
}
