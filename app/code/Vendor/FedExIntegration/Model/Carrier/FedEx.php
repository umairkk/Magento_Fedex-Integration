<?php

declare(strict_types=1);

namespace Vendor\FedExIntegration\Model\Carrier;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory as RateErrorFactory;
use Magento\Shipping\Model\Carrier\AbstractCarrier;
use Magento\Shipping\Model\Carrier\CarrierInterface;
use Magento\Shipping\Model\Rate\ResultFactory as RateResultFactory;
use Magento\Quote\Model\Quote\Address\RateResult\MethodFactory;
use Magento\Shipping\Model\Tracking\Result\ErrorFactory as TrackingErrorFactory;
use Magento\Shipping\Model\Tracking\Result\StatusFactory as TrackingStatusFactory;
use Psr\Log\LoggerInterface;
use Vendor\FedExIntegration\Api\RateServiceInterface;
use Vendor\FedExIntegration\Api\TrackingServiceInterface;
use Vendor\FedExIntegration\Model\Api\FedExApiException;
use Vendor\FedExIntegration\Model\Config;

class FedEx extends AbstractCarrier implements CarrierInterface
{
    public const CODE = Config::CARRIER_CODE;

    /**
     * @var string
     */
    protected $_code = self::CODE;

    /**
     * @var bool
     */
    protected $_isFixed = false;

    public function __construct(
        ScopeConfigInterface $scopeConfig,
        RateErrorFactory $rateErrorFactory,
        LoggerInterface $logger,
        private readonly RateResultFactory $rateResultFactory,
        private readonly MethodFactory $rateMethodFactory,
        private readonly TrackingStatusFactory $trackingStatusFactory,
        private readonly TrackingErrorFactory $trackingErrorFactory,
        private readonly RateServiceInterface $rateService,
        private readonly TrackingServiceInterface $trackingService,
        private readonly Config $fedExConfig,
        array $data = []
    ) {
        parent::__construct($scopeConfig, $rateErrorFactory, $logger, $data);
    }

    public function collectRates(RateRequest $request)
    {
        if (!$this->getConfigFlag('active')) {
            return false;
        }

        $result = $this->rateResultFactory->create();

        try {
            foreach ($this->rateService->getRates($request) as $rate) {
                $method = $this->rateMethodFactory->create();
                $method->setCarrier($this->_code);
                $method->setCarrierTitle($this->fedExConfig->getCarrierTitle($request->getStoreId()));
                $method->setMethod($rate['method_code']);
                $method->setMethodTitle($rate['method_title']);
                $method->setPrice($rate['amount']);
                $method->setCost($rate['amount']);
                $result->append($method);
            }
        } catch (FedExApiException|LocalizedException $exception) {
            $this->_logger->warning('FedEx rate collection failed.', [
                'message' => $exception->getMessage(),
                'status' => $exception instanceof FedExApiException ? $exception->getStatusCode() : null,
            ]);
            $result->append($this->createRateError($request));
        } catch (\Throwable $exception) {
            $this->_logger->error('Unexpected FedEx rate collection failure.', [
                'message' => $exception->getMessage(),
            ]);
            $result->append($this->createRateError($request));
        }

        return $result;
    }

    /**
     * @return array<string, string>
     */
    public function getAllowedMethods(): array
    {
        $methods = [];
        foreach ($this->fedExConfig->getAllowedMethods() as $methodCode) {
            $methods[$methodCode] = $this->fedExConfig->getMethodLabel($methodCode);
        }

        return $methods;
    }

    public function isTrackingAvailable(): bool
    {
        return true;
    }

    public function getTrackingInfo($tracking)
    {
        try {
            $trackingData = $this->trackingService->track((string) $tracking);
            $status = $this->trackingStatusFactory->create();
            $status->setCarrier($this->_code);
            $status->setCarrierTitle($this->fedExConfig->getCarrierTitle());
            $status->setTracking((string) $tracking);
            $status->setStatus($trackingData['status_description'] ?: $trackingData['status']);
            $status->setTrackSummary($trackingData['status_description'] ?: $trackingData['status']);
            $status->setProgressdetail($trackingData['events']);

            return $status;
        } catch (\Throwable $exception) {
            $this->_logger->warning('FedEx tracking lookup failed.', [
                'tracking_number' => $tracking,
                'message' => $exception->getMessage(),
            ]);

            $error = $this->trackingErrorFactory->create();
            $error->setCarrier($this->_code);
            $error->setCarrierTitle($this->fedExConfig->getCarrierTitle());
            $error->setTracking((string) $tracking);
            $error->setErrorMessage(__('Tracking information is currently unavailable.'));

            return $error;
        }
    }

    private function createRateError(RateRequest $request)
    {
        $error = $this->_rateErrorFactory->create();
        $error->setCarrier($this->_code);
        $error->setCarrierTitle($this->fedExConfig->getCarrierTitle($request->getStoreId()));
        $error->setErrorMessage($this->fedExConfig->getSpecificErrorMessage($request->getStoreId()));

        return $error;
    }
}
