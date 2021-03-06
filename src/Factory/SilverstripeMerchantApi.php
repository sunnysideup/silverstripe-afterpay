<?php

namespace Sunnysideup\Afterpay\Factory;

use CultureKings\Afterpay\Exception\ApiException;
use CultureKings\Afterpay\Factory\MerchantApi;
// Models for Data //
use CultureKings\Afterpay\Factory\SerializerFactory;
use CultureKings\Afterpay\Model\Merchant\Authorization;
use CultureKings\Afterpay\Model\Merchant\Configuration;
use CultureKings\Afterpay\Model\Merchant\OrderDetails;
use CultureKings\Afterpay\Model\Merchant\OrderToken;
use CultureKings\Afterpay\Model\Merchant\Payment;
use CultureKings\Afterpay\Service\Merchant\Payments;
use GuzzleHttp\Client;
use SilverStripe\Control\Director;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Extensible;
use SilverStripe\Core\Injector\Injectable;
use SilverStripe\ORM\FieldType\DBCurrency;
use SilverStripe\ORM\FieldType\DBField;
use Sunnysideup\Ecommerce\Api\ShoppingCart;
use Sunnysideup\Ecommerce\Model\Order;

/**
 * An API which handles the main steps needed for a website to function with afterpay.
 */
class SilverstripeMerchantApi
{
    use Configurable;
    use Extensible;
    use Injectable;

    //###########################
    // global settings
    //###########################

    /**
     * @var string
     */
    private const CONNECTION_URL_TEST = 'https://api-sandbox.afterpay.com/v1/';

    /**
     * @var string
     */
    private const CONNECTION_URL_LIVE = 'https://api.afterpay.com/v1/';

    //###########################
    // internal variables
    //###########################
    protected $authorization;

    protected $client;

    /**
     * Configuration information.
     *
     * @var Configuration[]
     */
    protected $configurationInfo = [];

    /**
     * Order Token.
     *
     * @var OrderToken
     */
    protected $orderToken;

    /**
     * Payment information.
     *
     * @var Payment
     */
    protected $paymentInfo;

    //###########################
    // instance
    //###########################

    /**
     * this.
     *
     * @var SilverstripeMerchantApi
     */
    protected static $singleton_cache;

    private static $merchant_id = 0;

    private static $secret_key = '';

    private static $number_of_payments = 4;

    private static $merchant_name = '';

    /**
     * see: afterpay/expectations as an example.
     *
     * @var string
     */
    private static $expectations_folder = 'vendor/sunnysideup/expectations';

    //###########################
    // global instance settings
    //###########################

    /**
     * @var float
     */
    private $minPrice = 0;

    /**
     * @var float
     */
    private $maxPrice = 0;

    /**
     * @var bool
     */
    private $isTest = false;

    /**
     * @var bool
     */
    private $isServerAvailable = false;

    public function __construct(string $initMethod = 'instance')
    {
        if ('singleton' !== $initMethod) {
            user_error('Please use the inst() static method to create me!');
        }
    }

    /**
     * Singleton instance pattern.
     */
    public static function inst(): self
    {
        if (null === self::$singleton_cache) {
            self::$singleton_cache = new self('singleton');
        }
        self::$singleton_cache->isTest = (! Director::isLive());
        self::$singleton_cache->setupAuthorization();
        self::$singleton_cache->setupGuzzleClient();

        return self::$singleton_cache;
    }

    //###########################
    // setters
    //###########################

    /**
     * Setter for is server available
     * If no server exists then collect fake responses from a cache.
     *
     * @param bool $available Are there any external APIs available
     *
     * @return self Daisy chain
     */
    public function setIsServerAvailable(bool $available): self
    {
        $this->isServerAvailable = $available;
        if ($available) {
            $tests = [
                'merchant_id',
                'secret_key',
            ];
            foreach ($tests as $name) {
                if (empty($this->Config()->get($name))) {
                    user_error($name . ' not set for afterpay - but is required to show afterpay');
                }
            }
        }

        return $this;
    }

    /**
     * set the minimum and maximum price to use Afterpay
     * This can overrule settings from Afterpay server
     * and therefore make it faster ...
     */
    public function setMinAndMaxPrice(float $minPrice, float $maxPrice): self
    {
        $this->minPrice = $minPrice;
        $this->maxPrice = $maxPrice;

        return $this;
    }

    //###########################
    // getters
    //###########################

    /**
     * Getter for is server available.
     *
     * @return bool Are any servers available? Otherwise use cache
     */
    public function getIsServerAvailable(): bool
    {
        return $this->isServerAvailable;
    }

    /**
     * Can the payment be processed (in range of the max and min price).
     *
     * @param float $price Price of product
     *
     * @return bool Can the payment be processed (true / false)
     */
    public function canProcessPayment($price): bool
    {
        $price = floatval($price);
        if ($price) {
            if ($this->getIsServerAvailable()) {
                if (empty($this->minPrice) || empty($this->maxPrice)) {
                    $this->retrieveMinAndMaxFromConfig();
                }
                if (empty($this->minPrice) || empty($this->maxPrice)) {
                    return false;
                }
                if ($price >= $this->minPrice && $price <= $this->maxPrice) {
                    return true;
                }
            }
        }

        return false;
    }

    public function getNumberOfPayments(): int
    {
        return $this->Config()->get('number_of_payments');
    }

    /**
     * @param Order $order optional
     *
     * @return DBCurrency
     */
    public function getAmountPerPaymentForCurrentOrder(?Order $order = null)
    {
        if (! $order) {
            $order = ShoppingCart::current_order();
        }
        $amountPerPayment = 0;
        if ($order) {
            $totalAmount = $order->Total();
            if ($totalAmount) {
                $amountPerPayment = $this->getAmountPerPayment(floatval($totalAmount));
            }
        }

        return DBField::create_field('Currency', $amountPerPayment);
    }

    /**
     * Get the payment installations for afterpay (return 0 if price is out of range).
     *
     * @param float $price Price of the product
     *
     * @return float (Price / 4) or 0 if fail
     */
    public function getAmountPerPayment($price): float
    {
        $price = floatval($price);
        if ($this->canProcessPayment($price)) {
            $numberOfPayments = $this->getNumberOfPayments();
            if ($numberOfPayments) {
                //make cents into dollars
                $amountPerPayment = $price * 100;
                //divide by four
                $amountPerPayment /= $this->getNumberOfPayments();
                //round up anything beyond cents
                $amountPerPayment = ceil($amountPerPayment);
                //bring back to cents
                return $amountPerPayment / 100;
            }
        }
        // user_error('This amount can not be processed', E_USER_NOTICE);

        return 0;
    }

    /**
     * Pass an OrderDetails object to this function and collect the OrderToken from afterpay
     * if succesful. This order helps afterpay assess the preapproval
     * https://github.com/culturekings/afterpay/blob/master/docs/merchant/api.md#create-order
     * https://docs.afterpay.com/nz-online-api-v1.html#orders.
     *
     * @param OrderDetails $order An object holding all the information for the request
     *
     * @return ApiException|OrderToken The token for the preapproval process
     */
    public function createOrder(OrderDetails $order)
    {
        if ($this->isServerAvailable) {
            try {
                $this->orderToken = MerchantApi::orders(
                    $this->authorization,
                    $this->client
                )->create($order);
            } catch (ApiException $apiException) {
                return $apiException;
            }
        } else {
            $this->orderToken = $this->localExpecationFileToClass(
                'order_create_response.json',
                OrderToken::class
            );
        }

        return $this->orderToken;
    }

    /**
     * Capture the payment after the order has been placed.
     *
     * @param string $merchantReference Optional: Update the merchant reference
     *
     * @return ApiException|Payments
     */
    public function createPayment(string $orderTokenAsString = '', string $merchantReference = '')
    {
        if ($this->isServerAvailable) {
            if (! $orderTokenAsString) {
                if (null !== $this->orderToken) {
                    $orderTokenAsString = $this->orderToken->getToken();
                }
            }
            if ($orderTokenAsString) {
                try {
                    $this->paymentInfo = MerchantApi::payments(
                        $this->authorization,
                        $this->client
                    )->capture(
                        $orderTokenAsString,
                        $merchantReference
                    );
                } catch (ApiException $e) {
                    return $e;
                }
            } else {
                user_error('No order token found, please create an order before processing a payment');
            }
        } else {
            $this->paymentInfo = $this->localExpecationFileToClass(
                'payments_get_response.json',
                Payment::class
            );
        }

        return $this->paymentInfo;
    }

    //###########################
    // internal do-ers
    //###########################

    /**
     * Initialize the authorization field with the set merchant id and secret key.
     */
    protected function ping_end_point(bool $pingAgain = false): bool
    {
        if (null === $this->isServerAvailable || $pingAgain) {
            $answer = MerchantApi::ping($this->getConnectionURL(), $this->client);
            $this->isServerAvailable = (bool) $answer;
        }

        return $this->isServerAvailable;
    }

    /**
     * Initialize the authorization field with the set merchant id and secret key.
     */
    protected function setupAuthorization(bool $setupAgain = false): Authorization
    {
        if (null === $this->authorization || $setupAgain) {
            $this->authorization = new Authorization(
                $this->getConnectionURL(),
                $this->Config()->get('merchant_id'),
                $this->Config()->get('secret_key')
            );
        }

        return $this->authorization;
    }

    protected function setupGuzzleClient(bool $setupAgain = false)
    {
        //we need to make sure authorization is set up.
        $this->setupAuthorization();
        if (null === $this->client || $setupAgain) {
            $this->client = new Client(
                [
                    'base_uri' => $this->authorization->getEndpoint(),
                    'headers' => [
                        'User-Agent' => $this->getUserAgentString(),
                    ],
                ]
            );
        }

        return $this->client;
    }

    protected function getUserAgentString(): string
    {
        return 'AfterpayModule/ 1.0 (Silverstripe/ 3 ; ' .
            $this->Config()->get('merchant_name') . '/ ' .
            $this->Config()->get('merchant_id') . ' ) ' .
            Director::absoluteBaseURL() . '/';
    }

    /**
     * Initialize the API with the configuration data from afterpay
     * Currently only the PAY_BY_INSTALLMENT configuration is collected**maybe.
     *
     * @return null|Configuration
     */
    protected function retrieveConfig(bool $getConfigAgain = false)
    {
        if ([] === $this->configurationInfo || $getConfigAgain) {
            if ($this->findExpectationFile('configuration_details.json')) {
                //look for local config details (FASTER)
                $this->configurationInfo = $this->localExpecationFileToClass(
                    'configuration_details.json',
                    sprintf('array<%s>', Configuration::class)
                );
            } elseif ($this->isServerAvailable) {
                try {
                    $this->configurationInfo = MerchantApi::configuration(
                        $this->authorization,
                        $this->client
                    )->get();
                } catch (ApiException $e) {
                    return null;
                }
            }
        }

        return $this->configurationInfo;
    }

    protected function retrieveMinAndMaxFromConfig()
    {
        $this->retrieveConfig();
        if ($this->configurationInfo) {
            foreach ($this->configurationInfo as $config) {
                if ('PAY_BY_INSTALLMENT' === $config->getType()) {
                    $this->minPrice = $config->getMaximumAmount()->getAmount();
                    $this->maxPrice = $config->getMinimumAmount()->getAmount();
                }
            }
        }
    }

    protected function getConnectionURL(): string
    {
        return $this->isTest ? $this::CONNECTION_URL_TEST : $this::CONNECTION_URL_LIVE;
    }

    //#######################################
    // helpers
    //#######################################

    protected function findExpectationFile(string $relativeFileName): string
    {
        if ($relativeFileName) {
            $folder = $this->Config()->get('expectations_folder');
            $absoluteFileName = Director::baseFolder() . '/' . $folder . '/' . $relativeFileName;
            if (file_exists($absoluteFileName)) {
                return $absoluteFileName;
            }
            user_error('bad file specified: ' . $absoluteFileName);
        }

        return '';
    }

    protected function localExpecationFileToClass(string $fileName, string $className)
    {
        $absoluteFileName = $this->findExpectationFile($fileName);
        if ($absoluteFileName) {
            $json = file_get_contents($absoluteFileName);
            if ($json) {
                return SerializerFactory::getSerializer()->deserialize(
                    (string) $json,
                    $className,
                    'json'
                );
            }
        }
        user_error('Could not create expectation file.');

        return new $className();
    }
}
