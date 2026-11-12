<?php
    /*
     * @copyright Copyright (c) DNS Africa 2023
     * @author  David Peall <david@dns.business>
     */
    namespace WHMCS\Module\Registrar\gateway_srs;

    use Throwable;

    class RestAPIException extends \Exception
    {
        /** @var array|null stores the output array from the API even though a error is retuned there can be helpful
         * information int the contents.
         */
        protected $api_output;

        public function __construct($message, $code = 0, ?array $output = null, ?Throwable $previous = null)
        {
            /** @var output set from constructor values*/
            $this->api_output = $output;

            /** call the parent constructor */
            parent::__construct($message, (int) $code, $previous);
        }

        /** @var bool the request may have reached the registry but no response arrived (timeout, dropped connection) */
        private bool $noResponse = false;

        public static function noResponse(string $message): self
        {
            $e = new self($message);
            $e->noResponse = true;
            return $e;
        }

        /**
         * True when the registry may have carried out the request although it failed here: no response arrived, or
         * the server failed (HTTP 5xx, which registry-api returns for EPP 2400/2500 "Command failed").
         */
        public function outcomeUnknown(): bool
        {
            return $this->noResponse || $this->getCode() >= 500;
        }

        /** registry-api's server transaction ID for this failure, when it sent a well-formed one. */
        public function svtrid(): ?string
        {
            return self::transactionId($this->api_output ?? []);
        }

        public static function transactionId(array $output): ?string
        {
            $id = $output['svtrid'] ?? null;
            return is_string($id) && preg_match('/^[A-Za-z0-9._:-]{1,64}$/D', $id) ? $id : null;
        }

        /**
         * Return the contents of the api output
         * @return array|null
         */
        public function get_api_output(): ?array
        {
            return $this->api_output;
        }
    }
