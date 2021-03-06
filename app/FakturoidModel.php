<?php
/**
 * FakturoidPairing.
 *
 * @author Michal Wiglasz <michal.wiglasz@gmail.com>
 * @copyright Copyright (c) 2010 Michal Wiglasz
 */


/**
 * Provides Fakturoid data.
 * 
 * Based on FakturoidCalc by Jan Javorek <honza@javorek.net>
 *
 * @author Michal Wiglasz <michal.wiglasz@gmail.com>
 * 
 */
class FakturoidModel
{
	/**
	 * @var string
	 */
	protected $username;

	/**
	 * @var string
	 */
	protected $apiKey;

	/**
	 * @var DOMXPath[]
	 */
	private $fileCache = array();

	/**
	 * @param string $username
	 * @param string $apiKey
	 */
	public function __construct($username, $apiKey)
	{
		$this->username = $username;
		$this->apiKey = $apiKey;
	}

	/**
	 * Remote XML XPath provider.
	 *
	 * @param $fileName
	 * @return DOMXPath
	 */
	protected function getFile($fileName)
	{
		if (!empty($this->fileCache[$fileName])) {
			return $this->fileCache[$fileName];
		}
		$xml = $this->fetch($fileName);

		$doc = new DOMDocument();
		$doc->loadXML($xml);
		$this->fileCache[$fileName] = (object)array(
			'doc' => $doc,
			'xpath' => new DOMXPath($doc),
		);
		return $this->fileCache[$fileName];
	}

        /**
         * Prepares cURL session
         *
         * Uses HTTPS authorization and certificate check. See these tutorials:
	 *  - http://www.electrictoolbox.com/php-curl-sending-username-password/
	 *  - http://unitstep.net/blog/2009/05/05/using-curl-in-php-to-access-https-ssltls-protected-sites/
	 *  - http://www.php.net/manual/en/function.curl-error.php#87212
	 *
         *
         * @param string $fileName to open
         * @return resource cURL session
         */
        private function setupCurl($fileName)
        {
            $username = $this->username;
            $apiKey = $this->apiKey;

            if (!$username || !$apiKey) {
                    throw new Exception('Chybí uživatelské jméno nebo API klíč.');
            }

            $c = curl_init();
            curl_setopt_array($c, array(
                CURLOPT_URL => "https://$username.fakturoid.cz/$fileName", // url
                CURLOPT_FAILONERROR => TRUE, // HTTP errors

                CURLOPT_USERPWD => "vera.pohlova:$apiKey", // auth
                CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
                CURLOPT_SSL_VERIFYPEER => TRUE, // HTTPS, certificate
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_CAINFO => dirname(__FILE__) . '/cacert.pem', // downloaded from http://curl.haxx.se/docs/caextract.html
            ));

            return $c;
        }

	/**
	 * Fetches wanted file from server.
	 *
	 * @param string $file
	 * @return string Response, should be XML.
	 */
	private function fetch($fileName)
	{
		$error = NULL;

		$c = $this->setupCurl($fileName);
                curl_setopt($c, CURLOPT_RETURNTRANSFER, TRUE); // return response
		$response = curl_exec($c);
		if ($response === FALSE) {
			$error = curl_error($c);
		}
		curl_close($c);
		if ($error) {
			throw new Exception($error);
		}
		return $response;
	}

        /**
	 * Fires an event on an invoice
	 *
	 * @param int $invoiceId
	 * @return string eventy name, @see https://github.com/fakturoid/fakturoid_api/wiki/Invoice
	 */
	private function fireInvoice($invoiceId, $event)
	{
		$error = NULL;

		$c = $this->setupCurl('invoices/' . intval($invoiceId) . '/fire?event=' . urlencode($event));
                curl_setopt($c, CURLOPT_POST, TRUE);
                curl_setopt($c, CURLOPT_HTTPHEADER, array(
                  'Accept: application/xml',
                  'Content-Type: application/xml',
                ));
                
		$response = curl_exec($c);
		if ($response === FALSE) {
			$error = curl_error($c);
		}
		curl_close($c);
		if ($error) {
			throw new Exception($error);
		}
		return $response;
	}

	/**
	 * Fetches all unpaid invoices.
	 *
	 * @return array
	 */
	public function getUnpaidInvoices()
	{
		$list = array();
		$page = 1;
		while($this->getFile("invoices.xml?page=$page")->xpath->evaluate("count(//invoice)")) {
			$nodes = $this->getFile("invoices.xml?page=$page")->xpath->evaluate("//invoice[status!='paid']");
                        foreach($nodes as $n)
                        {
                            $inv = $this->DOMElementToStdClass($n);
                            $list[$inv->id] = $inv;
                        }
			$page++;
		}
		return $list;
	}

        public function markAsPaid($invoiceId)
        {
            $invoiceId = intval($invoiceId);
            if(!$invoiceId)
                throw new Exception('Neplatné ID faktury.');
                
            $result = $this->fireInvoice($invoiceId, 'pay');
            
            return (bool)$result;
        }

        /**
         * Converts DOMElement into STDClass
         *
         * @param DOMNode $el
         * @return STDClass
         */
        private function DOMElementToStdClass(DOMNode $el)
        {
            $obj = new StdClass;

            if($el instanceof DOMText)
            {
                return mb_trim($el->textContent);
            }

            if(($el->firstChild === $el->lastChild) && (($child = $el->childNodes->item(0)) instanceof DOMText))
            {
                return mb_trim($el->childNodes->item(0)->textContent);
            }

            $hasChildren = FALSE;
            foreach($el->childNodes as $child)
            {
                $hasChildren = TRUE;
                $contents = $this->DOMElementToStdClass($child);
                if($child instanceof DOMText && $contents === '') continue;
                
                if(isset($obj->{$child->nodeName}))
                {
                    if(is_array($obj->{$child->nodeName}))
                    {
                        $obj->{$child->nodeName}[] = $contents;
                    }
                    else
                    {
                        $obj->{$child->nodeName} = array($obj->{$child->nodeName}, $contents);
                    }
                }
                else
                {
                    $obj->{$child->nodeName} = $contents;
                }
            }

            return $hasChildren ? $obj : NULL;
        }
}
