<?php

namespace StepOrange\SEPA;

use App\ProcessingHub;
use StepOrange\SEPA\PainStreamGenerator as Generator;

class PainManager
{
	/**
	 * The IBANs we need to fire the generator for.
	 * 
	 * @var array
	 */
	protected $banks;

	/**
	 * The Payment schedule the process runs for.
	 * 
	 * @var [type]
	 */
	protected $paymentSchedule;

	/**
	 * Generator class
	 * 
	 * @var [type]
	 */
	protected $fileGenerator;

	/**
	 * Set the banks we need to generate for.
	 * 
	 * @param array $banks [description]
	 */
	public function setBanks($banks)
	{
		$this->banks = $banks;	
	}

	/**
	 * Sets the Payment Schedule.
	 * 
	 * @param [type] $paymentSchedule [description]
	 */
	public function setPaymentSchedule($paymentSchedule)
	{
		$this->paymentSchedule = $paymentSchedule;	
	}

	/**
	 * Gets the payment schedule.
	 * 
	 * @return [type] [description]
	 */
	public function getPaymentSchedule()
	{
		return $this->paymentSchedule;	
	}

	/**
	 * Gets the ibans
	 * 
	 * @return [type] [description]
	 */
	public function getBanks()
	{
		return $this->banks;
	}

	/**
	 * Sets the Generator class.
	 * 
	 * @param [type] $class [description]
	 */
	public function setFileGenerator($class)
	{
		$this->fileGenerator = $class;
	}

	/**
	 * Dispatch the job onto the Generator
	 * 
	 * @return [type] [description]
	 */
	public function dispatch()
	{
		$fileGenerator = $this->fileGenerator;

		// Generate the PAIN file
        collect($this->getBanks())->each(function($bank) use ($fileGenerator) {
        	$fileGenerator = new $fileGenerator;
        	$fileGenerator->setConfig([
	            'name' => ProcessingHub::getUser()->account->name,
	            'IBAN' => getBankConfigValue('IBAN', $bank)->value,
	            'BIC' => getBankConfigValue('BIC', $bank)->value,
	            'creditor_id' => getBankConfigValue('CREDITOR_ID', $bank)->value,
	            'currency' => getBankConfigValue('CURRENCY', $bank)->value
	        ]);

        	$generatorService = resolve(Generator::class);
            $generatorService->setGeneratorType($fileGenerator);
            $generatorService->setBank($bank);            
            $generatorService->setPaymentSchedule($this->getPaymentSchedule());
            $generatorService->generate();
        });
	}
}