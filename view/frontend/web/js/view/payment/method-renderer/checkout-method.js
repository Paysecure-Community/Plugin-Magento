// jscs:disable requireCamelCaseOrUpperCaseIdentifiers
define(
    [
        'jquery',
        'Magento_Checkout/js/model/quote',
        'Magento_Checkout/js/model/url-builder',
        'mage/storage',
        'Magento_Customer/js/customer-data',
        'Magento_Checkout/js/view/payment/default',
        'Magento_Checkout/js/action/place-order',
        'Magento_Checkout/js/action/select-payment-method',
        'Magento_Customer/js/model/customer',
        'Magento_Checkout/js/checkout-data',
        'Magento_Checkout/js/model/payment/additional-validators',
        'mage/url'
    ],
    function (
        $,
        quote,
        urlBuilder,
        storage,
        customerData,
        Component,
        placeOrderAction,
        selectPaymentMethodAction,
        customer,
        checkoutData,
        additionalValidators,
        url
    ) {
        'use strict';

        return Component.extend({
            defaults: {
                template: 'PaySecure_Payments/payment/method/checkout/form'
            },
            configData: (window.checkoutConfig || {}).paysecure || {},

            /**
             * Initialize
             */
            initialize: function () {
                this._super();
                const detected_country = (quote.shippingAddress() || {}).countryId || null;

                window.paysecure_preferredCountryCode = null;
                window.paysecure_preferredCountryCode = this.getSelectedCountry(
                    detected_country, this.configData.country_options || []
                );
            },

            /**
             * Fill payment methods template
             */
            paysecure_fillPaymentMethodTemplate: function () {
                let paysecureCountryInput = document.getElementById('paysecure-country');
                const paysecureFilterPaymentMethods = () => {// jscs:ignore jsDoc
                    let selected = paysecureCountryInput.value,
                        els = document.getElementsByClassName('paysecure-payment-method'),
                        first = true;

                    for (let i = 0; i < els.length; i++) {
                        let el = els[i],
                            countries = JSON
                            .parse(el.getAttribute('data-countries')),
                            includes = false;

                        check_includes:
                            for (let j = 0; j < countries.length; j++) {
                            // eslint-disable-next-line max-depth
                            switch (countries[j]) {
                                case selected:
                                case 'any':
                                    includes = true;
                                break check_includes;
                            }
                        }

                        el.parentElement.hidden = !includes;
                        el.checked = false;

                        if (includes && first) {
                            first = false;
                            el.checked = true;
                        }
                    }
                };

                if (paysecureCountryInput) {
                    let selected = window.paysecure_preferredCountryCode;

                    if (selected) {
                        paysecureCountryInput.value = selected;
                    }
                    paysecureCountryInput.addEventListener('change', paysecureFilterPaymentMethods);
                    paysecureFilterPaymentMethods(paysecureCountryInput);
                }
            },

            /**
             * Get selected country
             *
             * @param {*} detected_country
             * @param {*} country_options
             * @returns {String}
             */
            getSelectedCountry: function (detected_country, country_options) {
                let selected_country = '';
                const any_index = country_options.indexOf('any');

                if (country_options.indexOf(detected_country) > -1) {
                    selected_country = detected_country;
                } else if (any_index > -1) {
                    selected_country = 'any';
                } else if (country_options.length > 0) {
                    selected_country = country_options[0];
                }

                return selected_country;
            },

            /**
             * Place order
             *
             * @param {*} data
             * @param {*} event
             * @returns {Boolean}
             */
            placeOrder: function (data, event) {
                if (event) {
                    event.preventDefault();
                }
                let self = this,
                    placeOrder;

                if (this.validate() && additionalValidators.validate()) {
                    this.isPlaceOrderActionAllowed(false);

                    const paymentData = this.getData(),
                          selector = 'input[name="paysecure_payment_method"]:checked',
                          json = JSON.stringify({
                              paysecure_payment_method: [...document.querySelectorAll(selector)]
                                .map(inp => inp.value)[0]// jscs:ignore jsDoc
                    });

                    fetch('/paysecure/checkout/setFormData?json=' + encodeURIComponent(json), {
                        credentials: 'include'
                    }).then(() => {// jscs:ignore jsDoc
                        placeOrder = placeOrderAction(paymentData, false, this.messageContainer);
                        $.when(placeOrder).fail(function () {
                            self.isPlaceOrderActionAllowed(true);
                        }).done(this.afterPlaceOrder.bind(this));
                    });

                    return true;
                }
                // eslint-disable-next-line no-alert
                alert('Validation did not pass, possibly email or sign-in is mandatory');

                return false;
            },

            /**
             * Select payment method
             *
             * @returns {Boolean}
             */
            selectPaymentMethod: function () {
                selectPaymentMethodAction(this.getData());
                checkoutData.setSelectedPaymentMethod(this.item.method);

                return true;
            },

            /**
             * Redirect after place order
             */
            afterPlaceOrder: function () {
                const backUrl = url.build('paysecure/checkout/redirect/action/back');

                window.history.pushState({
                    url: backUrl
                }, document.title, backUrl);

                $.mage.redirect(
                    url.build('paysecure/checkout/index')
                );
            },

            /**
             * Get data for template
             *
             * @returns {*|{}}
             */
            tpl_getData: function () {
                return this.configData;
            },

            /**
             * Get countries for template
             * @returns {*}
             */
            tpl_getCountries: function () {
                return this.configData.country_options.map(value => ({// jscs:ignore jsDoc
                    value, name: this.configData.payment_methods_api_data.country_names[value]
                }));
            },

            /**
             * Get payment methods for template
             *
             * @returns {*}
             */
            tpl_getPaymentMethods: function tpl_getPaymentMethods() {
                // eslint-disable-next-line max-len
                return Object.values(this.configData.by_method).filter(pm => typeof pm !== 'function').map(pm => {// jscs:ignore jsDoc
                    const logo = this.configData.payment_methods_api_data.logos[pm.payment_method];
                    let logoPaths = typeof logo !== 'string' ?
                        // eslint-disable-next-line no-shadow
                        Object.values(logo).filter(logo => typeof logo === 'string') : [logo],// jscs:ignore jsDoc
                        logoUrls = logoPaths.map(path => 'https://gate.savannah.tech' + path);// jscs:ignore jsDoc

                    return {
                        payment_method: pm.payment_method,
                        countries_json: JSON.stringify(pm.countries),
                        logo_urls: logoUrls,
                        single_logo: logoUrls.length > 1 ? null : logoUrls[0],
                        name: this.configData.payment_methods_api_data.names[pm.payment_method]
                    };
                });
            }
        });
    }
);
