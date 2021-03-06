<?php
/**
 * FakturoidPairing.
 *
 * @author Michal Wiglasz <michal.wiglasz@gmail.com>
 * @copyright Copyright (c) 2010 Michal Wiglasz
 */

/**
 * Provides access to bank statement via emails sent by CSOB <www.csob.cz>
 *
 * @author Michal Wiglasz <michal.wiglasz@gmail.com>
 */
class CsobEmailStatement extends EmailStatement {

    const REGEX = '#dne\s+([0-9]{1,2})\.([0-9]{1,2})\.(2[0-9]{3}).+(.|\n)+částka ([+-]?[0-9]+(,[0-9]+)?)(.|\n)+VS\s+([0-9]+)#ui';
    const PAYMENT_DELIMETER = 'Zůstatek na účtu po zaúčtování transakce';

    /**
     * Checks email for payment
     *
     * @param int $msgno Message number
     * @param array $headers Email headers
     * @return array List of payments found in this email
     */
    function processEmail($msgno, $headers)
    {
        $from = $headers->from[0];

        if($from->mailbox != 'administrator' || $from->host != 'tbs.csob.cz')
                return NULL;

        $body = $this->fetchBody($msgno);
        
        $parts = explode(self::PAYMENT_DELIMETER, $body);

        $payments = array();
        
        foreach($parts as $p)
        {
            if(preg_match(self::REGEX, $p, $m))
            {
                $amount = floatval(str_replace(',', '.', $m[5]));
                if($amount > 0)
                {
                    $payments[] = (object)array(
                        'variable-symbol' => intval($m[8]),
                        'date' => mktime(0,0,0, $m[2], $m[1], $m[3]),
                        'amount' => $amount,
                    );
                }
            }
        }
        
        return count($payments) ? $payments : NULL;
    }
}


