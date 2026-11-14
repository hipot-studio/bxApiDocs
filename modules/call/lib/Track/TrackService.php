<?php

namespace Bitrix\Call\Track;

use Bitrix\Call\Track;
use Bitrix\Call\Track\Downloader\DownloadAgent;
use Bitrix\Call\Track\Downloader\DownloadHelper;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Main\SystemException;
use Bitrix\Call;
use Bitrix\Call\Call\Registry;
use Bitrix\Call\DTO\TrackFileRequest;
use Bitrix\Call\Settings;
use Bitrix\Call\NotifyService;
use Bitrix\Call\Logger\Logger;
use Bitrix\Call\Integration\AI;
use Bitrix\Call\Integration\AI\CallAIError;
use Bitrix\Call\Integration\AI\CallAISettings;
use Bitrix\Call\Analytics\TrackAnalytics;
use Bitrix\Call\Analytics\FollowUpAnalytics;
use Bitrix\Call\Analytics\CloudRecordAnalytics;
use Bitrix\Main\Application;
use Bitrix\Main\IO\File;


final class TrackService
{
	private const DISK_ATTACH_TYPES = [
		Call\Track::TYPE_RECORD,
		Call\Track::TYPE_VIDEO_RECORD,
	];

	/**
	 * The key in the custom data of the verdict of the finalization saying that what failed was
	 * the delivery of a recording to chat and not the landing of the file
	 * {@see self::finalizeDownload()}, {@see self::isDeliveryFailure()}. The code of the verdict
	 * stays TrackError::FINALIZE_FAILED for everybody who reads codes.
	 */
	public const DELIVERY_FAILURE = 'deliveryFailure';

	/**
	 * Seconds the named lock of {@see self::findOrCreateTrack()} is held: only the insert is inside it.
	 */
	private const TRACK_LOCK_TTL = 10;

	/**
	 * Seconds the question about the address of a delivery waits for the connection
	 * {@see self::probeDeliveryAddress()}; the answer once connected is waited for
	 * {@see self::DELIVERY_PROBE_READ_TIMEOUT} more - about thirteen seconds a file at worst, about
	 * twenty-six for a set of a pack and a record.
	 *
	 * Longer than the wait for the answer, because the connection is where a live network to the
	 * storage spends its seconds: it takes in the resolving of the name of the storage and the
	 * repeats of a SYN that went unanswered, and those took a second or two of a connection on the
	 * way to the storage. A resolving that has nothing cached at all and takes some twenty seconds
	 * is not covered by it and stays a refusal worth a retry. A storage that has been connected to
	 * answers a HEAD quickly, so the wait for the answer stays short.
	 *
	 * Short, and not the timeouts of the download, because somebody is waiting for this answer
	 * and the download has nobody waiting: the operator of the media service is answered by the
	 * request itself, and the files of one set are asked about one after the other
	 * {@see \Bitrix\Call\Controller\CallController::checkRestartDelivery()}, so the timeouts of
	 * a storage that does not answer add up in the handler of that request.
	 *
	 * Short without being wrong about the file: an address that has not answered by then comes
	 * back with no status at all, and no status is refused as an address that could not be asked -
	 * {@see TrackError::DELIVERY_ADDRESS_UNCHECKED}, a refusal worth a retry - and never as a file
	 * that is not there {@see self::deliveryProbeRefusal()}. A slow storage costs the operator a
	 * second request; it never has a live recording taken for a swept one.
	 */
	public const DELIVERY_PROBE_CONNECT_TIMEOUT = 10;

	/**
	 * Seconds the question about the address of a delivery waits for the answer once connected
	 * {@see self::probeDeliveryAddress()}, {@see self::DELIVERY_PROBE_CONNECT_TIMEOUT}.
	 */
	public const DELIVERY_PROBE_READ_TIMEOUT = 3;

	private static ?TrackService $service = null;

	/**
	 * @var (\Closure(string): array{supports_range: bool, file_size: int, content_type: string,
	 *     status: int, error: ?string})|null
	 *     Seam of the HEAD the intake asks the address of a delivery by
	 *     {@see self::probeDeliveryAddress()}: replaced in tests.
	 */
	private static ?\Closure $deliveryAddressProbe = null;

	private function __construct()
	{}

	public static function getInstance(): self
	{
		if (!self::$service)
		{
			self::$service = new self();
		}
		return self::$service;
	}

	/**
	 * Whether the portal already holds the file of the track.
	 */
	public function isDownloaded(Call\Track $track): bool
	{
		return (bool)$track->getDownloaded();
	}

	/**
	 * Whether there is anything to download the file from. A not downloaded track without it
	 * is an invalid callback, not a track to process.
	 *
	 * Not to be confused with the ORM predicate Track::hasDownloadUrl(), which only answers
	 * whether the field of the entity is filled.
	 */
	public function hasDownloadSource(Call\Track $track): bool
	{
		return !empty($track->getDownloadUrl());
	}

	//region Intake

	/**
	 * Takes a file the controller service holds for the call: the row is found or created
	 * {@see self::findOrCreateTrack()} and the file goes to the route of its type.
	 *
	 * The duration and analysis checks apply to a track pack only: an audio record must still be
	 * delivered for a call whose analysis was switched off after a recording failure. A recording a
	 * user destroyed is the one case where even the record is refused.
	 *
	 * $restoreAiChain = false means "deliver, but do not relaunch the analysis and do not spend a
	 * quota on it" {@see self::checkAiTasksExecution()}.
	 *
	 * @return Result data: ['track' => Call\Track] once the row of the file exists - whatever the
	 *     verdict of the intake, because the caller reads the progress of the call from the row
	 *     existing at all {@see \Bitrix\Call\Integration\AI\CallAIService::takeFile()}. The checks
	 *     that refuse a file before its row is made carry no track.
	 */
	public function intakeServiceFile(
		\Bitrix\Call\Call $call,
		TrackFileRequest $file,
		bool $restoreAiChain = true
	): Result
	{
		$result = new Result();

		if ($call->readRecordDestroyedMark())
		{
			// The single door every file of the service comes through, so the deletion is answered
			// here once instead of at each entry point: the portal takes no file of a call whose
			// recording a user destroyed, whether the file was asked for or pushed in
			// {@see \Bitrix\Call\Call::isRecordDestroyed()}. Both types of file, as at the
			// service, where the same deletion removes the whole call from the answer
			// {@see \Bitrix\CallController\Call\Track::loadByCallUuid()}.
			//
			// The mark is read from the table and not from $call: this door stands behind a request
			// to the service, and a deletion made while that request was in flight is in the table
			// only {@see \Bitrix\Call\Call::readRecordDestroyedMark()}.
			return $result->addError(new Call\Error('record_destroyed', 'The record of the call was destroyed'));
		}

		if ($file->type === Call\Track::TYPE_TRACK_PACK)
		{
			if (!$call->isAiAnalyzeEnabled())
			{
				return $result->addError(new CallAIError(CallAIError::RECORDING_DISABLED));
			}

			if ($call->getDuration() < CallAISettings::getRecordMinDuration())
			{
				return $result->addError(new CallAIError(CallAIError::RECORD_TOO_SHORT));
			}
		}

		$intakeResult = $this->findOrCreateTrack((int)$call->getId(), $file);
		if (!$intakeResult->isSuccess())
		{
			return $result->addErrors($intakeResult->getErrors());
		}

		/** @var Call\Track $track */
		$track = $intakeResult->getData()['track'];
		$result->setData(['track' => $track]);

		if (($intakeResult->getData()['stored'] ?? false) === true && !$this->isDownloaded($track))
		{
			$this->refreshUndeliveredTrack($track, $file);
		}

		if ($this->isDownloaded($track))
		{
			return $result->addErrors($this->processDownloaded($track, $restoreAiChain)->getErrors());
		}

		if (!$this->hasDownloadSource($track))
		{
			return $result->addError(new TrackError(TrackError::EMPTY_DOWNLOAD_URL, 'Download URL undefined'));
		}

		// The analysis takes the service url, not the local file, so it starts ahead of the download.
		if ($track->getType() === Call\Track::TYPE_TRACK_PACK)
		{
			$result->addErrors($this->processTrack($track)->getErrors());
		}

		$result->addErrors($this->downloadTrackFile($track, true)->getErrors());

		return $result;
	}

	/**
	 * The row of one file of the call, found or created under a named lock shared by every intake path.
	 *
	 * b_call_track has no unique key on (CALL_ID, EXTERNAL_TRACK_ID), so this lock is the only thing
	 * keeping two paths that both miss the stored row from inserting one each. Only the row is inside
	 * the lock - download and processing stay outside, so two concurrent resends can still cost two
	 * AI tasks.
	 *
	 * @return Result data of a successful result: ['track' => Call\Track, 'stored' => bool], where
	 *     `stored` tells a row the portal already held from one just created.
	 */
	public function findOrCreateTrack(int $callId, TrackFileRequest $file): Result
	{
		$result = new Result();

		$lockName = "call_track_ready_{$callId}_{$file->trackId}";
		if (!Application::getConnection()->lock($lockName, self::TRACK_LOCK_TTL))
		{
			// Bitrix\Call\Error takes the code first, then the message.
			return $result->addError(new Call\Error('could_not_lock', 'Could not get exclusive lock'));
		}

		try
		{
			$storedTrack = $this->findStoredTrack($callId, $file->trackId);
			if ($storedTrack !== null)
			{
				return $result->setData(['track' => $storedTrack, 'stored' => true]);
			}

			$createResult = $this->createLocalTrack($callId, $file);
			if (!$createResult->isSuccess())
			{
				return $result->addErrors($createResult->getErrors());
			}

			return $result->setData(['track' => $createResult->getData()['track'], 'stored' => false]);
		}
		finally
		{
			Application::getConnection()->unlock($lockName);
		}
	}

	/**
	 * Brings a stored row whose file never arrived up to what the service reports about it now.
	 *
	 * The download url is the point of this. A row the portal holds while the service still hands
	 * out a working url for the same file is a download that was never scheduled or has given up,
	 * and an expired url is the usual reason it gave up - so taking the file again with the url of
	 * the row would only repeat the failure. The scheduler of the download is idempotent by track,
	 * so a row that is genuinely still being downloaded is not disturbed by this
	 * {@see DownloadAgent::schedule()}.
	 *
	 * The fields that merely describe the file are filled in only where the row has none: by now it
	 * may have learned them from the file itself, and the answer of the service is not more right
	 * about them than the row is.
	 */
	private function refreshUndeliveredTrack(Call\Track $track, TrackFileRequest $file): void
	{
		$changed = false;

		if ($file->url !== '' && $file->url !== $track->getDownloadUrl())
		{
			$track->setDownloadUrl($file->url);
			$changed = true;
		}

		if ($file->duration > 0 && (int)$track->getDuration() <= 0)
		{
			$track->setDuration($file->duration);
			$changed = true;
		}

		if ($file->size > 0 && (int)$track->getFileSize() <= 0)
		{
			$track->setFileSize($file->size);
			$changed = true;
		}

		$mime = \Bitrix\Main\Web\MimeType::normalize($file->mime);
		if ($mime && !$track->getFileMimeType())
		{
			$track->setFileMimeType($mime);
			$changed = true;
		}

		if (!$changed)
		{
			return;
		}

		$saveResult = $track->save();
		if (!$saveResult->isSuccess() && CallAISettings::isLoggingEnable())
		{
			Logger::getInstance()->error(
				'Unable to refresh the stored track #' . $track->getId()
				. ': ' . implode('; ', $saveResult->getErrorMessages())
			);
		}
	}

	/**
	 * The row the portal holds for one file of the call, addressed by the identifier of the service.
	 */
	public function findStoredTrack(int $callId, int $externalTrackId): ?Call\Track
	{
		return Call\Model\CallTrackTable::query()
			->setSelect(['*'])
			->where('CALL_ID', $callId)
			->where('EXTERNAL_TRACK_ID', $externalTrackId)
			->setLimit(1)
			->exec()
			->fetchObject()
		;
	}

	/**
	 * Creates the row of a file the portal does not hold yet out of the fields the service reports.
	 *
	 * @return Result data of a successful result: ['track' => Call\Track].
	 */
	private function createLocalTrack(int $callId, TrackFileRequest $file): Result
	{
		$result = new Result();

		$track = (new Call\Track)
			->setCallId($callId)
			->setExternalTrackId($file->trackId)
			->setDownloadUrl($file->url)
		;

		if (in_array($file->type, [Call\Track::TYPE_TRACK_PACK, Call\Track::TYPE_RECORD], true))
		{
			$track->setType($file->type);
		}

		$mime = \Bitrix\Main\Web\MimeType::normalize($file->mime);
		if ($mime)
		{
			$track->setFileMimeType($mime);
		}

		if ($file->name)
		{
			$track->setFileName($file->name);
		}
		$track->generateFilename();

		if ($file->duration)
		{
			$track->setDuration($file->duration);
		}

		if ($file->size)
		{
			$track->setFileSize($file->size);
		}

		$saveResult = $track->save();
		if (!$saveResult->isSuccess())
		{
			if (CallAISettings::isLoggingEnable())
			{
				Logger::getInstance()->error('Save track error: ' . implode('; ', $saveResult->getErrorMessages()));
			}

			return $result->addErrors($saveResult->getErrors());
		}

		return $result->setData(['track' => $track]);
	}

	/**
	 * Takes a new delivery of a file of the call that replaces the copy the portal holds of it: the
	 * restart of the analysis an operator asked for with the source of the recording set to a new
	 * delivery {@see \Bitrix\Call\Controller\CallController::restartFollowUpAction()}.
	 *
	 * Not an entry point of the delivery of files and not a second owner of the insert of a row:
	 * the row is found or made under the very lock every intake takes
	 * {@see self::findOrCreateTrack()}, and the two paths a file of the service arrives by - the
	 * callback and {@see self::intakeServiceFile()} - are left exactly as they are.
	 *
	 * A file the portal never held replaces nothing: the row of it is made beside the ones the call
	 * has, and the download of it is scheduled like any other. That is the behaviour of a new
	 * identifier of a track, and this path does not change it. It is not the rare path it once
	 * was: the set of a delivery carries the rows the user removed from the chat too, and a
	 * removal deletes the row whole {@see \Bitrix\Call\Track::drop()}, so a delivery of a file the
	 * portal holds no row of is ordinary traffic.
	 *
	 * Whatever the row turns out to be, the delivery is asked about before a single row is written
	 * {@see self::checkDelivery()} - the address of a file that lands on no row is worth no less
	 * than the address of one that replaces a copy, and a row made for an address that answers
	 * nothing schedules a download that can only give up, while the operator is told the job was
	 * queued.
	 *
	 * $deliveryChecked = true says the caller has already asked that question of this very file
	 * and must not have it asked twice: the intake of a set of files asks about all of them before
	 * it takes any {@see \Bitrix\Call\Controller\CallController::takeRestartDelivery()}.
	 *
	 * @return Result data: ['track' => Call\Track, 'replaced' => bool] once the row exists, where
	 *     `replaced` tells the replacement of a copy the portal held from the making of a new row.
	 */
	public function takeDeliveredFile(
		\Bitrix\Call\Call $call,
		TrackFileRequest $file,
		bool $deliveryChecked = false
	): Result
	{
		$result = new Result();

		if (!$deliveryChecked)
		{
			$checkResult = $this->checkDelivery((int)$call->getId(), $file);
			if (!$checkResult->isSuccess())
			{
				return $result->addErrors($checkResult->getErrors());
			}
		}

		$intakeResult = $this->findOrCreateTrack((int)$call->getId(), $file);
		if (!$intakeResult->isSuccess())
		{
			return $result->addErrors($intakeResult->getErrors());
		}

		/** @var Call\Track $track */
		$track = $intakeResult->getData()['track'];
		$replaced = ($intakeResult->getData()['stored'] ?? false) === true;
		$result->setData(['track' => $track, 'replaced' => $replaced]);

		if ($replaced && $file->type !== '' && $file->type !== (string)$track->getType())
		{
			// The row is found by the identifier the service gave the track, and the type says what
			// the portal does with the bytes behind it. A delivery that names another one is a
			// request about the wrong row: taken as it comes, it would empty the copy of a pack to
			// put an audio record in its place, or the other way round. Refused ahead of the
			// replacement, while the row still holds its file.
			return $result->addError(new TrackError(
				TrackError::DELIVERY_TYPE_MISMATCH,
				"The delivery of a {$file->type} names the track #{$file->trackId}, which is a "
					. (string)$track->getType()
			));
		}

		if ($replaced)
		{
			$replaceResult = $this->replaceLocalCopy($track, $file);
			if (!$replaceResult->isSuccess())
			{
				return $result->addErrors($replaceResult->getErrors());
			}
		}

		return $result->addErrors($this->downloadTrackFile($track, true)->getErrors());
	}

	/**
	 * Whether a delivery of this file can be taken at all, asked while nothing of the call has
	 * been written yet.
	 *
	 * Everything the intake of one file refuses by, and nothing else: the type the delivery names
	 * against the type of the row it finds, and the address of the delivery against the storage
	 * that is supposed to serve it. Both are questions and neither is a write, so the answer holds
	 * for a file the caller has not taken yet - which is what lets a set of files be asked about
	 * whole before the first of them replaces anything
	 * {@see \Bitrix\Call\Controller\CallController::takeRestartDelivery()}.
	 *
	 * The address is asked about whether the portal holds a row of the file or not. For a row it
	 * holds, the answer is what stands between the call and "the copy is gone and no new one is
	 * coming" {@see self::replaceLocalCopy()}. For a file it holds no row of, the row would be
	 * made and a download scheduled against an address that answers nothing: ten retries of the
	 * agent, a row that never gets a file, and an operator told the job was queued. The same two
	 * codes tell the two answers apart on both paths {@see self::deliveryProbeRefusal()}.
	 *
	 * @return Result an unsuccessful one carries the single code of the refusal.
	 */
	public function checkDelivery(int $callId, TrackFileRequest $file): Result
	{
		$result = new Result();

		$storedTrack = $this->findStoredTrack($callId, $file->trackId);
		if (
			$storedTrack !== null
			&& $file->type !== ''
			&& $file->type !== (string)$storedTrack->getType()
		)
		{
			return $result->addError(new TrackError(
				TrackError::DELIVERY_TYPE_MISMATCH,
				"The delivery of a {$file->type} names the track #{$file->trackId}, which is a "
					. (string)$storedTrack->getType()
			));
		}

		$probe = $this->probeDeliveryAddress($file->url);
		if ($probe['error'] !== null)
		{
			if (CallAISettings::isLoggingEnable())
			{
				Logger::getInstance()->error(
					'The address of the delivery of the track #' . $file->trackId
					. ' does not answer: ' . $probe['error']
				);
			}

			return $result->addError(new TrackError(
				$this->deliveryProbeRefusal((int)($probe['status'] ?? 0)),
				'The file of the delivery is not available at ' . $file->url . ': ' . $probe['error']
			));
		}

		return $result;
	}

	/**
	 * Empties the local copy of the file of a row the portal already holds and points the row at
	 * the delivery that replaces it.
	 *
	 * The row stays its own - the identifier of the track, the chat link and the file of the chat
	 * Disk are all fastened to it, and dropping the row {@see \Bitrix\Call\Track::drop()} would take
	 * the recording out of the chat and ask the service to forget the track besides. The file of the
	 * chat Disk is left pointing at the copy the chat serves today, so until the new copy has landed
	 * the call keeps showing the recording it had; the new copy is written into that same file as a
	 * new version once it is here {@see \Bitrix\Call\Track::attachToDisk()}.
	 *
	 * A pack has no file of the Disk behind it and is addressed by its row and by nothing else, so
	 * the row is what keeps its previous copy alive: the field is left naming the file the portal
	 * holds until the new one lands, and only then does the old one go
	 * {@see self::dropReplacedFile()}. The row says it is a replacement in flight by the two marks
	 * beside it - no landing and an address to fetch from {@see self::awaitsReplacement()} - and
	 * everything that would otherwise read the field as "the copy is here" asks that question
	 * instead: the scheduler of the download {@see self::downloadTrackFile()}, the attach of the
	 * landing {@see self::finalizeDownload()} and the cleanup of the mixer
	 * {@see TrackDeletionService::areAiTracksDownloaded()}. The analysis never looked at the field
	 * at all - it takes the address of the delivery for as long as the row has one
	 * {@see \Bitrix\Call\Integration\AI\Task\TranscribeCallRecord::getAIPayload()}.
	 *
	 * For everything else the mark of the local file goes here, ahead of the download being
	 * scheduled at all, and the previous copy is kept alive by the file of the chat Disk that
	 * names it. It has to go: the file of the Disk and the file of the row being different ones is
	 * the whole of how the landing knows it owes the chat a new version
	 * {@see self::replacesDiskContent()}.
	 *
	 * What the delivery says about the file is applied wherever the delivery says it, and not only
	 * where the row has nothing - that is the whole difference from the refresh of a row whose file
	 * never arrived {@see self::refreshUndeliveredTrack()}, which must not overwrite what the row
	 * has learned from a file it holds. A field the delivery is silent about is left alone: silence
	 * is not a value.
	 *
	 * Ahead of all of it the address of the delivery is asked whether it answers at all
	 * {@see self::checkDelivery()}, and a replacement whose address does not is refused before a
	 * single field of the row is touched - which is why the question is asked there and not here,
	 * one step before the row is even looked up. The address of a new delivery is the address the
	 * portal downloaded by the first time - the service does not build the recording anew - so on a
	 * call old enough for the retention of the service to have swept it the whole of "replace" is
	 * "throw the copy away and fetch nothing": the agent would take ten 404s and give up. What it
	 * leaves behind is a row with an address and no landing, and the copy the portal held is still
	 * there for as long as that lasts - the answer of the address is a promise that the file can
	 * be fetched, and nothing is destroyed on a promise.
	 */
	private function replaceLocalCopy(Call\Track $track, TrackFileRequest $file): Result
	{
		$result = new Result();

		$track
			->setFileId($this->keepsFileUntilLanding($track) ? $track->getFileId() : null)
			->setTempPath(null)
			->setDownloaded(false)
			->setDownloadUrl($file->url)
		;

		$mime = \Bitrix\Main\Web\MimeType::normalize($file->mime);
		if ($mime)
		{
			$track->setFileMimeType($mime);
		}

		if ($file->duration > 0)
		{
			$track->setDuration($file->duration);
		}

		if ($file->size > 0)
		{
			$track->setFileSize($file->size);
		}

		$saveResult = $track->save();
		if (!$saveResult->isSuccess())
		{
			if (CallAISettings::isLoggingEnable())
			{
				Logger::getInstance()->error(
					'Unable to replace the local copy of the track #' . $track->getId()
					. ': ' . implode('; ', $saveResult->getErrorMessages())
				);
			}

			return $result->addErrors($saveResult->getErrors());
		}

		return $result;
	}

	/**
	 * Under which code a replacement is refused by the probe of its address.
	 *
	 * The check itself stays as strict as it was: anything but a 200 refuses, and narrowing that
	 * to 404 and 410 is not on the table - a storage that speaks the S3 protocol answers 403 for
	 * an object that is not there, and the price of letting one through is a pack of tracks
	 * destroyed with no way back. What the code decides is not whether to refuse but what the
	 * operator of the media service is told, and those are two different things to be told:
	 *
	 * - the storage answered and the recording is not to be had (4xx, and any other answer that
	 *   is about the object): asking again changes nothing;
	 * - the address could not be asked at all - no answer (status zero) or an answer about the
	 *   storage itself rather than about the file (5xx): the same refusal, but worth a retry.
	 *
	 * @param int $status the http status of the HEAD, zero where it was never answered
	 */
	private function deliveryProbeRefusal(int $status): string
	{
		return $status > 0 && $status < 500
			? TrackError::DELIVERY_FILE_GONE
			: TrackError::DELIVERY_ADDRESS_UNCHECKED
		;
	}

	/**
	 * Asks the address of a delivery whether it answers, by the very HEAD the download of a file
	 * takes before it chooses how to fetch it {@see DownloadHelper::checkRangeSupport()},
	 * {@see \Bitrix\Call\Track\Downloader\DownloaderFactory::create()}. Anything other than 200
	 * comes back as `error`. The HEAD is the same, its timeouts are not: this one is asked while
	 * the operator waits, and waits for {@see self::DELIVERY_PROBE_CONNECT_TIMEOUT} to connect and
	 * {@see self::DELIVERY_PROBE_READ_TIMEOUT} to be answered, and no longer.
	 *
	 * The whole answer is passed on as the helper builds it, `status` included: the code of the
	 * refusal is chosen by that status {@see self::deliveryProbeRefusal()} and an answer without
	 * it reads as an address that was never asked. A replacement of this probe owes the caller
	 * every field of that shape for the same reason.
	 *
	 * A seam of one call: the tests of the replacement drive rows whose addresses point nowhere,
	 * and a probe going out for real would turn every one of them into a timeout. What is replaced
	 * is the question, never the answer to it - the refusal, its code and everything the row is
	 * spared by it stay the real ones.
	 *
	 * @return array{supports_range: bool, file_size: int, content_type: string, status: int,
	 *     error: ?string}
	 */
	private function probeDeliveryAddress(string $url): array
	{
		if (self::$deliveryAddressProbe !== null)
		{
			return (self::$deliveryAddressProbe)($url);
		}

		return DownloadHelper::checkRangeSupport(
			$url,
			self::DELIVERY_PROBE_CONNECT_TIMEOUT,
			self::DELIVERY_PROBE_READ_TIMEOUT
		);
	}

	/**
	 * The file the row has just stopped pointing at, once the replacement is written.
	 *
	 * Removed for a pack and kept for everything else, and the difference is who else addresses
	 * the file. A record is shown in the chat by a file of the chat Disk, and that file names the
	 * very file of the portal the row has just let go of: it is what the chat serves until the new
	 * copy lands and is poured into it as a new version {@see \Bitrix\Call\Track::attachToDisk()},
	 * so removing it would empty the chat for the whole length of the download - the one thing the
	 * replacement exists not to do. A preview is addressed by the record its image was bound to
	 * {@see self::processVideoRecordWithPreview()} and is not the row's to remove either.
	 *
	 * A pack is addressed by the row and by nothing else: no file of the Disk, no link in the
	 * chat, no binding of a preview, and the field that named it is a plain integer with no history
	 * behind it. Left where it is once the row has let go of it, it is a file the module can never
	 * reach again and never clean - the deletion of the call takes the file the row names at that
	 * moment {@see \Bitrix\Call\Track::drop()}, which by then is the new one.
	 *
	 * So it goes here, and here is the landing of the replacement and not the saving of its
	 * address {@see self::finalizeDownload()}. Between those two lies the whole download, which
	 * can still give up: the HEAD of the intake answers "the address is alive now"
	 * {@see self::checkDelivery()} and not "the file will arrive", and a row whose copy was
	 * destroyed on that promise is a call with no pack and no way back to one. Until the landing
	 * the row keeps naming the old file, which is the same rule the record of the chat lives by
	 * with its file of the Disk {@see self::replaceLocalCopy()}.
	 */
	private function dropReplacedFile(Call\Track $track, int $replacedFileId): void
	{
		if (
			$replacedFileId <= 0
			|| $replacedFileId === (int)$track->getFileId()
			|| $track->getType() !== Call\Track::TYPE_TRACK_PACK
		)
		{
			return;
		}

		\CFile::Delete($replacedFileId);
	}

	/**
	 * Whether the row of a replacement keeps naming the copy the portal holds until the new one
	 * lands, instead of letting go of it at once {@see self::replaceLocalCopy()}.
	 *
	 * True for the one type nothing else addresses. A record and a video record are held by the
	 * file of the chat Disk that names them, and that file has to stop matching the row for the
	 * landing to know it owes the chat a new version {@see self::replacesDiskContent()} - so for
	 * them the field is emptied and the Disk does the keeping.
	 */
	private function keepsFileUntilLanding(Call\Track $track): bool
	{
		return $track->getType() === Call\Track::TYPE_TRACK_PACK;
	}

	/**
	 * Whether the file this row names is the copy a replacement is on its way to push out, rather
	 * than a file that has landed.
	 *
	 * Three marks and all three are needed. A file, or there is nothing being kept. No landing,
	 * which the landing itself writes {@see \Bitrix\Call\Track::claimDownloadedFile()}, so a row
	 * that has been through one answers "no" and every pass after the replacement - the retry of
	 * the agent included - reads it as an ordinary stored row. And an address to fetch from,
	 * because that is the delivery that is coming.
	 *
	 * The combination belongs to a replacement of a pack in flight and to nothing else: every
	 * other way a row gets a file writes the landing in the same statement.
	 */
	public function awaitsReplacement(Call\Track $track): bool
	{
		return (int)$track->getFileId() > 0
			&& $track->getDownloaded() !== true
			&& !empty($track->getDownloadUrl())
		;
	}

	//endregion

	/**
	 * Processing of a file the portal already holds, for the operator who asked for the repeat.
	 *
	 * A type that lives on chat Disk goes through finalization: its delivery (Disk attach, chat
	 * link, audio-record fallback message) is there and not in processTrack(). finalizeDownload()
	 * is idempotent and calls processTrack() itself, so processing still happens exactly once.
	 *
	 * The need of Disk is the whole rule here, and it is narrower than the one the agent of a
	 * downloaded track follows {@see self::requiresFinalizeRetry()}: a repeat asked for a preview
	 * goes to processTrack() directly, so the records of the call are delivered - that loop is
	 * inside processTrack() {@see self::processCloudVideoTrack()} - but a delivery that fails
	 * there leaves neither the mark nor the line of the finalization. The operator is not left
	 * without an answer by that: the failure travels to the caller in the verdict below, and the
	 * marks of the delivery itself {@see NotifyService::sendRecordingReadyMessage()} are sent from
	 * inside it whatever the path.
	 *
	 * $restoreAiChain = false keeps an operator-driven resend of a stored audio record to what
	 * the operator asked for - the delivery to chat - without relaunching the AI chain and
	 * spending a quota on it {@see self::checkAiTasksExecution()}.
	 *
	 * $knownPreview binds a stored cloud record to the preview that came with it instead of the
	 * newest preview of the call: a call may hold several pairs {@see self::resolvePreview()}.
	 *
	 * Everything reaching this method is a repeat of a file the portal already holds, and the
	 * pipeline is told so: the failure of the processing is answered to the caller instead of
	 * staying telemetry only, and the mixer cleanup of the call is left to the delivery that
	 * downloaded the files {@see self::finalizeDownload()}, {@see self::processCloudVideoTrack()}.
	 */
	public function processDownloaded(
		Call\Track $track,
		bool $restoreAiChain = true,
		?Call\Track $knownPreview = null
	): Result
	{
		if ($this->requiresDisk($track))
		{
			return $this->finalizeDownload($track, $restoreAiChain, $knownPreview, resendOfStoredFile: true);
		}

		return $this->processTrack($track, $knownPreview, repeatOverStoredFile: true);
	}

	/**
	 * Whether the track type must be attached to chat Disk (audio/video records).
	 */
	public function requiresDisk(Call\Track $track): bool
	{
		return in_array($track->getType(), self::DISK_ATTACH_TYPES, true);
	}

	/**
	 * Whether a pass over an already downloaded track can still have finalization work to do, so
	 * the agent of the track re-finalizes instead of stopping
	 * {@see \Bitrix\Call\Track\Downloader\DownloadAgent::run()}.
	 *
	 * The attach to disk used to be the whole answer, and it is not: the delivery of the recording
	 * to chat is finalization work too, and it does not belong to the track that carries the file.
	 * On the regular order of a cloud recording the record lands first and waits for the frame
	 * {@see self::isPreviewDownloadInProgress()}, so the pass that delivers every record of the
	 * call is the pass of the preview - and a preview needs no disk at all. Answering by the disk
	 * alone left that order with no repeat of the delivery whatsoever: the agent of the preview
	 * would find the file in place and take itself off the schedule.
	 */
	public function requiresFinalizeRetry(Call\Track $track): bool
	{
		return $this->requiresDisk($track) || $track->getType() === Call\Track::TYPE_VIDEO_PREVIEW;
	}

	/**
	 * Whether the file of the track lies on the portal in the shape the finalization asks for it:
	 * attached as a file, and attached to Disk when the type of the track needs Disk. The same
	 * question {@see \Bitrix\Call\Track\Downloader\AbstractDownloader::complete()} asks before it
	 * reports a download finished, asked from outside so the pipeline can tell a download that
	 * failed from a finalization that did.
	 *
	 * The file a row keeps naming while a replacement is on its way to push it out is not an
	 * answer to this question {@see self::awaitsReplacement()}: it is the previous copy, held for
	 * the chat and for the analysis until the new one lands, and the file this pass is about has
	 * not reached the portal at all.
	 */
	public function isStoredOnPortal(Call\Track $track): bool
	{
		if ($this->awaitsReplacement($track))
		{
			return false;
		}

		return (bool)$track->getFileId() && (!$this->requiresDisk($track) || (bool)$track->getDiskFileId());
	}

	/**
	 * Whether the finalization of this pass writes the content of a file of the chat Disk the row
	 * already has, instead of making that file or reusing it as it is
	 * {@see \Bitrix\Call\Track::attachToDisk()}.
	 *
	 * Answered by comparing the two files and kept in no field of its own: the file of the chat
	 * Disk names the file of the portal it serves, and while that is not the file the row holds,
	 * the chat is still serving the previous recording. A write of a version brings the two
	 * together - `Disk\File::updateContent()` stores the new `FILE_ID` - and so does the creation
	 * of the file of the Disk, which `\CIMDisk::UploadFileFromMain()` makes over the very file of
	 * the row. So the answer is `false` on the regular order and on every pass after a successful
	 * replacement, and `true` for as long as the replacement owes the chat its content.
	 *
	 * Asked this way and not by "the row holds no local file": that mark is gone by the time it
	 * would be read again. A pass that fails at the Disk has already attached the local file and
	 * saved the row {@see \Bitrix\Call\Track::attachTempFile()}, so the retry of the agent would
	 * find the mark false, reuse the file of the chat untouched and report a delivery that never
	 * happened - the chat left with the old recording for good.
	 */
	private function replacesDiskContent(Call\Track $track): bool
	{
		$diskFileId = (int)$track->getDiskFileId();
		if (!$this->requiresDisk($track) || $diskFileId <= 0 || !Loader::includeModule('disk'))
		{
			return false;
		}

		$diskFile = \Bitrix\Disk\File::getById($diskFileId);

		// A file of the chat that cannot be read answers "no": the two cannot be told apart, and a
		// write of a version into a file that is not there would only turn an unanswerable
		// question into a failure of every pass over the row.
		return $diskFile instanceof \Bitrix\Disk\File
			&& (int)$diskFile->getFileId() !== (int)$track->getFileId()
		;
	}

	/**
	 * Whether the file the data of an AI task will be built from is really there.
	 *
	 * The payload of the transcription tells two sources apart - the url of the service for a file
	 * that has not landed yet, and the file of the portal for one that has
	 * {@see \Bitrix\Call\Integration\AI\Task\TranscribeCallRecord::getAIPayload()} - and the
	 * question is asked about the one that payload will take, not about whichever field of the row
	 * happens to be filled. Of the two only the local file can be checked without going anywhere,
	 * and only the local file needs checking: the module has no handler of a file a user deletes
	 * from the chat, so a row may name a file the portal no longer holds.
	 *
	 * The url of the service is not checked by going out to it, and it does not have to be: the
	 * question the row answers about it is whether anybody is still fetching by that url
	 * {@see self::hasLiveDownloadSource()}.
	 */
	public function hasLiveTaskSource(Call\Track $track): bool
	{
		if (!$this->isDownloaded($track) && $this->hasDownloadSource($track))
		{
			return $this->hasLiveDownloadSource($track);
		}

		$fileId = (int)$track->getFileId();

		return $fileId > 0 && !empty(\CFile::GetFileArray($fileId));
	}

	/**
	 * Whether the url a row holds instead of a file is still being fetched by somebody.
	 *
	 * "An address and no file" is not one state but two, and they look the same in the row: a
	 * download on its way, and a download that gave up. The agent takes its retries and takes
	 * itself off the schedule {@see \Bitrix\Call\Track\Downloader\DownloadAgent::scheduleRetry()},
	 * and what it leaves behind is the very url it was never able to fetch - nothing in the row
	 * records the surrender. So the schedule is asked instead, exactly as the wait of a recording
	 * asks it {@see AI\CallAIService::audioRecordOnItsWay()}: while an agent stands the file is on
	 * its way, and once none does the url addresses nothing this side will ever get.
	 *
	 * Taking the url for a source regardless is what turns a surrendered download into a task of
	 * the analysis built on a dead address: the quota is spent and the operator is answered by the
	 * failure of an engine instead of by "the recording is not there".
	 */
	private function hasLiveDownloadSource(Call\Track $track): bool
	{
		return DownloadAgent::hasScheduledAgent((int)$track->getId());
	}

	/**
	 * Whether a failed finalization failed at the publication of a recording in chat, and not at
	 * putting the file on the portal {@see self::DELIVERY_FAILURE}.
	 *
	 * Asked by the agent about the pass it is closing: only this class of failure has a row and a
	 * mark of its own, and only it may therefore be left out of the row of the download
	 * {@see \Bitrix\Call\Track\Downloader\DownloadAgent::reportFailedDownload()}. Everything else
	 * the finalization answers - the attach of the file, the attach to Disk, the chat link an
	 * audio record is published by - is the file failing to land, and the row of the download is
	 * the only place it is visible in.
	 *
	 * The verdict of a download carries the same mark when the finalization of that download
	 * raised it {@see \Bitrix\Call\Track\Downloader\AbstractDownloader::complete()}, so the answer
	 * does not depend on which pass of the agent asks.
	 */
	public function isDeliveryFailure(Result $result): bool
	{
		foreach ($result->getErrors() as $error)
		{
			$customData = $error->getCustomData();
			if (is_array($customData) && ($customData[self::DELIVERY_FAILURE] ?? false) === true)
			{
				return true;
			}
		}

		return false;
	}

	public function doNeedNeedAttachToDisk(Call\Track $track): bool
	{
		// Note: the !diskFileId gate is intentionally omitted — attachToDisk is idempotent
		// (reuses an existing diskFileId and never duplicates the chat link), so re-running it
		// on retry lets a failed-only chat delivery (e.g. link_save_failed) self-heal.
		return
			$this->requiresDisk($track)
			&& $track->getFileId()
		;
	}

	public function doNeedNeedAiProcessing(Call\Track $track): bool
	{
		if ($track->getType() != Call\Track::TYPE_TRACK_PACK)
		{
			return false;
		}

		$minDuration = \Bitrix\Call\Integration\AI\CallAISettings::getRecordMinDuration();
		if ($track->getDuration() > 0 && $track->getDuration() < $minDuration)
		{
			return false;
		}

		$taskList = Call\Model\CallAITaskTable::query()
			->setSelect(['ID'])
			->where('TRACK_ID', $track->getId())
			->setLimit(1)
			->exec()
		;

		return $taskList->getSelectedRowsCount() == 0;
	}

	/**
	 * Create default preview track from static file
	 *
	 * @param int $callId Call ID
	 * @return Result Contains created Track in data['track'] on success
	 */
	public function createDefaultPreview(int $callId): Result
	{
		$result = new Result();

		$log = CallAISettings::isLoggingEnable();
		$logger = Logger::getInstance();

		$log && $logger->info("TrackService::createDefaultPreview: Starting. CallId: {$callId}");

		$existingPreview = Track::getTrackForCall($callId, Track::TYPE_VIDEO_PREVIEW);
		if ($existingPreview)
		{
			$log && $logger->info("TrackService::createDefaultPreview: Preview already exists. CallId: {$callId}");
			return $result->addError(new TrackError(
				TrackError::PREVIEW_ALREADY_EXISTS,
				'Preview track already exists for this call'
			));
		}

		$previewFileName = Settings::isCisRegion() ? 'preview_ru.webp' : 'preview_en.webp';
		$previewPath = Application::getDocumentRoot() . '/bitrix/images/call/cloud/' . $previewFileName;
		if (!File::isFileExists($previewPath))
		{
			$log && $logger->error("TrackService::createDefaultPreview: Static file not found: {$previewPath}");
			return $result->addError(new TrackError(
				TrackError::STATIC_PREVIEW_NOT_FOUND,
				'Static preview file not found: ' . $previewPath
			));
		}

		$track = (new Call\Track)
			->setCallId($callId)
			->setType(Call\Track::TYPE_VIDEO_PREVIEW)
			->setDownloadUrl('')
			->setFileName($this->defaultPreviewFileName($callId))
			->setFileMimeType('image/webp')
			->setDownloaded(false)
		;

		// Save first — makes the row visible to concurrent requests
		$saveResult = $track->save();
		if (!$saveResult->isSuccess())
		{
			$log && $logger->error("TrackService::createDefaultPreview: Could not save track. CallId: {$callId}");
			return $result->addErrors($saveResult->getErrors());
		}

		$attachResult = $track->attachFileFromPath($previewPath, 'image/webp');
		if (!$attachResult->isSuccess())
		{
			$log && $logger->error("TrackService::createDefaultPreview: Could not attach file. CallId: {$callId}");
			$track->delete();
			return $result->addErrors($attachResult->getErrors());
		}

		$track->setDownloaded(true);
		$track->save();

		$fileId = $track->getFileId();
		$log && $logger->info("TrackService::createDefaultPreview: Success. CallId: {$callId}, FileId: {$fileId}");

		return $result->setData(['track' => $track]);
	}

	public function processTrack(
		Call\Track $track,
		?Call\Track $knownPreview = null,
		bool $repeatOverStoredFile = false
	): Result
	{
		$result = new Result();

		if ($log = \Bitrix\Call\Integration\AI\CallAISettings::isLoggingEnable())
		{
			$logger = Logger::getInstance();
			$logger->info(
				"Call track file: {$track->getFileName()}"
				. ", size:{$track->getFileSize()}b"
				. ", type:{$track->getType()}"
				. ", duration:{$track->getDuration()}"
				. ", source:{$track->getDownloadUrl()}"
				. ", url:{$track->getUrl()}"
			);
		}

		$event = $this->fireTrackReadyEvent($track);
		if (
			($eventResult = $event->getResults()[0] ?? null)
			&& $eventResult instanceof EventResult
			&& $eventResult->getType() == EventResult::ERROR
		)
		{
			$log && $logger->info('Processing track was canceled by event. TrackId:'.$track->getId());

			return $result;// cancel processing by event
		}

		if (in_array($track->getType(), [Call\Track::TYPE_VIDEO_RECORD, Call\Track::TYPE_VIDEO_PREVIEW], true))
		{
			return $this->processCloudTrack($track, $knownPreview, $repeatOverStoredFile);
		}

		return $this->processAiTrack($track, $result);
	}

	/**
	 * Process cloud recording track based on MIME type
	 *
	 * Routes to appropriate handler: video/image -> processCloudVideoTrack,
	 * audio -> processAudioTrack, unknown -> error
	 *
	 * @param Call\Track $track Cloud track to process
	 * @return Result
	 */
	public function processCloudTrack(
		Call\Track $track,
		?Call\Track $knownPreview = null,
		bool $repeatOverStoredFile = false
	): Result
	{
		if (
			str_starts_with($track->getFileMimeType(), 'video')
			|| str_starts_with($track->getFileMimeType(), 'image')
		)
		{
			return $this->processCloudVideoTrack($track, $knownPreview, $repeatOverStoredFile);
		}

		if (str_starts_with($track->getFileMimeType(), 'audio'))
		{
			return $this->processCloudAudioTrack($track);
		}

		// Unknown/unsupported MIME type
		$result = new Result();

		if ($log = CallAISettings::isLoggingEnable())
		{
			$logger = Logger::getInstance();
			$logger->error("Unsupported MIME type for cloud track. TrackId: {$track->getId()}, MimeType: {$track->getFileMimeType()}");
		}

		$call = Registry::getCallWithId($track->getCallId());
		if ($call)
		{
			(new CloudRecordAnalytics($call))
				->sendTelemetry(
					source: $track,
					status: 'error',
					errorCode: 'unsupported_mime_type',
					event: 'cloud_track_processing_error',
				);
		}

		return $result->addError(new \Bitrix\Main\Error(
			"Unsupported MIME type: {$track->getFileMimeType()}",
			'UNSUPPORTED_MIME_TYPE'
		));
	}

	/**
	 * Resolve a usable preview for the call.
	 *
	 * - Preview ready → return it
	 * - Preview stuck → delete and create default
	 * - No preview → create default, return it (or null on failure)
	 */
	private function resolvePreview(int $callId, ?Track $knownPreview = null): ?Track
	{
		if ($log = CallAISettings::isLoggingEnable())
		{
			$logger = Logger::getInstance();
		}

		$preview = $knownPreview ?? Track::getTrackForCall($callId, Track::TYPE_VIDEO_PREVIEW);

		if ($preview && $preview->getDownloaded() && $preview->getFileId())
		{
			return $preview;
		}

		if ($preview)
		{
			$log && $logger->warning("Preview not ready. Deleting stuck preview. CallId: {$callId}, TrackId: {$preview->getId()}");
			$preview->delete();
		}

		$log && $logger->info("No preview track found. Creating default. CallId: {$callId}");

		$previewResult = $this->createDefaultPreview($callId);
		if ($previewResult->isSuccess())
		{
			return $previewResult->getData()['track'];
		}

		return null;
	}

	protected function processCloudVideoTrack(
		Call\Track $track,
		?Call\Track $knownPreview = null,
		bool $repeatOverStoredFile = false
	): Result
	{
		$result = new Result();

		if ($log = CallAISettings::isLoggingEnable())
		{
			$logger = Logger::getInstance();
		}

		$log && $logger->info("Processing cloud video track. TrackId: {$track->getId()}, Type: {$track->getType()}");

		if (!Loader::includeModule('im'))
		{
			$log && $logger->error("IM module not loaded. TrackId: {$track->getId()}");
			return $result->addError(new \Bitrix\Main\Error('IM module not loaded', 'IM_MODULE_NOT_LOADED'));
		}

		$callId = $track->getCallId();

		if ($track->getType() === Track::TYPE_VIDEO_PREVIEW)
		{
			$records = Track::getTracksForCall($callId, Track::TYPE_VIDEO_RECORD);
		}
		else
		{
			if (!$track->getDownloaded() || !$track->getFileId())
			{
				$log && $logger->info("Record not ready yet. TrackId: {$track->getId()}, Downloaded: "
					. ($track->getDownloaded() ? 'yes' : 'no'));
				return $result;
			}

			$records = [$track];
		}

		// A ready preview named by the caller completes the pair on its own, so a download in
		// progress belongs to another pair of the same call and this delivery must not wait for
		// it: the resend of a stored pair would answer success without ever reaching chat.
		$pairIsComplete = $knownPreview && $knownPreview->getDownloaded() && $knownPreview->getFileId();

		// The preview may still be downloading in parallel with the record. Don't treat it as
		// stuck (resolvePreview would delete it and substitute a default) — wait instead. The
		// preview's own download completion re-enters this method and delivers the records with
		// the real preview. Only a preview named by the caller narrows the question to its own
		// pair; the regular pipeline asks about the newest preview of the call even while
		// processing a preview of its own, because the delivery below binds one preview to every
		// record of the call and the cleanup then takes the files of the call off the mixer.
		$previewDownloadInProgress = $this->isPreviewDownloadInProgress($callId, $knownPreview);
		if ($previewDownloadInProgress && !$pairIsComplete)
		{
			$log && $logger->info("Preview still downloading, waiting before delivery. CallId: {$callId}");
			return $result;
		}

		// The preview of this very pair when the caller knows it - an operator-driven resend of a
		// stored pair does. Otherwise the call has one preview to bind and resolvePreview() takes
		// it; on a call with several pairs that is the newest one, not the pair of this record.
		$knownPreview ??= $track->getType() === Track::TYPE_VIDEO_PREVIEW ? $track : null;

		// May be null only if a default preview could not be created (e.g. static image missing).
		// In that case still deliver the record below — without a preview image — so the video
		// does not silently vanish from chat.
		$preview = $this->resolvePreview($callId, $knownPreview);

		// A preview completing its download has every record of the call to bind to - there is no
		// stored link between a record and its preview {@see self::resolvePreview()}. On a call
		// with one record that is right. On a call with several, an image already bound came from
		// the delivery of its own pair, and this preview would replace it with a frame of another
		// recording, so the binding that is already there stands. The delivery of the record is
		// not skipped with it - the claim of its chat message answers for that one.
		$keepBoundPreviews = count($records) > 1;

		// The default preview is the exception to that: it stands in for a call that had no
		// preview at all when the record was delivered, not for a frame of the record's own pair.
		// Kept, it would leave the record showing a placeholder for good - before the rule above
		// existed, the next preview of the call replaced it.
		$defaultPreviewFileId = $keepBoundPreviews ? $this->defaultPreviewFileId($callId) : 0;

		foreach ($records as $record)
		{
			if ($record->getDownloaded() && $record->getFileId())
			{
				$keepBoundImage = $keepBoundPreviews
					&& $this->hasRealPreviewImage($record, $defaultPreviewFileId)
				;

				// The loop is not broken on a failure: every record of the call has a message and
				// a claim of its own, and the one that did go through must not be undone by the
				// one that did not. The failures travel together and the finalization reads them
				// {@see self::finalizeDownload()}.
				$result->addErrors(
					$this->processVideoRecordWithPreview($record, $preview, $callId, $keepBoundImage)->getErrors()
				);
			}
		}

		// The cleanup drops the expectation agent of the call and the files of the call on the
		// mixer, so it stays out of a delivery that went ahead of a download still running: the
		// completion of that download runs the cleanup itself. A repeat over stored files has
		// nothing left to clean - the mixer lost them when they were downloaded, and the drop of
		// an already dropped track is not free: EXTERNAL_TRACK_ID stays on the row, so every
		// repeat would ask the controller again, once per cloud track of the call and
		// synchronously {@see TrackDeletionService::deleteCloudTracksFromMixer()}. That is a
		// price per callback of an operator-driven repeat, and a price per pass of the agent that
		// re-finalizes a delivery which failed - up to DownloadAgent::MAX_RETRY_COUNT of them.
		if (!$previewDownloadInProgress && !$repeatOverStoredFile)
		{
			$this->cleanupIfAllRecordsProcessed($callId);
		}

		return $result;
	}

	/**
	 * Whether the video preview this delivery depends on exists but is still being downloaded by
	 * an agent - the preview of the pair when the caller names one, the newest preview of the call
	 * otherwise. The distinction is the whole point on a call with several pairs: getTrackForCall()
	 * answers with the newest preview, so a pair whose own preview is still downloading would be
	 * declared ready to deliver and would borrow the image of another pair.
	 */
	private function isPreviewDownloadInProgress(int $callId, ?Track $knownPreview = null): bool
	{
		$preview = $knownPreview ?? Track::getTrackForCall($callId, Track::TYPE_VIDEO_PREVIEW);

		return $preview
			&& !$preview->getDownloaded()
			&& CloudRecordExpectationAgent::hasDownloadAgentForTrack($preview->getId())
		;
	}

	/**
	 * @return Result the outcome of the delivery of this record to chat; the binding of the
	 *         preview above it is not part of that verdict - a record without its frame is still
	 *         delivered {@see NotifyService::sendRecordingReadyMessage()}.
	 */
	protected function processVideoRecordWithPreview(
		Call\Track $record,
		?Call\Track $preview,
		int $callId,
		bool $keepBoundImage = false
	): Result
	{
		if ($log = CallAISettings::isLoggingEnable())
		{
			$logger = Logger::getInstance();
		}

		if (!$preview)
		{
			$log && $logger->warning("Delivering record without preview. RecordFileId: {$record->getFileId()}, CallId: {$callId}");
		}
		elseif ($keepBoundImage)
		{
			$log && $logger->info("Record already carries a preview, keeping it. RecordFileId: {$record->getFileId()}, CallId: {$callId}");
		}
		else
		{
			$log && $logger->info("Attaching preview to record. RecordFileId: {$record->getFileId()}, PreviewFileId: {$preview->getFileId()}");

			(new \Bitrix\Main\UI\Viewer\PreviewManager())->setPreviewImageId(
				$record->getFileId(),
				$preview->getFileId()
			);
		}

		$call = Registry::getCallWithId($callId);
		if ($call)
		{
			$log && $logger->info("Sending recording ready message. CallId: {$callId}, RecordId: {$record->getId()}");

			return NotifyService::getInstance()->sendRecordingReadyMessage($call, $record);
		}

		$log && $logger->error("Call not found. CallId: {$callId}");

		return new Result();
	}

	/**
	 * Whether the record already shows a frame of its own pair in chat.
	 *
	 * A bound default preview does not count as one: it is a placeholder, and a real frame
	 * arriving later takes its place {@see self::defaultPreviewFileId()}.
	 */
	private function hasRealPreviewImage(Call\Track $record, int $defaultPreviewFileId): bool
	{
		$entry = (new \Bitrix\Main\UI\Viewer\PreviewManager())
			->getFilePreviewEntryByFileId($record->getFileId())
		;
		$boundImageId = (int)($entry['PREVIEW_IMAGE_ID'] ?? 0);

		return $boundImageId > 0 && $boundImageId !== $defaultPreviewFileId;
	}

	/**
	 * The file the default preview of the call was made from, 0 when the call has none.
	 *
	 * A call holds at most one such track: createDefaultPreview() refuses a call that already has
	 * a preview, and the track it leaves is downloaded, so resolvePreview() never deletes it as
	 * stuck.
	 */
	private function defaultPreviewFileId(int $callId): int
	{
		$defaultFileNames = [
			$this->defaultPreviewFileName($callId),
			$this->legacyDefaultPreviewFileName($callId),
		];
		foreach (Track::getTracksForCall($callId, Track::TYPE_VIDEO_PREVIEW) as $preview)
		{
			if (in_array($preview->getFileName(), $defaultFileNames, true))
			{
				return (int)$preview->getFileId();
			}
		}

		return 0;
	}

	private function defaultPreviewFileName(int $callId): string
	{
		return "preview_default_{$callId}.webp";
	}

	private function legacyDefaultPreviewFileName(int $callId): string
	{
		return "preview_default_{$callId}.png";
	}

	protected function cleanupIfAllRecordsProcessed(int $callId): void
	{
		if ($log = CallAISettings::isLoggingEnable())
		{
			$logger = Logger::getInstance();
		}

		$records = Track::getTracksForCall($callId, Track::TYPE_VIDEO_RECORD);
		foreach ($records as $record)
		{
			if (!$record->getDownloaded() || !$record->getFileId())
			{
				$log && $logger->info("Not all records processed yet. CallId: {$callId}, TrackId: {$record->getId()}");
				return;
			}
		}

		$log && $logger->info("All records processed. Running cleanup. CallId: {$callId}");

		CloudRecordExpectationAgent::removeAgent($callId);
		TrackDeletionService::getInstance()->deleteCloudTracksFromMixer($callId);
	}

	/**
	 * Process audio track without preview
	 *
	 * @param Call\Track $track Audio track
	 * @return Result
	 */
	protected function processCloudAudioTrack(Call\Track $track): Result
	{
		$result = new Result();

		if ($log = CallAISettings::isLoggingEnable())
		{
			$logger = Logger::getInstance();
		}

		$log && $logger->info("Processing audio track. TrackId: {$track->getId()}, Type: {$track->getType()}");

		if (!Loader::includeModule('im'))
		{
			$log && $logger->error("IM module not loaded. TrackId: {$track->getId()}");
			return $result->addError(new \Bitrix\Main\Error('IM module not loaded', 'IM_MODULE_NOT_LOADED'));
		}

		$callId = $track->getCallId();
		$call = Registry::getCallWithId($callId);

		if (!$call)
		{
			$log && $logger->error("Call not found. CallId: {$callId}");
			return $result->addError(new \Bitrix\Main\Error("Call not found: {$callId}", 'CALL_NOT_FOUND'));
		}

		if (!$track->getDownloaded() || !$track->getFileId())
		{
			$log && $logger->info("Track not ready yet. TrackId: {$track->getId()}, Downloaded: "
				. ($track->getDownloaded() ? 'yes' : 'no'));
			return $result;
		}

		$log && $logger->info("Sending recording ready message. CallId: {$callId}, RecordId: {$track->getId()}");
		$result->addErrors(NotifyService::getInstance()->sendRecordingReadyMessage($call, $track)->getErrors());

		$this->cleanupIfAllRecordsProcessed($callId);

		return $result;
	}

	protected function processAiTrack(Call\Track $track): Result
	{
		$result = new Result();

		if ($log = CallAISettings::isLoggingEnable())
		{
			$logger = Logger::getInstance();
		}

		TrackDeletionService::getInstance()->tryDeleteAiTracksFromMixer($track->getCallId());

		$minDuration = CallAISettings::getRecordMinDuration();
		if ($track->getDuration() > 0 && $track->getDuration() < $minDuration)
		{
			$log && $logger->error("Ignoring track:{$track->getUrl()}, track #{$track->getExternalTrackId()}. Call #{$track->getCallId()} was too short.");

			$error = new CallAIError(CallAIError::RECORD_TOO_SHORT);


			$aiService = AI\CallAIService::getInstance();
			$aiService->removeExpectation($track->getCallId());

			if ($track->getType() == Call\Track::TYPE_TRACK_PACK)
			{
				$call = Registry::getCallWithId($track->getCallId());
				NotifyService::getInstance()->sendTaskFailedMessage($error, $call);

				(new FollowUpAnalytics($call))->addGotEmptyRecord();
			}

			return $result->addError($error);
		}

		// The decision and the task are one step, under a lock of their own: the question
		// doNeedNeedAiProcessing() answers is "does this pack have a task yet", and the answer stops
		// being true the moment another process creates one. Two paths reach this pack now - the
		// callback of the service and the intake of the wait
		// {@see AI\CallAIService::takeTracksFromService()} - and the idempotency of the callback
		// covers repeats of the callback alone, so without this both would read "no task" and each
		// would create a paid one. The lock of the row above is released before this point and would
		// not help: the row is found by the second path, not created twice.
		$aiLockName = "call_track_ai_{$track->getCallId()}_{$track->getId()}";
		if (!Application::getConnection()->lock($aiLockName, self::TRACK_LOCK_TTL))
		{
			// Somebody else is deciding about this very pack right now, so this path has nothing to
			// decide: a lock held here means a task is being created, not that the file is lost.
			$log && $logger->info('AI processing of the track is already being decided. TrackId:' . $track->getId());

			return $result;
		}

		try
		{
			if ($this->doNeedNeedAiProcessing($track))
			{
				if (!CallAISettings::isCallAIEnable())
				{
					$log && $logger->error('Unable process track. Module AI is unavailable. TrackId:'.$track->getId());

					$error = new CallAIError(CallAIError::AI_UNAVAILABLE_ERROR);
					$error->allowRecover();

					$call = Registry::getCallWithId($track->getCallId());
					NotifyService::getInstance()->sendTaskFailedMessage($error, $call);

					return $result->addError($error);
				}

				$log && $logger->info('Start AI processing. TrackId:'.$track->getId());

				// The quota is asked inside the entry point below, which every other start of the chain
				// goes through as well {@see AI\CallAIService::processTrack()}; a copy of that gate here
				// is one the restart path walks past.
				$aiService = AI\CallAIService::getInstance();
				$aiResult = $aiService->processTrack($track);
				if (!$aiResult->isSuccess())
				{
					$error = $aiResult->getError();

					$call = Registry::getCallWithId($track->getCallId());
					NotifyService::getInstance()->sendTaskFailedMessage($error, $call);

					$result->addErrors($aiResult->getErrors());
				}
			}
		}
		finally
		{
			Application::getConnection()->unlock($aiLockName);
		}

		return $result;
	}

	/**
	 * Finalize download: attach to storage and Disk, then process track.
	 *
	 * File validation is performed in AbstractDownloader::complete() before this method is called.
	 *
	 * Idempotent: safe to call again on retry — skips already completed steps (attachTempFile is
	 * not re-run once FILE_ID is set; attachToDisk reuses an existing DISK_FILE_ID). This lets a
	 * disk-only failure be retried without re-downloading the file.
	 *
	 * $resendOfStoredFile marks the synchronous path: there the caller answers a live request for
	 * the delivery, so a failed processing is its failure too {@see self::processDownloaded()}. The
	 * background agent has no such caller and must not see it - a negative verdict there means the
	 * file is downloaded again {@see self::onCallTrackDownloadCompleted()}, which repairs nothing
	 * when the download was fine and the processing of the file was not.
	 *
	 * One failure of the processing is the exception, and it is raised to both paths: the recording
	 * that did not reach the chat {@see NotifyService::DELIVERY_FAILED}. The reason above does not
	 * hold for it - by the time the message is published DOWNLOAD_URL is already cleared
	 * {@see \Bitrix\Call\Track::attachTempFile()}, so the retry of the agent takes the
	 * finalize-only branch {@see \Bitrix\Call\Track\Downloader\DownloadAgent::run()} and
	 * downloads nothing. Only the delivery is raised, told by its code and not by the fact that
	 * processTrack() was unsuccessful, so the verdict does not start depending on, say, a module of
	 * somebody else that failed to load. The one delivery answer that differs between the paths is
	 * DELIVERY_NOT_CONFIRMED - see below.
	 *
	 * @param Call\Track $track
	 * @return Result Success only when the track is fully finalized.
	 */
	public function finalizeDownload(
		Call\Track $track,
		bool $restoreAiChain = true,
		?Call\Track $knownPreview = null,
		bool $resendOfStoredFile = false
	): Result
	{
		$result = new Result();
		$log = CallAISettings::isLoggingEnable();
		$logger = Logger::getInstance();

		// Whether this pass found the file already on the portal instead of putting it there. The
		// files of a call leave the mixer with the pass that downloaded them, so a pass that
		// downloaded nothing must not ask the controller to drop them again
		// {@see self::processCloudVideoTrack()}. The answer is taken before the attaches below:
		// afterwards every pass looks like a pass over a stored file.
		$storedBeforeThisPass = $this->isStoredOnPortal($track);

		$log && $logger->info("finalizeDownload: Starting. TrackId: {$track->getId()}");

		// The mark of the deletion, asked again at the last door before the file becomes a message
		// in the chat. The intake read it too {@see self::intakeServiceFile()}, but between that
		// read and this one lie the making of the row and the whole download, and a user who
		// destroyed the recording in that time left the mark in the table alone
		// {@see \Bitrix\Call\Call::readRecordDestroyedMark()}. Asking here costs one read per
		// downloaded file and closes the part of that window that matters: the file arriving in the
		// chat the user has just removed it from. The row and the downloaded file stay - the
		// deletion of the recording removes them by its own path.
		$callOfTrack = Registry::getCallWithId($track->getCallId());
		if ($callOfTrack && $callOfTrack->readRecordDestroyedMark())
		{
			$log && $logger->error(
				"finalizeDownload: the record of call #{$track->getCallId()} was destroyed by a user."
				. " TrackId: {$track->getId()}"
			);

			return $result->addError(new Call\Error('record_destroyed', 'The record of the call was destroyed'));
		}

		// Attach to file storage (skip if already attached — attachTempFile is not idempotent).
		// A row that still names the copy a replacement is pushing out has not been through this
		// pass at all {@see self::awaitsReplacement()}: it is the previous file it names, kept
		// until this very landing, and the file it names afterwards is the one that goes
		// {@see self::dropReplacedFile()}.
		$replacedFileId = $this->awaitsReplacement($track) ? (int)$track->getFileId() : 0;
		if (!$track->getFileId() || $replacedFileId > 0)
		{
			$attachResult = $track->attachTempFile();
			if (!$attachResult->isSuccess())
			{
				$log && $logger->error("finalizeDownload: attachTempFile failed. TrackId: {$track->getId()}");
				$this->fireTrackErrorEvent($track, $attachResult->getError());
				$forced = (bool)($attachResult->getData()['debug_forced_error'] ?? false);
				$this->sendFinalizeTelemetry($track, 'error', 'finalize_save_failed' . ($forced ? '_debug' : ''), $attachResult->getError());

				return $result->addError(new TrackError(TrackError::FINALIZE_FAILED, 'Attach temp file failed'));
			}

			// file_id just appeared
			$this->sendTrackTelemetry($track, 'success', 'file_attached', context: ['fileId' => $track->getFileId()]);

			// The new copy is on the portal and the row names it: the one the row named until a
			// moment ago is addressed by nothing now.
			$this->dropReplacedFile($track, $replacedFileId);
		}

		// Attach to Disk (for audio/video records)
		if ($this->doNeedNeedAttachToDisk($track))
		{
			$hadDiskFile = (bool)$track->getDiskFileId();

			// Whether the file of the chat Disk this row points at is to be written anew rather
			// than made or reused {@see self::replacesDiskContent()}. Asked right here, where the
			// answer is used: it is read off the two files and not off a mark the attach above
			// consumes, so no pass of the finalization can lose it half-way through.
			$diskResult = $track->attachToDisk($this->replacesDiskContent($track));
			if (!$diskResult->isSuccess())
			{
				$log && $logger->error("finalizeDownload: attachToDisk failed. TrackId: {$track->getId()}");
				$this->fireTrackErrorEvent($track, $diskResult->getError());
				$forced = (bool)($diskResult->getData()['debug_forced_error'] ?? false);
				$this->sendFinalizeTelemetry($track, 'error', 'finalize_disk_failed' . ($forced ? '_debug' : ''), $diskResult->getError());

				return $result->addError(new TrackError(TrackError::FINALIZE_FAILED, 'Attach to disk failed'));
			}

			// disk_file_id just appeared (don't re-emit on an idempotent re-finalize)
			if (!$hadDiskFile && $track->getDiskFileId())
			{
				$this->sendTrackTelemetry($track, 'success', 'disk_file_attached', context: ['diskFileId' => $track->getDiskFileId()]);
			}

			// Record delivery to chat (file appears in chat files). On a successful attachToDisk
			// chatDelivered is always true (message_owned / link_exists / link saved); every
			// not-delivered path returns an error and exits above, so there is no skipped branch.
			if (($diskResult->getData()['chatDelivered'] ?? false) === true)
			{
				$this->sendTrackTelemetry($track, 'success', 'record_chat_delivered');
			}
		}

		// Verify the track is fully finalized before declaring success
		if (!$track->getFileId() || ($this->requiresDisk($track) && !$track->getDiskFileId()))
		{
			$log && $logger->error("finalizeDownload: Not finalized. TrackId: {$track->getId()}");
			$this->sendFinalizeTelemetry($track, 'error', 'finalize_failed');

			return $result->addError(new TrackError(TrackError::FINALIZE_FAILED, 'Track not finalized'));
		}

		$log && $logger->info("finalizeDownload: Success. TrackId: {$track->getId()}, FileId: {$track->getFileId()}");

		$this->sendFinalizeTelemetry($track, 'success', 'finalize_success');

		$this->checkAiTasksExecution($track, $restoreAiChain);

		$processResult = $this->processTrack(
			$track,
			$knownPreview,
			$resendOfStoredFile || $storedBeforeThisPass,
		);
		if ($processResult->isSuccess())
		{
			return $result;
		}

		$deliveryErrors = $this->deliveryErrorsOf($processResult);
		$deliveryError = $deliveryErrors[0] ?? null;
		if ($deliveryError !== null)
		{
			// "Not confirmed" says another process holds the right to send this very message. On
			// the synchronous resend that is a success: the answer goes to an operator, the winner
			// of the race is sending it, and there is nobody here to retry for - answering an
			// error would fail a parallel manual retry that succeeds today. On the background path
			// it is a failure, and the agent comes back to either find the message of the winner
			// or send its own.
			if ($resendOfStoredFile && $deliveryError->getCode() === NotifyService::DELIVERY_NOT_CONFIRMED)
			{
				return $result;
			}

			// The mark goes into the row of the delivery and not of the finalization: its pair is
			// cloud_record_chat_sent {@see NotifyService::sendRecordingReadyMessage()}, and the
			// defect is read as the ratio of the two rows. finalize_success cannot be that pair -
			// it goes out above, before processTrack() is entered, so one and the same pass would
			// hold both it and this error. Its meaning is left alone for the same reason: the
			// defect was found by comparing those rows with the periods before them.
			//
			// One mark and one line per delivery that failed, each naming the recording of its
			// own. A pass of the preview carries a delivery per record of the call, and the two
			// rows the defect is counted by would otherwise point at different tracks: the pair
			// names the record it published, while this one would name the preview being
			// finalized - a track whose delivery nobody even attempted.
			$call = Registry::getCallWithId($track->getCallId());
			foreach ($deliveryErrors as $error)
			{
				$record = $this->trackOfDeliveryError($error, $track);

				if ($call)
				{
					(new CloudRecordAnalytics($call))->sendTelemetry(
						source: $record,
						status: 'error',
						event: 'cloud_record_chat_send_failed',
						error: $error,
					);
				}

				$log && $logger->error(
					"finalizeDownload: the recording of call #{$track->getCallId()},"
					. " track #{$record->getId()} was not delivered to chat."
				);
			}

			// The verdict keeps the code every caller of the finalization already answers to, and
			// carries what failed inside it: the row of the download is chosen by that class and
			// not by the state of the track {@see self::isDeliveryFailure()}.
			return $result->addError(new TrackError(
				TrackError::FINALIZE_FAILED,
				'Recording delivery failed',
				[self::DELIVERY_FAILURE => true],
			));
		}

		// The synchronous caller reports this failure itself, in the answer of the callback
		// and in the telemetry of its own path, so reporting it here as well would count one
		// failure twice.
		if ($resendOfStoredFile)
		{
			$result->addError($processResult->getError());
		}
		else
		{
			$call = Registry::getCallWithId($track->getCallId());
			if ($call)
			{
				(new TrackAnalytics($call))->sendTelemetry(
					source: $track,
					status: 'error',
					event: 'track_processing_error',
					error: $processResult->getError()
				);
			}
		}

		return $result;
	}

	/**
	 * The failures of the delivery of the recordings to chat among the failures of the processing,
	 * empty when the processing failed for another reason. Recognised by the code, because the
	 * branches next to the delivery answer with codes of everybody's - IM_MODULE_NOT_LOADED,
	 * CALL_NOT_FOUND - and the verdict of the finalization must not hang on those.
	 *
	 * More than one of them is the regular shape and not an edge: one pass of the preview delivers
	 * every record of the call {@see self::processCloudVideoTrack()}.
	 *
	 * @return \Bitrix\Main\Error[] in the order the deliveries were attempted
	 */
	private function deliveryErrorsOf(Result $result): array
	{
		$deliveryCodes = [NotifyService::DELIVERY_FAILED, NotifyService::DELIVERY_NOT_CONFIRMED];

		return array_values(array_filter(
			$result->getErrors(),
			static fn (\Bitrix\Main\Error $error): bool => in_array($error->getCode(), $deliveryCodes, true),
		));
	}

	/**
	 * The recording a failed delivery is about. The delivery names it in the custom data of its
	 * error {@see NotifyService::DELIVERY_TRACK_ID}, because the track being finalized is not
	 * always the one that was being delivered.
	 *
	 * Falls back to the finalized track: an error of the delivery without the id is not worth
	 * losing the mark and the line over, and on every path but the one of the preview the two are
	 * one and the same track anyway.
	 */
	private function trackOfDeliveryError(\Bitrix\Main\Error $error, Call\Track $finalized): Call\Track
	{
		$customData = $error->getCustomData();
		$trackId = is_array($customData) ? (int)($customData[NotifyService::DELIVERY_TRACK_ID] ?? 0) : 0;

		if ($trackId <= 0 || $trackId === (int)$finalized->getId())
		{
			return $finalized;
		}

		$record = Call\Model\CallTrackTable::getById($trackId)->fetchObject();

		return $record instanceof Call\Track ? $record : $finalized;
	}

	/**
	 * Send track download pipeline telemetry for the finalization stage.
	 */
	private function sendFinalizeTelemetry(
		Call\Track $track,
		string $status,
		string $event,
		?\Bitrix\Main\Error $error = null
	): void
	{
		$call = Registry::getCallWithId($track->getCallId());
		if (!$call)
		{
			return;
		}

		(new TrackAnalytics($call))->sendTelemetry(
			source: $track,
			status: $status,
			event: 'download_agent_' . $event,
			error: $error
		);
	}

	/**
	 * Send generic track telemetry (no download_agent_ prefix), with optional errorCode/context.
	 */
	private function sendTrackTelemetry(
		Call\Track $track,
		string $status,
		string $event,
		?string $errorCode = null,
		array $context = []
	): void
	{
		$call = Registry::getCallWithId($track->getCallId());
		if (!$call)
		{
			return;
		}

		(new TrackAnalytics($call))->sendTelemetry(
			source: $track,
			status: $status,
			errorCode: $errorCode,
			event: $event,
			context: $context
		);
	}

	/**
	 * Download track file - orchestrates the download process.
	 *
	 * @param Call\Track $track Track entity
	 * @param bool $retryOnFailure Schedule retry agent on failure
	 * @return Result
	 */
	public function downloadTrackFile(Call\Track $track, bool $retryOnFailure = true): Result
	{
		$result = new Result();

		// A file and nothing to do - unless the file is the copy a replacement is on its way to
		// push out, which the row keeps naming until the new one lands
		// {@see self::awaitsReplacement()}. Read as "the copy is here", it would answer the very
		// download the replacement exists to order with "nothing to do".
		if ($track->getFileId() > 0 && !$this->awaitsReplacement($track))
		{
			return $result->setData(['fileId' => $track->getFileId()]);
		}

		$log = CallAISettings::isLoggingEnable();
		$logger = Logger::getInstance();

		if (empty($track->getDownloadUrl()))
		{
			$log && $logger->error("downloadTrackFile: URL undefined. TrackId: {$track->getId()}");
			return $result->addError(new TrackError(TrackError::EMPTY_DOWNLOAD_URL, 'Download URL undefined'));
		}

		$log && $logger->info("downloadTrackFile: Scheduling download agent. TrackId: {$track->getId()}");

		try
		{
			DownloadAgent::schedule($track->getId());
			return $result->setData(['status' => 'scheduled']);
		}
		catch (\Psr\Http\Client\ClientExceptionInterface | SystemException $ex)
		{
			$log && $logger->error("downloadTrackFile: Exception: {$ex->getMessage()}. TrackId: {$track->getId()}");
			$result->addError(new TrackError(TrackError::DOWNLOAD_ERROR, $ex->getMessage()));
		}

		return $result;
	}

	/**
	 * Check AI tasks execution and send audio record message if needed
	 *
	 * If no analysis is coming to the chat and no expectation agent exists, sends the audio record
	 * notification to chat as fallback - finalization of a stored file reuses the link it already
	 * made {@see \Bitrix\Call\Track::attachToDisk()} and announces nothing itself.
	 *
	 * A switched-off analysis is read from the call itself, because $restoreAiChain does not survive
	 * the download hop and the file may have been taken by a path that ended long before this
	 * download did. It does not override a running task: that task was launched while the flag was
	 * still on and publishes its own result. What only the caller knows stays in $restoreAiChain -
	 * permission to spend a quota on a relaunch, and on a resend that nothing else is on its way.
	 *
	 * @param Track $track Downloaded track (must be TYPE_RECORD)
	 */
	public static function checkAiTasksExecution(Track $track, bool $restoreAiChain = true): void
	{
		if ($track->getType() !== \Bitrix\Call\Track::TYPE_RECORD)
		{
			return;
		}

		if ($log = CallAISettings::isLoggingEnable())
		{
			$logger = Logger::getInstance();
			$logger->info("Checking AI tasks after track download. TrackId: {$track->getId()}, CallId: {$track->getCallId()}");
		}

		$call = Registry::getCallWithId($track->getCallId());
		$aiService = AI\CallAIService::getInstance();
		$aiResult = $aiService->checkCallAiTask($track->getCallId());

		if (
			$restoreAiChain
			// An analysis the portal switched off is not resurrected by a file arriving late.
			&& $call?->isAiAnalyzeEnabled()
			&& $aiResult->isSuccess()
			&& ($aiResult->getData()['restart'] ?? null) === true
			&& !$aiService->hasExpectationAgent($track->getCallId())
		)
		{
			$log && $logger->info("AI outcome is empty, relaunching AI chain for call #{$track->getCallId()}");
			$aiResult = $aiService->restartCallAiTask($track->getCallId());

			// The restart arms no wait of its own, and this branch runs exactly where the call has
			// none: left as it was, a chain relaunched here would run unwatched and the user would
			// get neither a result nor a timeout if its callback were lost. Armed the way every
			// other path of the restart arms it {@see AI\CallAIService::watchRetriedCall()} - on a
			// restart that left something to wait for, and on no other.
			if (self::restartLeftSomethingToWaitFor($aiResult))
			{
				$aiService->setupExpectation($track->getCallId());
			}
		}

		// The check reports an empty outcome and a missing pack as success, so the verdict is made
		// here. An analysis switched off is the state nothing can deliver an analysis from - not even
		// a pack arriving later - and it is read here rather than taken from the caller, because the
		// file may have been taken by a path that ended long before this download did.
		$outcome = $aiResult->getData();
		$analysisIsRunning = ($outcome['pending_sense'] ?? '') !== '';
		$analysisIsNotComing =
			!$aiResult->isSuccess()
			|| (!$call?->isAiAnalyzeEnabled() && !$analysisIsRunning)
			|| (
				!$restoreAiChain
				&& (($outcome['restart'] ?? false) || ($outcome['wait_more'] ?? false))
			)
		;

		if ($analysisIsNotComing && !$aiService->hasExpectationAgent($track->getCallId()))
		{
			$log && $logger->info("No analysis is coming to the chat of call #{$track->getCallId()} and no expectation agent found");

			if (!Loader::includeModule('im'))
			{
				$log && $logger->error("Cannot load IM module");
				return;
			}

			if ($call)
			{
				NotifyService::getInstance()->sendAudioRecordMessage($call);
				$log && $logger->info("Audio record message sent for call #{$track->getCallId()}");
			}
		}
	}

	/**
	 * Whether a restart left the call something a wait could watch, read off the verdict of the
	 * restart itself {@see AI\CallAIService::restartCallAiTask()}.
	 *
	 * A refusal left nothing: it is reported where it happened and nothing was created. An analysis
	 * already complete left nothing either - there is no step of it that has not answered. Every
	 * other verdict did: a start, a batch that launched part of what it meant to, an analysis
	 * already running, and a launch a handler cancelled before the queue, whose task is saved and
	 * counts as unanswered exactly as a queued one does.
	 */
	private static function restartLeftSomethingToWaitFor(Result $restartResult): bool
	{
		return $restartResult->isSuccess()
			&& ($restartResult->getData()['status'] ?? null) !== AI\CallAIService::RESTART_STATUS_ALREADY_COMPLETE
		;
	}

	/**
	 * Event handler for call:onCallTrackDownloadCompleted
	 * Called immediately after download completes, before finalization
	 *
	 * @event call:onCallTrackDownloadCompleted
	 * @param Event $event
	 */
	public static function onCallTrackDownloadCompleted(Event $event): EventResult
	{
		$track = $event->getParameter('track');
		if (!($track instanceof \Bitrix\Call\Track))
		{
			return new EventResult(EventResult::ERROR);
		}

		$log = CallAISettings::isLoggingEnable();
		if ($log)
		{
			$logger = Logger::getInstance();
			$logger->info("onCallTrackDownloadCompleted: Starting finalization. TrackId: {$track->getId()}");
		}

		$service = self::getInstance();
		$finalizeResult = $service->finalizeDownload($track);

		// Report the finalize verdict so AbstractDownloader::complete() can retry the download
		// agent when finalization failed for a reason not visible in the track state alone
		// (e.g. file+disk are set but the chat link was not published).
		//
		// The errors travel with the verdict and not only the fact of it: the class of the failure
		// is what the row of the download of this pass is chosen by
		// {@see \Bitrix\Call\Track\Downloader\DownloadAgent::reportFailedDownload()}, and the
		// finalization is the only place that knows it. Without them the delivery of a recording
		// that failed would look exactly like a file that never landed.
		return new EventResult(
			$finalizeResult->isSuccess() ? EventResult::SUCCESS : EventResult::ERROR,
			['errors' => $finalizeResult->getErrors()],
		);
	}

	/**
	 * @event call:onCallTrackReady
	 * @param Call\Track $track
	 * @return Event
	 */
	protected function fireTrackReadyEvent(Call\Track $track): Event
	{
		$event = new Event('call', 'onCallTrackReady', ['track' => $track]);
		$event->send();

		return $event;
	}

	/**
	 * @event call:onCallTrackError
	 * @param Call\Track $track
	 * @return Event
	 */
	protected function fireTrackErrorEvent(Call\Track $track, \Bitrix\Main\Error $error): Event
	{
		$event = new Event('call', 'onCallTrackError', ['track' => $track, 'error' => $error]);
		$event->send();

		return $event;
	}
}
