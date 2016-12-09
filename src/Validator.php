<?php

namespace StepOrange\SEPA;

use App\ProcessingHub;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;

/**
 * Class Schema
 *
 */
class Validator
{
    /**
     * Function to convert an amount in cents to a decimal (with point).
     * 
     * @param $int The amount as decimal string
     * 
     * @return The decimal
     */
    public static function intToDecimal($int)
    {
        $int = str_replace(".","",$int); //For cases where the int is already an decimal.
        $before = substr($int, 0, -2);
        $after = substr($int, -2);
        if( empty($before) ){
            $before = 0;
        }

        if( strlen($after) == 1 ){
            $after = "0".$after;
        }

        return $before.".".$after;
    }

    /**
     * Validate an IBAN
     * 
     * @param  [type] $value [description]
     * @return [type]       [description]
     */
    public static function validateIBAN($value)
    {
        return ProcessingHub::validateIBAN($value); 
    }

    /**
     * Validate an EndToEndId.
     * @param $EndToEndId the EndToEndId to check.
     * @return BOOLEAN TRUE if valid, error string if invalid.
     */
    public static function validateEndToEndId($EndToEndId)
    {
        $ascii = mb_check_encoding($EndToEndId,'ASCII');
        $len = strlen($EndToEndId);
        if ( $ascii && $len < 36 ) {
            return True;
        }elseif( !$ascii ){
            return $EndToEndId." is not ASCII";
        }else{
            return $EndToEndId." is longer than 35 characters";        
        }
    }
     
    /**
     * Validate a BIC number.Payment Information 
     * @param $BIC the BIC number to check.
     * @return TRUE if valid, FALSE if invalid.
     */
    public static function validateBIC($BIC)
    {
        $result = preg_match("([a-zA-Z]{4}[a-zA-Z]{2}[a-zA-Z0-9]{2}([a-zA-Z0-9]{3})?)",$BIC);
        
        if ( $result > 0 && $result !== false){
            return true;
        }else{
            return false;
        }
    }
    
    /**
     * Function to validate a ISO date.
     * @param $date The date to validate.
     * @return True if valid, error string if invalid.
     */
    public static function validateDate($date)
    {
        $result = \DateTime::createFromFormat("Y-m-d",$date);
        if($result === false){
            return $date." is not a valid ISO Date";
        }
        
        return true;
    }
    
    /**
     * Function to validate a ISO date.
     * @param $date The date to validate.
     * @return True if valid, error string if invalid.
     */
    public static function validateMandateDate($date)
    {
        if($date === false){
            return $date." is not a valid ISO Date";
        }
        
        /**$timeStamp = $result->getTimestamp();
        $beginOfToday = strtotime(date("Y-m-d") . " 00:00");
        
        if ($timeStamp > $beginOfToday) {
            return "mandate_date " . $date . " must be at least 1 day earlier then current day " . date("Y-m-d");
        }**/
        
        return true;
    }
    
    /**
     * Function to validate the Direct Debit Transaction types
     * @param Typecode
     * @return True if valid, error string if invalid.
     */
    public static function validateDDType($type)
    {
        $types = array("FRST",
                       "RCUR",
                       "FNAL",
                       "OOFF");
        if(in_array($type,$types)){
            return true;
        }else{
            return $type." is not a valid Sepa Direct Debit Transaction Type.";
        }
    }

    /**
     * Function to validate an amount, to check that amount is in cents.
     * @param $amount The amount to validate.
     * @return TRUE if valid, FALSE if invalid.
     */
    public static function validateAmount($amount)
    {
        return ctype_digit(strval($amount->getAmount()));
    }
}
