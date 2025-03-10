<?php

namespace PayEx\Api\Service\Transaction\Resource\Response\Data;

use PayEx\Api\Service\Resource\Data\ResponseInterface;

/**
 * Transaction reversal response data interface
 *
 * @api
 */
interface TransactionReversalInterface extends ResponseInterface
{
    const PAYMENT = 'payment';
    const REVERSAL = 'reversal';

    /**
     * @return string
     */
    public function getPayment();

    /**
     * @param string $payment
     * @return $this
     */
    public function setPayment($payment);

    /**
     * @return ReversalInterface
     */
    public function getReversal();

    /**
     * @param ReversalInterface $reversal
     * @return $this
     */
    public function setReversal($reversal);
}
