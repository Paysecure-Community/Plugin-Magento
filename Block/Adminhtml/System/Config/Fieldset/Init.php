<?php

declare(strict_types=1);

namespace PaySecure\Payments\Block\Adminhtml\System\Config\Fieldset;

use Magento\Backend\Block\Template;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\Data\Form\Element\Renderer\RendererInterface;

class Init extends Template implements RendererInterface
{
    /**
     * @var string
     */
    protected $_template = 'PaySecure_Payments::system/config/fieldset/init.phtml';

    /**
     * Render fieldset html
     *
     * @param AbstractElement $element
     * @return string
     */
    public function render(AbstractElement $element)
    {
        return '';
    }
}
