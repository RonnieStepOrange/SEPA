<?php

namespace StepOrange\SEPA\PAIN00800102;

use XMLWriter;
use Carbon\Carbon;
use App\Exceptions\Pain008Exception;
use Illuminate\Support\Facades\Storage;
use StepOrange\SEPA\Contracts\PainContract;

class Writer extends XMLWriter implements PainContract
{
	/**
	 * The name of the file
	 * 
	 * @var [type]
	 */
	protected $filename;

	/**
	 * The config to use for generating
	 * 
	 * @var [type]
	 */
	protected $config;

	/**
	 * Constructor setting the config
	 * 
	 * @param [type] $config [description]
	 */
	public function setConfig($config)
	{
		$this->config = $config;
	}

	public function getConfig()
	{
		return $this->config;
	}

	/**
	 * Sets the filename
	 * 
	 * @param string $filename [description]
	 */
	public function setFilename(string $filename)
	{
		$this->filename = $filename;
	}

	/**
	 * Gets the filename
	 * 
	 * @return [type] [description]
	 */
	public function getFilename()
	{
		return $this->filename;
	}

	/**
	 * Open stream for writing the XML file
	 * 
	 * @return [type] [description]
	 */
	public function openDocument()
	{
		$this->openMemory();
		$this->startDocument('1.0', 'UTF-8');
		$this->setIndent(true);
		$this->setIndentString('    ');
	}

	/**
	 * Create the XML header section
	 * 
	 * @return [type] [description]
	 */
	public function addHeader($totalRecords, $totalAmount)
	{
		$messageId = $this->makeMsgId();

		$this->startElement('Document');
		
		$this->writeAttribute('xmlns', 'urn:iso:std:iso:20022:tech:xsd:pain.008.001.02');
		$this->writeAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-intance');

		$this->startElement('CstmrDrctDbtInitn');

		$this->startElement('GrpHdr');
		$this->writeElement('MsgId', $messageId);
		$this->writeElement('CreDtTm', Carbon::now()->format('Y-m-d\TH:i:s'));
		$this->writeElement('NbOfTxs', $totalRecords);
		$this->writeElement('CtrlSum', intToDecimal($totalAmount));

		$this->startElement('InitgPty');
		$this->writeElement('Nm', $this->config['name']);
		$this->endElement();

		$this->endElement();

		$this->writeBuffer();

		return $messageId;
	}

	/**
	 * Create a sub header row
	 * 
	 * @param  [type] $sequenceType  [description]
	 * @param  [type] $sectionNumber [description]
	 * @param  [type] $installments  [description]
	 * @return [type]                [description]
	 */
	public function addBatch($sequenceType, $sectionNumber, $batchRecordCount, $batchRecordAmount, $selectionDate)
	{
		$batchId = 'PAY-ID-' . $sectionNumber . '-' . $sequenceType . '-' . Carbon::now()->format('Ymd\THis');

		$this->startElement('PmtInf');

		// PmtInfId node
		$this->writeElement('PmtInfId', $batchId);

		// PmtMtd node
		$this->writeElement('PmtMtd', 'DD');

		// NbOfTxs node
		$this->writeElement('NbOfTxs', $batchRecordCount);
		$this->writeElement('CtrlSum', intToDecimal($batchRecordAmount));

		// PmtTpInf node
		$this->startElement('PmtTpInf');
		$this->startElement('SvcLvl');
		$this->writeElement('Cd', 'SEPA');
		$this->endElement();
		$this->startElement('LclInstrm');
		$this->writeElement('Cd', 'CORE');
		$this->endElement();
		$this->writeElement('SeqTp', $sequenceType);
		$this->endElement();

		// ReqdColltnDt node
		$this->writeElement('ReqdColltnDt', $selectionDate->format('Y-m-d'));

		// Cdtr noide
		$this->startElement('Cdtr');
		$this->writeElement('Nm', $this->config['name']);
		$this->endElement();

		// CdtrAcct node
		$this->startElement('CdtrAcct');
		$this->startElement('Id');
		$this->writeElement('IBAN', $this->config['IBAN']);
		$this->endElement();
		$this->endElement();

		// CdtrAgt node
		$this->startElement('CdtrAgt');
		$this->startElement('FinInstnId');
		$this->writeElement('BIC', $this->config['BIC']);
		$this->endElement();
		$this->endElement();

		// ChrgBr node
		$this->writeElement('ChrgBr', 'SLEV');

		// CdtrSchmeId mode
		$this->StartElement('CdtrSchmeId');
		$this->writeElement('Nm', $this->config['name']);
		$this->startElement('Id');
		$this->startElement('PrvtId');
		$this->startElement('Othr');
		$this->writeElement('Id', $this->config['creditor_id']);
		$this->startElement('SchmeNm');
		$this->writeElement('Prtry', 'SEPA');
		$this->endElement();
		$this->endElement();
		$this->endElement();
		$this->endElement();
		$this->endElement();

		$this->writeBuffer();

		return $batchId;
	}

	/**
	 * Handle a single transation
	 * 
	 * @param  [type] $node [description]
	 * @return [type]       [description]
	 */
	public function addPayment($installment)
	{
		// First validate the file
		$validationResult = $this->validatePayment($installment->toArray());
		if ($validationResult !== true) {
            throw new Pain008Exception('Invalid Payment, error: ' . $validationResult);
        }

		// If no exception is thrown all is good and payment is valid
		$this->startElement('DrctDbtTxInf');

		// PmtId node
		$this->startElement('PmtId');
		$this->writeElement('EndToEndId', $installment->final_payment_reference);
		$this->endElement();

		// InstdAmt node
		$this->startElement('InstdAmt');
		$this->writeAttribute('Ccy', $this->config['currency']);
		$this->text(intToDecimal($installment->amount->getAmount()));
		$this->endElement();

		// DrctDbtTx node
		$this->startElement('DrctDbtTx');
		$this->startElement('MndtRltdInf');
		$this->writeElement('MndtId', $installment->mandate_id);
		$this->writeElement('DtOfSgntr', $installment->mandate_date_signed->format('Y-m-d'));
		$this->endElement();
		$this->endElement();

		// DbtrAgt Node
		$this->startElement('DbtrAgt');
		$this->startElement('FinInstnId');
		$this->writeElement('BIC', $installment->bic);
		$this->endElement();
		$this->endElement();
		
		// Dbtr node
		$this->startElement('Dbtr');
		$this->writeElement('Nm', $installment->account_holder_name);
		$this->endElement();

		// DbtrAcct node
		$this->startElement('DbtrAcct');
		$this->startElement('Id');
		$this->writeElement('IBAN', $installment->iban);
		$this->endElement();
		$this->endElement();

		// RmtInf node
		$this->startElement('RmtInf');
		$this->writeElement('Ustrd', $installment->bank_statement_description);
		$this->endElement();

		$this->endElement();		
	}

	/**
	 * Write the data of the current buffer
	 * 
	 * @return [type] [description]
	 */
	public function writeBuffer()
	{
		Storage::disk(config('filesystems.default_disk'))->append($this->filename, $this->flush());
	}

	/**
	 * Ends the pain document
	 * 
	 * @return [type] [description]
	 */
	public function endPainDocument()
	{
		$this->endDocument();
	}

	/**
	 * End the current batch
	 * 
	 * @return [type] [description]
	 */
	public function endBatch()
	{
		$this->endElement();
	}

	/**
     * Create a random Message Id $PmtInfNodeor the header, prefixed with a timestamp.
     * @return the Message Id.
     */
    private function makeMsgId()
    {
        $random = mt_rand();
        $random = md5($random);
        $random = substr($random,0,12);
        $timestamp = date("dmYsi");

        return $timestamp."-".$random;
    }
    
    /**
     * Create a random id, combined with the name (truncated at 22 chars).
     * @return the Id.
     */
    private function makeId()
    {
        $random = mt_rand();
        $random = md5($random);
        $random = substr($random,0,12);
        $name = $this->config['name'];
        $length = strlen($name);
        
        if($length > 22){
            $name = substr($name,0,22);
        }

        return $name."-".$random;
    }

    /**
     * Check a payment for validity
     * 
     * @param $payment The payment array
     * @return TRUE if valid, error string if invalid.
     */
    private function validatePayment($payment)
    {
        $required =[
        	'account_holder_name',
            'iban',
            'amount',
            //'type',
            //'collection_date',
            'mandate_id',
            'mandate_date_signed',
            'bank_statement_description'
        ];

        $functions = [
        	'iban' => 'validateIBAN',
           	'bic' => 'validateBIC',
           	'amount' => 'validateAmount',
           	//'collection_date' => 'validateDate',
           	'mandate_date_signed' => 'validateMandateDate',
           	'type' => 'validateDDType',
           	'final_payment_reference' => 'validateEndToEndId'
        ];
        
        foreach ( $required as $requirement ) {
        	//Check if the config has the required parameter
            if ( array_key_exists($requirement,$payment) ) {
                //It exists, check if not empty
                if ( empty($payment[$requirement]) ){
                    return $requirement." is empty.";
                }
            }else{
                return $requirement." does not exist.";
            }
            
        }
        
        foreach ( $functions as $target => $function ){
            //Check if it is even there in the config
            if ( array_key_exists($target,$payment) ) {
                //Perform the RegEx
                $function_result = call_user_func("\StepOrange\SEPA\Validator::".$function,$payment[$target]);
                if ( $function_result === true ){
                    continue;
                }else{
                    return $target." does not validate: ".$function_result;
                }
            }  
            
        }
        
        return true;
    }
}