<?php

namespace StepOrange\SEPA;

use XMLWriter;
use Carbon\Carbon;
use App\Tenant\Bank;
use App\ProcessingHub;
use App\Traits\Loggable;
use App\Tenant\Installment;
use App\Tenant\PaymentSchedule;
use App\Events\FileReadyForUpload;
use App\Exceptions\Pain008Exception;
use Illuminate\Support\Facades\Storage;
use App\Repositories\Tenant\InstallmentRepository;
use App\Repositories\Tenant\PaymentScheduleRepository;

class PainStreamGenerator
{
	use Loggable;

	/**
	 * The type of file we need to generate.
	 * 
	 * @var [type]
	 */
	protected $generator;

	protected $fileInfo;

	protected $filenames;

	/**
     * True if an error occured during generation, false otherwise.
     */
    private $hasGenerationError = false;

    const STATUS_NEW = 'New';
    const STATUS_COLLECTED = 'Collected';
    const STATUS_FAILED = 'Failed';
    const STATUS_WAITING_VERIFICATION = 'Pending Verification';

	/**
	 * Service constructor
	 *
	 * @param      App\Repositories\InstallmentRepository  $installments  The installments
	 */
	public function __construct(InstallmentRepository $installments, PaymentScheduleRepository $paymentSchedules)
	{
		$this->installments = $installments;
		$this->paymentSchedules = $paymentSchedules;

		$this->filenames = collect();	
	}

	/**
	 * Sets the destination iban.
	 *
	 * @param      string  $destinationIBAN  The destination iban
	 */
	public function setBank(Bank $bank)
	{
		$this->bank = $bank;	
	}

	/**
	 * @param string
	 */
	public function setGeneratorType($generator)
	{
		$this->generator = $generator;
	}

	/**
	 * Gets the file info.
	 * 
	 * @return [type]
	 */
	public function getFileinfo()
	{
		return $this->fileInfo;	
	}

	/**
	 * Sets the payment schedule.
	 *
	 * @param      string  $paymentSchedule  The payment schedule
	 */
	public function setPaymentSchedule(PaymentSchedule $paymentSchedule)
	{
		$this->paymentSchedule = $paymentSchedule;	
	}

	/**
	 * Generate the PAIN00800102 file
	 * 
	 * @return [type] [description]
	 */
	public function generate()
	{
		$numberOfInstallments = $this->getInstallmentQueryBuilder()->count();
		$amountOfInstallments = ($this->getInstallmentQueryBuilder()->sum('amount')/100);

		if ($numberOfInstallments <= 0 ) {
			throw new Pain008Exception('There are no installments that needs to be processed!');
		}

		$unlimitedRecordsPerFile = false;
		$allowedNumberOfRecordsPerFile = formatStrict(getBankConfigValue('RECORDS_PER_FILE', $this->bank), 'int');
		if ($allowedNumberOfRecordsPerFile == 0) {
			$unlimitedRecordsPerFile = true;
			$allowedNumberOfRecordsPerFile = $numberOfInstallments;
		}

		$allowedNumberOfRecordsPerSequence = formatStrict(getBankConfigValue('RECORDS_PER_SEQUENCE_TYPE', $this->bank), 'int');
		$allowedNumberOfRecordsPerSequence = ($allowedNumberOfRecordsPerSequence > 0 ? $allowedNumberOfRecordsPerSequence : $allowedNumberOfRecordsPerFile);

		// Validate of config has been setup correctly
		if (!$unlimitedRecordsPerFile && $allowedNumberOfRecordsPerSequence > $allowedNumberOfRecordsPerFile) {
			throw new Pain008Exception('Config error: RECORDS_PER_SEQUENCE_TYPE may never exceed RECORDS_PER_FILE. Please correct your configuration.');
		}

		$numberOfFiles = ceil($numberOfInstallments/$allowedNumberOfRecordsPerFile);

		$this->logInfoMessage(
            'Generator working to generate ' . $numberOfFiles . ' SDD files.'
        );

		$fileDate = Carbon::now();
		
		for($filePointer = 0; $filePointer < $numberOfFiles; $filePointer++) {
			$this->fileInfo = $this->calculateFileTotals($allowedNumberOfRecordsPerFile, $allowedNumberOfRecordsPerSequence, $filePointer);

			$this->generator->setFilename(
				$this->generateFilename(str_pad(($filePointer+1), 4, 0, STR_PAD_LEFT), $fileDate)
			);

			$this->generator->openDocument();
			$messageId = $this->generator->addHeader($this->fileInfo['totals']['records'], intToDecimal($this->fileInfo['totals']['amount']));
			
			$sequenceTypeMemory = null;

			foreach($this->fileInfo['batches'] as $sequenceType => &$perSequenceType) {
				foreach($perSequenceType as $batchId => &$batch) {
					$batchInfo = $this->generator->addBatch($sequenceType, ($batchId+1), $batch['records'], $batch['amount'], $this->paymentSchedule->selection_date);
					
					// Enrich fileInfo array
					$batch['message_id'] = $messageId;
					$batch['batch_id'] = $batchInfo;
					$batch['filename'] = $this->generator->getFilename();

					$this->writeBatchInstallments($allowedNumberOfRecordsPerFile, $filePointer, $batch, $batchId, $sequenceType);

					$this->generator->endBatch();
					$this->generator->writeBuffer();
				}
			}

			// Handle the leftover
			$this->generator->endPainDocument();
			$this->generator->writeBuffer();

			$this->followup($this->generator->getFilename());
		}

		$this->processFileDetailsAndTotals();
	}

	/**
	 * Write the installments that belongs to the batch given
	 * 
	 * @param  [type] $allowedNumberOfRecordsPerFile [description]
	 * @param  [type] $filePointer                   [description]
	 * @param  [type] $batch                         [description]
	 * @param  [type] $batchId                       [description]
	 * @param  [type] $sequenceType                  [description]
	 * @return [type]                                [description]
	 */
	public function writeBatchInstallments($allowedNumberOfRecordsPerFile, $filePointer, $batch, $batchId, $sequenceType)
	{
		$recordCounter = 0;
		foreach($this->getInstallmentQueryBuilder()
			->skip(($allowedNumberOfRecordsPerFile*$filePointer)+($batch['records']*$batchId))
			->take($batch['records'])
			->where('sequence_type', $sequenceType)
			->orderBy('sequence_type')						
			->cursor() as $installment) {

			try {
				$this->generator->addPayment($installment);

				$installment->status = self::STATUS_NEW;
			} catch(\Exception $e) {
				$this->hasGenerationError = true;

	        	$installment->status = self::STATUS_FAILED;
	        	$installment->last_failure_reason = $e->getMessage();        					     
			}

			$this->updateHandledInstallment($installment);   

			if (0 == $recordCounter%1000) {
				$this->generator->writeBuffer();	
			}

			$recordCounter++;
		}

		$this->generator->writeBuffer();
	}

	public function calculateFileTotals($allowedNumberOfRecordsPerFile, $allowedNumberOfRecordsPerSequence, $filePointer)
	{
		$totals['totals']['records'] = 0;
		$totals['totals']['amount'] = 0;
		$totals['totals']['first_collection_date'] = null;

		$sequenceTypeCount = 0;
		$sequenceTypeRecordCount = 0;
		$sequenceType = null;
		foreach($this->getInstallmentQueryBuilder()
			->skip($allowedNumberOfRecordsPerFile*$filePointer)
			->take($allowedNumberOfRecordsPerFile)
			->orderBy('sequence_type')
			->cursor() as $installment) {

			$totals['totals']['records'] = $totals['totals']['records']+1;
			$totals['totals']['amount'] = $totals['totals']['amount']+$installment->amount->getAmount();

			if (0 == $sequenceTypeRecordCount%$allowedNumberOfRecordsPerSequence) {
				$sequenceTypeCount++;
			}

			if (is_null($sequenceType) || $sequenceType != strtoupper($installment->sequence_type)) {
				$sequenceType = strtoupper($installment->sequence_type);
				$sequenceTypeCount = 0;				
			}

			if (! isset($totals['batches'][$installment->sequence_type][$sequenceTypeCount])) {
				$totals['batches'][$installment->sequence_type][$sequenceTypeCount]['records'] = 1;
				$totals['batches'][$installment->sequence_type][$sequenceTypeCount]['amount'] = $installment->amount->getAmount();
			} else {
				$totals['batches'][$installment->sequence_type][$sequenceTypeCount]['records'] = $totals['batches'][$installment->sequence_type][$sequenceTypeCount]['records']+1;
				$totals['batches'][$installment->sequence_type][$sequenceTypeCount]['amount'] = $totals['batches'][$installment->sequence_type][$sequenceTypeCount]['amount']+$installment->amount->getAmount();	
			}

			if (is_null($totals['totals']['first_collection_date']) || $installment->due_date->format('Y-m-d') < $totals['totals']['first_collection_date']) {
				$totals['totals']['first_collection_date'] = $installment->due_date->format('Y-m-d');
			}

			$sequenceTypeRecordCount++;			
		}

		return $totals;
	}

	/**
	 * Save the file to local storage and send upload event.
	 *
	 * @param      string  $filename  The filename
	 */
	private function followup(string $filename)
	{		
		$this->validate($filename);

		event(
            new FileReadyForUpload(storage_path('app/'.$filename), convertConfigToBoolean(getBankConfigValue('UPLOAD_SDD_TO_CHATTER', $this->bank)->value))
        );
	}

	/**
	 * Validate the file contents
	 * 
	 * @param  string $filename [description]
	 * @return [type]           [description]
	 */
	public function validate(string $filename)
	{
		$this->logInfoMessage(
            trans('process.generate.pain008.validation.start', ['filename' => basename($filename)])
        );

        $fileRoot = ('filesystems.disks.'.config('filesystems.default_disk').'.root');

		$xml = new \XMLReader();
		$xml->open(config($fileRoot) .'/'. $filename);
		$xml->setSchema(app_path()."/Support/Pain008/pain.008.001.02.xsd");
		$xml->isValid();

		while($xml->read());
		$xml->close();

		$this->logInfoMessage(
            trans('process.generate.pain008.validation.finished', ['filename' => basename($filename)])
        );
	}

	/**
	 * Update the installment
	 *
	 * @param      Installment  $installment  The installment
	 */
	public function updateHandledInstallment(Installment $installment)
	{
		// Update the installment accordingly
        $this->installments->update(
        	$installment, [
        		'status' => $installment->status,
        		'last_failure_reason' => $installment->last_failure_reason
        	]
        );
	}

	/**
	 * Generate a name for the file.
	 *
	 * @param      string  $appendWith  The append with
	 *
	 * @return     <type>  The generated filename
	 */
	public function generateFilename(string $appendWith, Carbon $fileDate)
	{
		$filename = strtoupper(str_slug(
			getBankConfigValue('IBAN', $this->bank)->value . ' ' . $fileDate->format('YmdH:i:s') . ' ' . $appendWith . '-SDD'
		)) . '.xml';

		$this->filenames->push($filename);

		return $filename;
	}

	/**
	 * Gets the installment count.
	 *
	 * @return     Int  The installment count.
	 */
	public function getInstallmentQueryBuilder()
	{
		return $this->paymentSchedule->installments()
			->whereIN('payment_method', ['Direct Debit'])
			->where('destinationiban', getBankConfigValue('IBAN', $this->bank)->value);
	}

	/**
	 * Process the file totals but also the batch totals.
	 *
	 * @param      string  $filename  The filename
	 */
	public function processFileDetailsAndTotals()
	{
		// Soft delete existing records
		$this->paymentSchedule->totals()->delete();

		// Add new Batch infos
		foreach($this->fileInfo['batches'] as $sequence => $batches) {
			foreach($batches as $batch) {
				$this->paymentSchedule->totals()->create([
				    'type' => $sequence,
				    'batch_id' => $batch['batch_id'],
				    'transactions' => $batch['records'],
				    'amount' => $batch['amount'],
				    'filename' => $batch['filename'],
				    'message_id' => $batch['message_id']
				]);
			}
		}

		// Update totals of the complete run back to the payment schedule.
		$this->paymentSchedules->update(
			$this->paymentSchedule, [
				'transactions' => $this->fileInfo['totals']['records'],
				'amount' => $this->fileInfo['totals']['amount'],
				'first_collection_date' => $this->fileInfo['totals']['first_collection_date'],
				'status' => self::STATUS_WAITING_VERIFICATION
			]
		);
	}

	/**
	 * Remove the local files.
	 */
	public function __destruct()
	{
		$this->filenames->each(function($filename) {
			Storage::disk(config('filesystems.default_disk'))->delete($filename);
		});
	}
}