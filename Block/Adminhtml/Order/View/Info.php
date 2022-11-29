<?php

namespace ONVO\Pay\Block\Adminhtml\Order\View;

use Magento\Backend\Block\Template\Context;
use Magento\Backend\Block\Widget;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\Registry;
use Magento\Sales\Model\Order;

class Info extends Widget
{
    /**
     * Core registry
     *
     * @var Registry
     */
    protected $_coreRegistry = null;

    /**
     * @param Context $context
     * @param Registry $coreRegistry
     * @param array $data
     */
    public function __construct(
        Context $context,
        Registry $coreRegistry,
        array $data = []
    ) {
        $this->_coreRegistry = $coreRegistry;
        parent::__construct($context, $data);
    }

    /**
     * Retrieve order model instance
     *
     * @return Order
     */
    public function getOrder()
    {
        return $this->_coreRegistry->registry('current_order');
    }
}
