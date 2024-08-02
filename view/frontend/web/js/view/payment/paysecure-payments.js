define(
    [
        'uiComponent',
        'Magento_Checkout/js/model/payment/renderer-list'
    ],
    function (
        Component,
        rendererList
    ) {
        'use strict';

        rendererList.push(
            {
                type: 'paysecure',
                component: 'PaySecure_Payments/js/view/payment/method-renderer/checkout-method'
            }
        );

        /** Add view logic here if needed */
        return Component.extend({});
    }
);
